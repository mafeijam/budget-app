<template>
  <q-card v-if="groups.length" flat bordered>
    <q-card-section class="row items-center">
      <div class="text-h6 text-weight-medium">Card statements</div>
      <q-space />
      <div class="text-caption text-grey-7">Periods still owing</div>
    </q-card-section>

    <q-separator />

    <!--
      One table for every card, not one per card. A separate table computes its own
      column widths, so with two or more cards the Due column of one sat wherever its own
      figures put it and the header repeated above each -- aligned with a single card,
      visibly not with two. The card name is a spanning row rather than a heading, so it
      still separates one card's periods from the next.
    -->
    <q-markup-table flat dense>
      <thead>
        <tr class="text-left text-grey-7">
          <th>Covers</th>
          <th>Due</th>
          <th class="text-right">Charges</th>
          <th class="text-right">Paid</th>
          <th class="text-right">Owes</th>
          <th />
        </tr>
      </thead>
      <tbody>
        <template v-for="group in groups" :key="group.card.id">
          <tr class="bg-grey-1">
            <th colspan="6" class="text-left q-py-sm">
              <q-icon name="credit_card" size="xs" color="grey-7" class="q-mr-sm" />
              <span class="text-subtitle2 text-weight-medium">{{ group.card.name }}</span>
              <q-badge outline color="grey-7" class="q-ml-sm" :label="group.card.ccy" />
              <!-- Where the money leaves, so the settle dialog holds no surprise. -->
              <span class="text-caption text-grey-7 text-weight-regular q-ml-md">
                {{ bankLine(group) }}
              </span>
            </th>
          </tr>
          <tr v-for="period in group.periods" :key="`${group.card.id}-${period.due_date}`">
            <td class="text-grey-8">{{ covers(period) }}</td>
            <td>
              <span class="text-weight-medium">{{ formatDate(period.due_date) }}</span>
              <q-badge v-bind="dueBadge(period)" class="q-ml-sm" />
              <!--
                The one thing the figure cannot tell the user: the owed total is correct
                without a pending row, so a reader who trusted it would pay against a
                statement that grows once the charge posts and the period would reopen
                under them having settled it. Said on the due date rather than on Covers
                because it is about whether the period can be settled at all.
              -->
              <q-badge
                v-if="period.pending_count"
                class="q-ml-sm"
                color="amber-9"
                text-color="white"
                :label="`${period.pending_count} not yet posted`"
              />
              <!--
                After the badge, so the cell reads due, then why it is provisional, then
                what to do about it. Icon rather than a labelled button because the cell
                is the width of a date and the words are on hover.
              -->
              <q-btn
                dense
                flat
                round
                class="q-ml-xs"
                color="grey-7"
                icon="edit_calendar"
                :disable="!correctable(period)"
                @click="openCorrect(group, period)"
              >
                <q-tooltip v-if="!correctable(period)" :delay="500" :offset="[0, 6]">
                  {{ correctionBlocked(period) }}
                </q-tooltip>
                <q-tooltip v-else :delay="500" :offset="[0, 6]">
                  Correct this statement's due date
                </q-tooltip>
              </q-btn>
            </td>
            <td class="text-right">
              {{ money(period.charged) }}
              <div class="text-caption text-grey-6">{{ count(period.charge_count, 'charge') }}</div>
            </td>
            <td class="text-right">
              {{ money(period.paid) }}
              <div class="text-caption text-grey-6">
                {{ count(period.payment_count, 'payment') }}
              </div>
            </td>
            <td class="text-right text-subtitle1 text-weight-bold" :class="owedClass(period)">
              {{ money(period.owed) }}
            </td>
            <td class="text-right">
              <!-- Disabled rather than hidden, for the reason given on `settleable`. -->
              <q-btn
                dense
                unelevated
                no-caps
                color="green-1"
                text-color="green-9"
                icon="payments"
                label="Settle"
                class="q-px-sm text-weight-bold"
                :disable="!settleable(group, period)"
                @click="openSettle(group, period)"
              >
                <q-tooltip v-if="!settleable(group, period)" :delay="500" :offset="[0, 6]">
                  {{ blockedReason(group, period) }}
                </q-tooltip>
              </q-btn>
            </td>
          </tr>
        </template>
      </tbody>
    </q-markup-table>

    <SettleDialog
      ref="dialog"
      :group="chosen?.group"
      :period="chosen?.period"
      :bank="chosen?.bank"
    />

    <DueDateDialog ref="dueDialog" :group="chosen?.group" :period="chosen?.period" />
  </q-card>
</template>

<script setup>
const props = defineProps({
  groups: { type: Array, default: Array },
  // The bank each card is paid from, keyed by card id, so the dialog can name where
  // the money leaves before the user commits.
  banks: { type: Object, default: () => ({}) },
})

const formatDate = useCalendarDay()

// Rounded as digits, never through a Number -- see money.js. The copy that lived here
// truncated rather than rounded, so 0.0050 owed printed as 0.00.
const money = useMoney()

const count = (n, noun) => `${n} ${noun}${n === 1 ? '' : 's'}`

const bankLine = group => {
  const bank = props.banks[group.card.id]

  return bank ? `Paid from ${bank.name}` : 'No bank named yet'
}

// How close the due date is, counted by the server from Hong Kong's today rather than
// the browser's -- see days_until_due in TransactionController::index().
const dueBadge = period => {
  const days = period.days_until_due

  if (days < 0) {
    return {
      color: 'red-1',
      textColor: 'red-9',
      label: `${-days} day${days === -1 ? '' : 's'} overdue`,
    }
  }

  if (days === 0) return { color: 'amber-2', textColor: 'amber-10', label: 'due today' }

  return { color: 'grey-2', textColor: 'grey-8', label: `in ${days} day${days === 1 ? '' : 's'}` }
}

// Red while it owes; a period paid beyond its charges is in credit and reads green.
const owedClass = period =>
  String(period.owed).startsWith('-') ? 'text-positive' : 'text-negative'

// The dates of the charges in the period, which is what the statement is about -- Due
// says when it is payable, this says what it is for. One date when every charge landed
// on the same day, because "16 Sep – 16 Sep" says less and takes twice the room.
// Nothing at all for a period with no charge in it, which is honest: it covers nothing.
const covers = period => {
  const from = period.first_charge_date
  const to = period.last_charge_date

  if (!from) return ''

  return from === to ? formatDate(from) : `${formatDate(from)} – ${formatDate(to)}`
}

// Whether a period holds anything the issuer has not billed, which is what makes its
// figures and its due date provisional together. One predicate for the two questions
// below: they are refused for the same reason, and two functions each testing
// period.pending_count would be a second copy of a decision the server makes.
const pending = period => period.pending_count > 0

// The one condition the server refuses on that the panel can see: a period with
// pending rows has a total that is not final, and offering to settle it would be
// offering something that comes back as a refusal.
//
// Not the bank, which used to be the other half of this. The dialog asks which account
// to pay from now, so a card that names none is the case it exists for, and disabling
// the button was the dead end. A card with no cash account anywhere is still stuck, and
// the dialog is where that gets said.
//
// Disabled rather than hidden: the period still shows what it owes, and the control
// says why it cannot be settled yet.
const settleable = (group, period) => !pending(period)

const blockedReason = (group, period) => {
  if (pending(period)) {
    return `${period.pending_count} row${period.pending_count === 1 ? '' : 's'} not yet posted, so the total is not final`
  }

  return ''
}

// The same refusal, asked about a different act, and worded for it: a period that is not
// final cannot be paid and has not been issued, which are different sentences about
// different things.
//
// A settled period is the other refusal the server has, and the panel never shows one:
// index() filters them out before the periods reach here. So pending is the only
// condition this can be disabled for, and the server asks again regardless -- a stale
// page gets past a disabled button.
const correctable = period => !pending(period)

const correctionBlocked = period => {
  if (pending(period)) {
    return `${period.pending_count} row${period.pending_count === 1 ? '' : 's'} not yet posted, so the statement has not been issued yet`
  }

  return ''
}

const dialog = ref(null)
const dueDialog = ref(null)
const chosen = ref(null)

// The period and the bank go to show() as well as onto `chosen`, because chosen
// reaches the dialog as props and a render is queued rather than run: a show()
// reading props in the same tick as this assignment sees the previous open's
// values, and the first after a page load sees none at all -- so the dialog would
// open on an empty date and an empty picker, with its confirm disabled. The
// caller has both values already, so it passes them.
const openSettle = (group, period) => {
  const bank = props.banks[group.card.id] ?? null

  chosen.value = { group, period, bank }
  dialog.value?.show(period, bank)
}

// The same two lines for the same reason, and the period passed as an argument for the
// same reason: chosen reaches the dialog as props and a render is queued rather than run,
// so a show() reading props in the same tick as the assignment sees the previous open's
// values and the first after a page load sees none at all.
const openCorrect = (group, period) => {
  chosen.value = { group, period }
  dueDialog.value?.show(period)
}
</script>
