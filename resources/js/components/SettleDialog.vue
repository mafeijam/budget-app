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
        <!--
          Read-only by design. The amount of a settlement is not a thing a user
          chooses: it is what the period owes, computed by the server. An editable
          field here would need a bound -- the server would have to accept a partial
          payment or reject one that exceeds the period, which is a different feature.

          The date below is the exception, and it is not one of the figure. That
          argument is about a value the period decides; the day the money moved is the
          user's own fact about their life, which is why it gets a control.
        -->
        <!-- Which bill, and what it comes to: the figure the button will pay, largest. -->
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

        <!-- How the figure is made up, in two tiles, so the headline can be checked. -->
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

        <!--
          The same control the transaction form uses, and duplicated rather than
          extracted: FormContractTest reads FormTransaction.vue's own text for
          v-model="form.date", so moving that binding into a child component would fail
          the contract, and teaching the test about components costs more than the
          duplication.
        -->
        <!--
          Always present rather than only for a card that names no bank, because a card
          is paid from different accounts at different times and making the user visit
          the account form to change it is a worse answer than asking here. The server
          remembers the answer, so this is a choice with a consequence rather than a
          field, and the sentence below says what that consequence is.
        -->
        <!--
          Side by side, the two facts about the payment the user supplies; stacked on a
          narrow screen. bottom-slots on the date so it reserves the same space under it
          as the picker's hint does, and the two fields line up.
        -->
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

        <!--
          The consequence the figure above does not show: settling writes a second row
          taking money out of a bank account the user may not have had in mind, or may
          not have connected to this card at all. Cheaper to say now than to discover in
          the bank list. Both rows carry the date above, so it is named once rather than
          implying the transfer happened whenever, and the account is named as chosen so
          the sentence stays true while the picker is being used.
        -->
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

        <!--
          Shown after a refusal whose figure has moved. The server recomputes rather
          than trusting the number above, so a charge that landed while this dialog was
          open turns into a message and a second look, not a payment the user did not
          agree to.
        -->
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
  // The card's bank as {id, name}, or null when it names none. Both fields because the
  // picker is preselected with the id and the sentence below prints the name.
  bank: { type: Object, default: null },
})

// The cash accounts this card may be paid from: the shared list the account form also
// uses, narrowed to the card's own currency by the controller.
//
// Narrowed there rather than here, and that is the point rather than a convenience.
// Account::guardSettledFrom() refuses a bank in another currency, and its docblock says
// why this list must not be narrowed in the browser: a second copy of that rule is what
// lets this dialog offer a target the account form refuses, or the other way round, and
// neither would say so. So the controller sends what the server will accept for this
// currency and the dialog reads it.
//
// The account form keeps the whole list, because it cannot narrow: it is offering
// targets for whichever account is open, and that account's currency changes while the
// form is filled in. The currency is in every label there for the same reason it used to
// be here -- so an incompatible bank is recognisable rather than missing.
const options = computed(() => {
  const byCcy = usePage().props.settlementOptionsByCcy ?? {}

  return byCcy[props.group?.card?.ccy] ?? []
})

// Naming the currency, because "no cash account to pay from" is the wrong sentence when
// there are two cash accounts and neither is the right one: the user would go looking
// for a bank that does not exist rather than one in the wrong currency.
const noBankMessage = computed(() => `No cash account in ${props.group?.card?.ccy} to pay from`)

// Which account the money leaves from, preselected with the card's own so the ordinary
// case is nothing to do.
const bankId = ref(null)

const chosenName = computed(() => options.value.find(o => o.value === bankId.value)?.label ?? null)

// Whether the choice is not the card's current one, which the server takes as an
// instruction to change it. Said rather than left to be inferred, because settling one
// statement from the wrong account changes where every later one is paid from too.
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

// The day the money moved, and the statement's own due date until the user says
// otherwise. A settlement belongs to the period it settles, so the due date is the
// answer in the ordinary case and this is the field for paid early, or paid long after
// the statement fell due.
const paidOn = ref('')

// A field error and a banner error, kept apart so a bad date does not read as "that
// statement could not be settled": a malformed date is the user's own input and belongs
// beside the field, while a refusal is about the whole request.
const fieldError = ref(null)

// The account field's own error, kept apart from the banner for the same reason as the
// date: a target the server will not accept is about one field, and the banner would say
// the whole request failed.
const bankError = ref(null)

// The calendar's menu, so a chosen day can close it.
const dateMenu = ref(null)

// Writing the value and closing the menu together, as FormTransaction.vue does.
const pickDate = value => {
  paidOn.value = typeof value === 'string' ? value : ''
  dateMenu.value?.hide()
}

// `router`, not `useRouter`: that is the name @inertiajs/vue3 is auto-imported under in
// vite.config.js, and there is no useRouter to fall back to -- which fails at setup with
// a ReferenceError and leaves the button inert.

// Rounded as digits, never through a Number -- see money.js. The copy that lived here
// truncated rather than rounded, so 0.0050 owed printed as 0.00.
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

// Disabled rather than hidden, with the reason on the control, so the period still shows
// what it owes. An account must be chosen: there is nothing to write the transfer to
// without one, and the server refuses that case rather than guessing a bank.
const settleable = computed(
  () => bankId.value !== null && !props.period?.pending_count && !settling.value,
)

const confirm = () => {
  settling.value = true

  router.post(
    `/accounts/${props.group.card.id}/settle`,
    // The figure the user was shown, sent so the server can notice if it has moved.
    // Never used as the amount -- see TransactionController::settle(). The date is not
    // sent that way: settle() takes it as given, because unlike the amount it is not
    // something the server can check. The account is the third: settle() uses it and
    // remembers it on the card, so it is not a per-statement override.
    {
      due_date: props.period.due_date,
      owed: props.period.owed,
      date: paidOn.value,
      settlement_account_id: bankId.value,
    },
    {
      preserveScroll: true,
      preserveState: true,
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

// Opened by the panel rather than by an event bus, because the thing being settled is a
// period and the panel is the only place that knows which one. Reseeded on every open
// rather than the first, since the dialog is reusable and a date left over from settling
// one statement would silently date the next.
//
// The period and the bank arrive as arguments rather than being read off the props they
// also fill: the panel assigns them in the same tick it calls this, and a render -- so a
// props update -- is queued behind that. Read here they would be the previous open's, and
// on the first open after a page load there is no previous one: an empty date, an empty
// picker and a disabled confirm.
defineExpose({
  show: (period, bank) => {
    // The statement's own due date, not today: see paidOn above. And the card's own
    // bank, rather than whatever was chosen last time: settling one statement from
    // another account changed the card, so the next one starts from where that left off.
    paidOn.value = period?.due_date ?? ''
    // The card's own bank, where that bank is one this dialog can offer. A card naming a
    // bank in another currency is a stored state guardSettledFrom() refuses -- from
    // before that rule, or from data written another way -- and preselecting an id that
    // is not among the options would show a bare number in the picker and fail the save
    // over a choice the user was never offered.
    bankId.value = options.value.some(o => o.value === bank?.id) ? bank.id : null
    error.value = null
    fieldError.value = null
    bankError.value = null
    open.value = true
  },
})
</script>
