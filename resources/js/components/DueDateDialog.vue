<template>
  <q-dialog v-model="open" persistent>
    <q-card flat class="card-form-dialog">
      <q-card-section>
        <div class="row justify-between items-center">
          <div class="text-h6 text-capitalize text-blue-grey-8 text-weight-bold">
            statement issued
          </div>
          <q-btn flat round color="grey-6" icon="close" @click="open = false" />
        </div>
      </q-card-section>

      <q-separator inset />

      <q-card-section v-if="period" class="q-gutter-sm">
        <!--
          Read-only, and the reason this dialog is not SettleDialog. Nothing here is the
          user's to choose: the period already owes what it owes and moving the day it
          falls due does not change a figure of it. What the user is supplying is a fact
          about the statement the bank posted, which is the one thing about a period the
          card's terms cannot know.
        -->
        <div class="text-subtitle2 text-weight-medium">
          {{ group.card.name }} · statement due {{ formatDate(period.due_date) }}
        </div>

        <q-markup-table dense flat class="q-my-sm">
          <tbody>
            <tr>
              <td>Covers</td>
              <td class="text-right">{{ covers }}</td>
            </tr>
            <tr>
              <td>Charges</td>
              <td class="text-right">
                {{ period.charge_count }} · {{ money(period.charged) }} {{ group.card.ccy }}
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
          extracted for the reason given there: FormContractTest reads FormTransaction.vue's
          own text for v-model="form.date", so moving that binding into a child component
          would fail the contract, and teaching the test about components costs more than
          the duplication. It does not pin this file, which is not a form the test walks --
          it only says nothing here is worth arguing with.
        -->
        <q-input
          v-model="stated"
          class="q-mb-sm"
          label="Due on the statement"
          filled
          :hint="hint"
          :error="!!fieldError"
          :error-message="fieldError"
        >
          <template #append>
            <q-btn flat dense icon="event" rounded>
              <q-menu ref="dateMenu" :offset="[10, 15]" anchor="bottom right" self="top right">
                <q-date
                  :model-value="stated"
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
          The consequence, which is not a figure: what changes is when the bill is payable,
          and what does not change is the card's terms. A charge recorded after this lands
          by the statement day and term as they stand, so a card whose terms are simply
          wrong keeps producing the wrong period until those are changed on the account.
          Cheaper to say here than to let the next charge fall due on the old day and look
          like a late payment.
        -->
        <div v-if="changed" class="bg-blue-1 rounded-borders text-blue-9 text-body2 q-pa-md">
          Every charge and payment in this statement moves to
          {{ formatDate(stated) }}. The amount does not change.
        </div>

        <!--
          Shown after a refusal, which is about the statement rather than the field: a
          settled period, one that is not issued yet, or a day that is already another
          statement. All three are decided by the server from state the panel does not
          carry, so the button below can only disable for the one it can see.
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
              :label="saving ? 'saving' : 'save due date'"
              :loading="saving"
              :disable="!saveable"
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
})

const formatDate = useCalendarDay()

const open = ref(false)
const saving = ref(false)
const error = ref(null)
const fieldError = ref(null)

// The day the bank stated, and the day the card's terms predicted. Two fields rather than
// one plus a dirty flag, because the panel passes the period in and there is nothing to
// compare a flag against until the props arrive -- and a compare against props.due_date
// would be comparing against the previous open's on a reopen, which is the bug
// defineExpose() below exists to avoid.
const stated = ref('')
const was = ref('')

// Whether this is a correction at all. False on open, so the ordinary case is a dialog
// showing what the panel already shows and one button that does nothing.
const changed = computed(() => stated.value !== '' && stated.value !== was.value)

// The charges the period covers, which is what identifies the statement being corrected.
// A statement due date is otherwise a date with nothing to hang it on.
const covers = computed(() => {
  const from = props.period?.first_charge_date
  const to = props.period?.last_charge_date

  if (!from) return ''

  return from === to ? formatDate(from) : `${formatDate(from)} – ${formatDate(to)}`
})

const hint = computed(() =>
  changed.value
    ? `Was ${formatDate(was.value)}, counted from the card's terms`
    : "What the card's statement day and term make of it",
)

// Rounded on the string rather than through a Number, for the reason the panel gives.
const money = value => {
  if (value === null || value === undefined) return ''

  const [whole, places = ''] = String(value).split('.')

  return `${whole}.${places.padEnd(4, '0').slice(0, 2)}`
}

// The calendar's menu, so a chosen day can close it.
const dateMenu = ref(null)

const pickDate = value => {
  stated.value = typeof value === 'string' ? value : ''
  dateMenu.value?.hide()
}

// Nothing to save on open, and a pending period is refused by the server whatever the
// date -- which the panel has already disabled the button for, so reaching here with one
// means a stale page.
const saveable = computed(() => changed.value && !props.period?.pending_count && !saving.value)

// `router`, not `useRouter`: that is the name @inertiajs/vue3 is auto-imported under in
// vite.config.js, and there is no useRouter to fall back to -- which fails at setup with a
// ReferenceError and leaves the button inert.
const confirm = () => {
  saving.value = true

  router.post(
    `/accounts/${props.group.card.id}/due-date`,
    {
      due_date: was.value,
      new_due_date: stated.value,
    },
    {
      preserveScroll: true,
      preserveState: true,
      onError: errors => {
        fieldError.value = errors.new_due_date ?? null
        error.value = errors.due_date ?? "That statement's due date could not be changed."
      },
      onSuccess: () => {
        // The server's own words: the controller flashes what it did and this reads it
        // off the page props, so there is no message here that could drift from it. The
        // convention lives in useSubmit() and useDestroy(), neither of which this
        // dialog uses -- it posts on its own rather than through a form.
        notifySuccess()

        open.value = false
        error.value = null
        fieldError.value = null
      },
      onFinish: () => (saving.value = false),
    },
  )
}

watch(
  () => props.period,
  () => {
    error.value = null
    fieldError.value = null
  },
)

// Opened by the panel rather than by an event bus, because the thing being corrected is a
// period and the panel is the only place that knows which one. Reseeded on every open
// rather than the first, since the dialog is reusable and a date left over from correcting
// one statement would silently re-date the next.
//
// The period arrives as an argument rather than being read off the props it also fills:
// the panel assigns them in the same tick it calls this, and a render -- so a props update
// -- is queued behind that. Read here they would be the previous open's, and on the first
// open after a page load there is no previous one.
defineExpose({
  show: period => {
    was.value = period?.due_date ?? ''
    stated.value = was.value
    error.value = null
    fieldError.value = null
    open.value = true
  },
})
</script>
