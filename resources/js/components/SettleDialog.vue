<template>
  <q-dialog v-model="open" persistent>
    <q-card flat class="card-form-dialog">
      <q-card-section>
        <div class="row justify-between items-center">
          <div class="text-h6 text-grey-9 text-weight-bold">Settle statement</div>
          <q-btn flat round color="grey-6" icon="close" @click="open = false" />
        </div>
      </q-card-section>

      <q-separator inset />

      <q-card-section v-if="period" class="q-gutter-sm">
        <div class="row items-center no-wrap">
          <q-icon name="credit_card" size="md" color="grey-6" class="q-mr-md" />
          <div>
            <div class="text-subtitle1 text-weight-medium">{{ group.card.name }}</div>
            <div class="text-caption text-grey-7">
              Statement due {{ formatDate(period.due_date) }}
            </div>
          </div>
          <q-space />
          <div class="text-right">
            <div class="text-caption text-grey-7">Owes</div>
            <div class="text-h5 text-weight-bold text-negative">
              {{ money(period.owed) }}
              <span class="text-subtitle2 text-grey-7">{{ group.card.ccy }}</span>
            </div>
          </div>
        </div>

        <div class="row q-col-gutter-sm q-mb-sm">
          <div v-for="tile in tiles" :key="tile.label" class="col-6">
            <div class="bg-grey-2 rounded-borders q-pa-sm">
              <div class="text-caption text-grey-7">{{ tile.label }}</div>
              <div class="text-body1 text-weight-medium">
                {{ money(tile.amount) }}
                <span class="text-caption text-grey-7">{{ group.card.ccy }}</span>
              </div>
              <div class="text-caption text-grey-6">{{ tile.count }}</div>
            </div>
          </div>
        </div>

        <!-- Duplicated, not extracted: FormContractTest reads FormTransaction.vue's text. -->
        <div class="row q-col-gutter-sm">
          <q-select
            v-model="bankId"
            :options="options"
            class="col-12 col-sm-6"
            label="Paid from"
            filled
            emit-value
            map-options
            :hint="bankHint"
            :error="!!bankError"
            :error-message="bankError"
          >
            <template #no-option>
              <q-item>
                <q-item-section class="text-grey"> {{ noBankMessage }} </q-item-section>
              </q-item>
            </template>
          </q-select>

          <q-input
            v-model="paidOn"
            class="col-12 col-sm-6"
            label="Paid on"
            filled
            bottom-slots
            :error="!!fieldError"
            :error-message="fieldError"
          >
            <template #append>
              <q-btn flat dense icon="event" rounded>
                <q-menu ref="dateMenu" :offset="[10, 15]" anchor="bottom right" self="top right">
                  <q-date
                    :model-value="paidOn"
                    mask="YYYY-MM-DD"
                    minimal
                    color="primary"
                    @update:model-value="pickDate"
                  />
                </q-menu>
              </q-btn>
            </template>
          </q-input>
        </div>

        <div class="app-note row no-wrap">
          <q-icon name="info" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
          <div>
            This records a payment on {{ group.card.name }} dated {{ formatDate(paidOn) }}
            <template v-if="chosenName">
              and a transfer of the same amount out of {{ chosenName }}.
              <template v-if="changedBank">The card will be paid from there from now on.</template>
            </template>
            <template v-else>, and no account has been chosen to pay it from.</template>
          </div>
        </div>

        <div v-if="error" class="app-note app-note--negative row no-wrap">
          <q-icon name="error_outline" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
          <div>{{ error }}</div>
        </div>
      </q-card-section>

      <q-separator v-if="period" inset />

      <q-card-actions class="q-pa-md">
        <div class="col-12">
          <div class="row">
            <q-space />
            <q-btn
              class="text-weight-bold q-mr-md"
              color="grey-6"
              padding="sm md"
              flat
              no-caps
              label="Cancel"
              @click="open = false"
            />
            <q-btn
              class="text-weight-bold app-btn app-btn--positive"
              padding="sm md"
              unelevated
              no-caps
              :label="settling ? 'Settling' : `Settle ${money(period?.owed)}`"
              :loading="settling"
              :disable="!settleable"
              @click="confirm"
            />
          </div>
        </div>
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
const props = defineProps({
  group: { type: Object, default: Object },
  period: { type: Object, default: null },
  bank: { type: Object, default: null },
})

// Narrowed to the card's currency by the controller, not here: a second copy of
// guardSettledFrom()'s rule would drift from it.
const optionsFor = ccy => (usePage().props.settlementOptionsByCcy ?? {})[ccy] ?? []

const options = computed(() => optionsFor(props.group?.card?.ccy))

const noBankMessage = computed(() => `No cash account in ${props.group?.card?.ccy} to pay from`)

const bankId = ref(null)

const chosenName = computed(() => options.value.find(o => o.value === bankId.value)?.label ?? null)

const changedBank = computed(() => bankId.value !== null && bankId.value !== props.bank?.id)

const bankHint = computed(() =>
  changedBank.value
    ? 'The card will be paid from this account from now on'
    : 'Where the money leaves when this card is paid',
)

const formatDate = useCalendarDay()

const open = ref(false)
const settling = ref(false)
const error = ref(null)

const paidOn = ref('')

const fieldError = ref(null)

const bankError = ref(null)

const dateMenu = ref(null)

const pickDate = value => {
  paidOn.value = typeof value === 'string' ? value : ''
  dateMenu.value?.hide()
}

const money = useMoney()

const count = (n, noun) => `${n} ${noun}${n === 1 ? '' : 's'}`

const tiles = computed(() =>
  props.period
    ? [
        {
          label: 'Charges',
          amount: props.period.charged,
          count: count(props.period.charge_count, 'charge'),
        },
        {
          label: 'Already paid',
          amount: props.period.paid,
          count: count(props.period.payment_count, 'payment'),
        },
      ]
    : [],
)

const settleable = computed(
  () => bankId.value !== null && !props.period?.pending_count && !settling.value,
)

const confirm = () => {
  settling.value = true

  router.post(
    `/accounts/${props.group.card.id}/settle`,
    // `owed` is only a staleness check; settle() computes the amount itself.
    {
      due_date: props.period.due_date,
      owed: props.period.owed,
      date: paidOn.value,
      settlement_account_id: bankId.value,
    },
    {
      preserveScroll: true,
      preserveState: true,
      // Settle's own spinner -- see plugins/quasar.js.
      showProgress: false,
      onError: errors => {
        fieldError.value = errors.date ?? null
        bankError.value = errors.settlement_account_id ?? null
        error.value = errors.due_date ?? errors.date ?? 'That statement could not be settled.'
      },
      onSuccess: () => {
        open.value = false
        error.value = null
        fieldError.value = null
        bankError.value = null
      },
      onFinish: () => (settling.value = false),
    },
  )
}

watch(
  () => props.period,
  () => {
    error.value = null
    fieldError.value = null
    bankError.value = null
  },
)

// All three passed in: the props they fill have not updated yet in this tick, so reading
// options here would check the bank against the previous card's currency, or none.
defineExpose({
  show: (period, bank, group) => {
    paidOn.value = period?.due_date ?? ''
    // A card may name a bank in another currency; preselecting it would show a bare id.
    bankId.value = optionsFor(group?.card?.ccy).some(o => o.value === bank?.id) ? bank.id : null
    error.value = null
    fieldError.value = null
    bankError.value = null
    open.value = true
  },
})
</script>
