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
          Read-only by design. The figure is computed by the server and this dialog
          never edits it, because the amount of a settlement is not a thing a user
          chooses: it is what the period owes. An editable field here would suggest
          otherwise, and would need a bound -- the server would have to accept a
          partial payment or reject one that exceeds the period, which is a different
          feature with different rules.

          The date below is the exception, and it is not one of the figure. That
          argument is about a value the period decides; the day the money moved is
          the user's own fact about their life, which is why it gets a control.
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
          The one editable thing here, and the same control the transaction form uses:
          a q-date in a menu rather than a native input, which would render in the
          browser's locale while the value is ISO. See FormTransaction.vue for why the
          mask belongs to the q-date and to nothing else.

          Duplicated rather than extracted, because FormContractTest reads
          FormTransaction.vue's own text for v-model="form.date" -- moving that binding
          into a child component would fail the contract, and teaching the test about
          components is a larger change than the duplication costs.
        -->
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
          The consequence the user cannot see from the figure above, so it is said
          plainly. Settling writes two rows, and the second one takes money out of a
          bank account they may not have had in mind -- or may not have connected to
          this card at all. Cheaper to say now than to discover in the bank list.

          Both rows carry the date above, so the sentence names it once rather than
          implying the transfer happened whenever.
        -->
        <div class="bg-blue-1 rounded-borders text-blue-9 text-body2 q-pa-md">
          This records a payment on {{ group.card.name }} dated {{ formatDate(paidOn) }}
          <template v-if="bank"> and a transfer of the same amount out of {{ bank }}.</template>
          <template v-else>
            , but this card does not name the bank it is paid from, so it cannot be settled.
          </template>
        </div>

        <!--
          Shown after a refusal whose figure has moved. The server recomputes and
          compares rather than trusting the number above, so a charge that landed
          while this dialog was open turns into a message and a second look, not a
          payment the user did not agree to.
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
  // The card's bank, or null when it has none. Sent by the controller rather than
  // looked up here, so the dialog can say which account the money leaves before the
  // user commits -- and so the same check the server makes is on screen.
  bank: { type: String, default: null },
})

const formatDate = useCalendarDay()

const open = ref(false)
const settling = ref(false)
const error = ref(null)

// The day the money moved, and the statement's own due date until the user says
// otherwise. A settlement belongs to the period it settles, so the due date is the
// answer in the ordinary case and this is the field for the days it is not -- paid
// early, or paid long after the statement fell due.
const paidOn = ref('')

// A field error and a banner error, because a malformed date is the user's own input
// and belongs beside the field while a refusal is about the whole request. Both are
// kept apart so a bad date does not read as "that statement could not be settled".
const fieldError = ref(null)

// The calendar's menu, so a chosen day can close it.
const dateMenu = ref(null)

// Writing the value and closing the menu together, so they cannot come apart -- the
// same reason FormTransaction.vue does it in one function.
const pickDate = value => {
  paidOn.value = typeof value === 'string' ? value : ''
  dateMenu.value?.hide()
}

// `router`, not `useRouter`: that is the name under which @inertiajs/vue3 is listed in
// vite.config.js's auto-import set, and there is no useRouter to fall back to -- which
// fails at setup with a ReferenceError and leaves the dialog's button inert.

// Rounded on the string rather than through a Number, for the reason the panel gives.
const money = value => {
  if (value === null || value === undefined) return ''

  const [whole, places = ''] = String(value).split('.')

  return `${whole}.${places.padEnd(4, '0').slice(0, 2)}`
}

// A period with pending rows is not final, and a card with no bank cannot be paid
// at all. Both are disabled rather than hidden, with the reason visible, so the
// period still shows what it owes and why it cannot be settled yet.
const settleable = computed(
  () => Boolean(props.bank) && !props.period?.pending_count && !settling.value,
)

const confirm = () =>
  router.post(
    `/accounts/${props.group.card.id}/settle`,
    // The figure the user was shown, sent so the server can notice if it has moved.
    // Never used as the amount -- see TransactionController::settle(). The date is not
    // sent the same way: settle() takes it as given, because unlike the amount it is
    // not something the server can check.
    { due_date: props.period.due_date, owed: props.period.owed, date: paidOn.value },
    {
      preserveScroll: true,
      preserveState: true,
      onError: errors => {
        fieldError.value = errors.date ?? null
        error.value = errors.due_date ?? errors.date ?? 'That statement could not be settled.'
      },
      onSuccess: () => {
        open.value = false
        error.value = null
        fieldError.value = null
      },
      onFinish: () => (settling.value = false),
    },
  )

watch(
  () => props.period,
  () => {
    error.value = null
    fieldError.value = null
  },
)

// Opened by the panel rather than by an event bus, because the thing being settled
// is a period and the panel is the only place that knows which one.
//
// Reseeded on every open rather than on the first: the dialog is reusable, and a date
// left over from settling one statement would silently date the next.
defineExpose({
  show: () => {
    // The statement's own due date, not today. A settlement belongs to the period it
    // settles, and this field is for the days that is not the answer -- paid early, or
    // paid long after the statement fell due.
    paidOn.value = props.period?.due_date ?? ''
    error.value = null
    fieldError.value = null
    open.value = true
  },
})
</script>
