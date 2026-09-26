<template>
  <q-card v-if="groups.length" flat bordered>
    <q-card-section>
      <div class="text-h6 text-weight-medium">Card statements</div>
    </q-card-section>

    <q-separator />

    <q-card-section v-for="group in groups" :key="group.card.id" class="q-py-sm">
      <div class="text-subtitle2 text-weight-medium q-mb-sm">
        {{ group.card.name }}
        <span class="text-grey-6 text-weight-regular">· {{ group.card.ccy }}</span>
      </div>

      <q-markup-table flat dense>
        <thead>
          <tr class="text-left">
            <th>Due</th>
            <th class="text-right">Charges</th>
            <th class="text-right">Paid</th>
            <th class="text-right">Owes</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="period in group.periods" :key="period.due_date">
            <td>
              {{ formatDate(period.due_date) }}
              <!--
                The one thing the figure cannot tell the user. The owed total is correct
                without a pending row -- that is what pending means -- so a reader who
                trusted it would pay against a statement that grows once the charge
                posts, and the period would reopen under them having settled it. Said
                here rather than only in the dialog, because the row is what they would
                act on.
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
              <!--
                Disabled rather than hidden, and the reason is the dialog's. A period
                with pending rows has a total that is not final and a card with no
                bank has nowhere to pay from; either way the user is better served by
                the period still showing what it owes and a control that says why not
                than by a missing button.
              -->
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
        </tbody>
      </q-markup-table>
    </q-card-section>

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
  // The bank each card is paid from, keyed by card id. Sent by the controller so the
  // dialog can name the account the money leaves before the user commits, and so the
  // server's own check is on screen rather than only appearing on a refusal.
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

const bankName = card => props.banks[card.id] ?? null

// The same two conditions the server refuses on, kept in step with
// TransactionController::settle(). A copy rather than a derivation, because the
// server's answer comes from the database and this one only has the panel's payload.
// Still worth having: a control that offers an action the server will refuse, or
// hides one it will accept, is worse than a duplicated condition.
const settleable = (group, period) => Boolean(bankName(group.card)) && !period.pending_count

const blockedReason = (group, period) => {
  if (!bankName(group.card)) return 'This card does not name the bank it is paid from'

  if (period.pending_count) {
    return `${period.pending_count} row${period.pending_count === 1 ? '' : 's'} not yet posted, so the total is not final`
  }

  return ''
}

const dialog = ref(null)
const chosen = ref(null)

const openSettle = (group, period) => {
  chosen.value = { group, period, bank: bankName(group.card) }
  dialog.value?.show()
}
</script>
