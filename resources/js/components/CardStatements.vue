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
          </tr>
        </thead>
        <tbody>
          <tr v-for="period in group.periods" :key="period.due_date">
            <td>
              {{ formatDate(period.due_date) }}
              <!--
                The one thing the figure above cannot tell the user. The owed total is
                correct without a pending row -- that is what pending means -- so a
                reader who trusted it would pay against a statement that grows once
                the charge posts, and the period would reopen under them having
                already settled it. Said here rather than only in the row, because
                the row is what they would act on.
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
          </tr>
        </tbody>
      </q-markup-table>
    </q-card-section>
  </q-card>
</template>

<script setup>
defineProps({
  groups: { type: Array, default: Array },
})

// A day, not a timestamp: due_date is a calendar day, and formatting it with a
// time would invent a settlement hour the column does not hold.
const formatDate = useCalendarDay()

// Two decimal places for reading, from a four-place string. Not a float: the
// figures arrive as strings precisely so the arithmetic upstream was exact, and
// parsing one into a Number here to round it would reintroduce the drift
// BigDecimal was there to avoid. Rounded on the string.
const money = value => {
  const [whole, places = ''] = String(value).split('.')

  return `${whole}.${places.padEnd(4, '0').slice(0, 2)}`
}
</script>
