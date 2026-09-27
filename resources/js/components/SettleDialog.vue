<template>
  <q-dialog v-model="open" persistent>
    <q-card flat class="card-form-dialog">
      <q-card-section>
        <div class="row justify-between items-center">
          <div class="text-h6 text-capitalize text-blue-grey-8 text-weight-bold">
            settle statement
          </div>
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
        <div class="text-subtitle2 text-weight-medium">
          {{ group.card.name }} · statement due {{ formatDate(period.due_date) }}
        </div>

        <q-markup-table dense flat class="q-my-sm">
          <tbody>
            <tr>
              <td>Charges</td>
              <td class="text-right">
                {{ period.charge_count }} · {{ money(period.charged) }} {{ group.card.ccy }}
              </td>
            </tr>
            <tr>
              <td>Already paid</td>
              <td class="text-right">
                {{ period.payment_count }} · {{ money(period.paid) }} {{ group.card.ccy }}
              </td>
            </tr>
            <tr class="text-weight-medium">
              <td>Owes</td>
              <td class="text-right">{{ money(period.owed) }} {{ group.card.ccy }}</td>
            </tr>
          </tbody>
        </q-markup-table>

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
        <q-select
          v-model="bankId"
          :options="options"
          class="q-mb-sm"
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
              <q-item-section class="text-grey"> No cash account to pay from </q-item-section>
            </q-item>
          </template>
        </q-select>

        <q-input
          v-model="paidOn"
          class="q-mb-sm"
          label="Paid on"
          filled
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
                  color="green-7"
                  @update:model-value="pickDate"
                />
              </q-menu>
            </q-btn>
          </template>
        </q-input>

        <!--
          The consequence the figure above does not show: settling writes a second row
          taking money out of a bank account the user may not have had in mind, or may
          not have connected to this card at all. Cheaper to say now than to discover in
          the bank list. Both rows carry the date above, so it is named once rather than
          implying the transfer happened whenever, and the account is named as chosen so
          the sentence stays true while the picker is being used.
        -->
        <div class="bg-blue-1 rounded-borders text-blue-9 text-body2 q-pa-md">
          This records a payment on {{ group.card.name }} dated {{ formatDate(paidOn) }}
          <template v-if="chosenName">
            and a transfer of the same amount out of {{ chosenName }}.
            <template v-if="changedBank">The card will be paid from there from now on.</template>
          </template>
          <template v-else>, and no account has been chosen to pay it from.</template>
        </div>

        <!--
          Shown after a refusal whose figure has moved. The server recomputes rather
          than trusting the number above, so a charge that landed while this dialog was
          open turns into a message and a second look, not a payment the user did not
          agree to.
        -->
        <div v-if="error" class="bg-red-1 rounded-borders text-red-9 text-body2 q-pa-md">
          {{ error }}
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
              label="cancel"
              @click="open = false"
            />
            <q-btn
              class="text-weight-bold"
              padding="sm md"
              color="green-1"
              text-color="green-9"
              unelevated
              icon="done"
              :label="settling ? 'settling' : `settle ${money(period?.owed)}`"
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

// The cash accounts a card may be paid from, from the page props: the same list the
// account form offers, sent by the controller, so the two cannot disagree about what may
// be a target. The currency is in each label because the server refuses a mismatch, so a
// bank in the wrong currency is recognisable rather than a surprise on save.
const options = computed(() => usePage().props.settlementOptions ?? [])

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

// Rounded on the string rather than through a Number, for the reason the panel gives.
const money = value => {
  if (value === null || value === undefined) return ''

  const [whole, places = ''] = String(value).split('.')

  return `${whole}.${places.padEnd(4, '0').slice(0, 2)}`
}

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
    bankId.value = bank?.id ?? null
    error.value = null
    fieldError.value = null
    bankError.value = null
    open.value = true
  },
})
</script>
