<template>
  <q-dialog v-model="open" persistent>
    <q-card flat class="card-form-dialog app-dialog--narrow">
      <q-card-section class="row items-start no-wrap">
        <q-icon name="credit_card" size="sm" color="grey-6" class="q-mr-sm q-mt-xs" />
        <div>
          <div class="text-h6 text-grey-9 text-weight-bold">Pay {{ group.card?.name }}</div>
          <div v-if="period" class="text-caption text-grey-7">
            Statement due {{ formatDate(period.due_date) }} ·
            {{ count(period.charge_count, 'charge') }}
            <template v-if="period.payment_count">
              · {{ count(period.payment_count, 'payment') }} already made
            </template>
          </div>
        </div>
        <q-space />
        <q-btn flat round dense color="grey-6" icon="close" @click="open = false" />
      </q-card-section>

      <q-card-section v-if="period" class="q-pt-none">
        <!-- The one figure: what the statement owes. The sum behind it is one quiet line. -->
        <div class="text-h4 text-weight-bold text-negative money">
          {{ money(period.owed) }}
          <span class="text-subtitle1 text-grey-7">{{ group.card.ccy }}</span>
        </div>
        <div class="text-caption text-grey-7 money q-mb-lg">
          Charges {{ money(period.charged) }} − already paid {{ money(period.paid) }}
        </div>

        <!-- Duplicated, not extracted: FormContractTest reads FormTransaction.vue's text. -->
        <div class="row q-col-gutter-sm">
          <q-input
            v-model="amount"
            class="col-12"
            label="Pay"
            filled
            inputmode="decimal"
            :suffix="group.card.ccy"
            :hint="amountHint"
            :error="!!amountError"
            :error-message="amountError"
          >
            <template v-if="partial" #append>
              <q-btn flat dense no-caps color="primary" label="Pay in full" @click="payInFull" />
            </template>
          </q-input>

          <q-select
            v-model="bankId"
            :options="options"
            class="col-12 col-sm-6"
            label="Pay from"
            filled
            emit-value
            map-options
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
            label="On"
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

        <div class="row no-wrap text-caption text-grey-7 q-mt-xs">
          <q-icon name="subdirectory_arrow_right" size="xs" class="q-mr-xs" />
          <div>
            <template v-if="chosenName">
              Writes a payment on {{ group.card.name }} and a withdrawal from {{ chosenName }}, both
              dated {{ formatDate(paidOn) }}.
              <template v-if="changedBank">The card is paid from there from now on.</template>
            </template>
            <template v-else>
              Writes a payment on {{ group.card.name }} dated {{ formatDate(paidOn) }}. Choose the
              account it is paid from.
            </template>
          </div>
        </div>

        <!-- Tinted notes are for what stops or changes the settlement, not for describing it. -->
        <div v-if="period.pending_count" class="app-note app-note--warning row no-wrap q-mt-md">
          <q-icon name="schedule" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
          <div>
            {{ count(period.pending_count, 'row') }} in this statement
            {{ period.pending_count === 1 ? 'is' : 'are' }} not yet posted, so the total is not
            final. Post or remove {{ period.pending_count === 1 ? 'it' : 'them' }} first.
          </div>
        </div>

        <div v-if="error" class="app-note app-note--negative row no-wrap q-mt-md">
          <q-icon name="error_outline" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
          <div>{{ error }}</div>
        </div>
      </q-card-section>

      <q-separator v-if="period" />

      <q-card-actions class="q-pa-md">
        <q-space />
        <q-btn
          class="text-weight-bold q-mr-sm"
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
          :label="
            settling ? 'Paying' : partial ? `Pay ${money(amount)}` : `Settle ${money(period?.owed)}`
          "
          :loading="settling"
          :disable="!settleable"
          @click="confirm"
        />
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

const amount = ref('')

const amountError = ref(null)

// Digits only, so the comparison stays on the decimal string rather than a float.
const toCents = value => {
  const match = String(value ?? '')
    .trim()
    .match(/^(\d+)(?:\.(\d{0,4}))?$/)

  return match ? BigInt(match[1] + (match[2] ?? '').padEnd(4, '0')) : null
}

const owedCents = computed(() => toCents(props.period?.owed))

const amountCents = computed(() => toCents(amount.value))

const validAmount = computed(
  () =>
    amountCents.value !== null &&
    amountCents.value > 0n &&
    owedCents.value !== null &&
    amountCents.value <= owedCents.value,
)

const partial = computed(() => validAmount.value && amountCents.value < owedCents.value)

const amountHint = computed(() => {
  if (amountCents.value === null) return 'An amount, to up to four places'
  if (amountCents.value > (owedCents.value ?? 0n)) return 'More than the statement owes'
  if (!partial.value) return 'Pays the statement in full'

  const left = owedCents.value - amountCents.value

  return `Leaves ${money(`${left / 10000n}.${String(left % 10000n).padStart(4, '0')}`)} owing`
})

const payInFull = () => (amount.value = twoPlaces(props.period?.owed ?? ''))

const settleable = computed(
  () =>
    bankId.value !== null && validAmount.value && !props.period?.pending_count && !settling.value,
)

const confirm = () => {
  settling.value = true

  router.post(
    `/accounts/${props.group.card.id}/settle`,
    // `owed` is only a staleness check; settle() computes the amount itself.
    {
      due_date: props.period.due_date,
      owed: props.period.owed,
      amount: amount.value,
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
        amountError.value = errors.amount ?? null
        error.value =
          errors.due_date ??
          errors.date ??
          (errors.amount ? null : 'That statement could not be settled.')
      },
      onSuccess: () => {
        open.value = false
        error.value = null
        fieldError.value = null
        bankError.value = null
        amountError.value = null
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
    amount.value = twoPlaces(period?.owed ?? '')
    amountError.value = null
    // A card may name a bank in another currency; preselecting it would show a bare id.
    bankId.value = optionsFor(group?.card?.ccy).some(o => o.value === bank?.id) ? bank.id : null
    error.value = null
    fieldError.value = null
    bankError.value = null
    open.value = true
  },
})
</script>
