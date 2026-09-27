<template>
  <q-card v-if="groups.length" flat bordered>
    <q-card-section>
      <div class="text-h6 text-weight-medium">Card statements</div>
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
        <tr class="text-left">
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
          <tr>
            <th colspan="6" class="text-left q-py-sm q-pl-none">
              <span class="text-subtitle2 text-weight-medium">{{ group.card.name }}</span>
              <span class="text-grey-6 text-weight-regular">· {{ group.card.ccy }}</span>
            </th>
          </tr>
          <tr v-for="period in group.periods" :key="`${group.card.id}-${period.due_date}`">
            <td>{{ covers(period) }}</td>
            <td>
              {{ formatDate(period.due_date) }}
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
            </td>
            <td class="text-right">
              {{ period.charge_count }}
              <span class="text-grey-6 text-weight-regular">· {{ money(period.charged) }}</span>
            </td>
            <td class="text-right">
              {{ period.payment_count }}
              <span class="text-grey-6 text-weight-regular">· {{ money(period.paid) }}</span>
            </td>
            <td class="text-right text-weight-medium">{{ money(period.owed) }}</td>
            <td class="text-right">
              <!-- Disabled rather than hidden, for the reason given on `settleable`. -->
              <q-btn
                dense
                flat
                no-caps
                color="green-9"
                icon="payments"
                label="settle"
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

// Rounded on the string, not through a Number: the figures arrive as strings
// precisely so the arithmetic upstream was exact, and parsing one to round it would
// reintroduce the drift BigDecimal was there to avoid.
const money = value => {
  if (value === null || value === undefined) return ''

  const [whole, places = ''] = String(value).split('.')

  return `${whole}.${places.padEnd(4, '0').slice(0, 2)}`
}

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
const settleable = (group, period) => !period.pending_count

const blockedReason = (group, period) => {
  if (period.pending_count) {
    return `${period.pending_count} row${period.pending_count === 1 ? '' : 's'} not yet posted, so the total is not final`
  }

  return ''
}

const dialog = ref(null)
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
</script>
