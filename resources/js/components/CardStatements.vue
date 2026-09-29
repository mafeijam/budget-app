<template>
  <q-card v-if="groups.length" flat bordered>
    <q-card-section class="row items-center">
      <div class="text-h6 text-weight-medium">Card statements</div>
      <q-space />
      <div class="text-caption text-grey-7">Periods still owing</div>
    </q-card-section>

    <q-separator />

    <!-- One table for every card, so the columns line up across cards. -->
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
              <!-- A pending row means the owed total is not final yet. -->
              <q-badge
                v-if="period.pending_count"
                class="q-ml-sm app-tint app-tint--warning"
                :label="`${period.pending_count} not yet posted`"
              />
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
            <td class="text-right money">
              {{ money(period.charged) }}
              <div class="text-caption text-grey-6">{{ count(period.charge_count, 'charge') }}</div>
            </td>
            <td class="text-right money">
              {{ money(period.paid) }}
              <div class="text-caption text-grey-6">
                {{ count(period.payment_count, 'payment') }}
              </div>
            </td>
            <td class="text-right text-subtitle1 text-weight-bold money" :class="owedClass(period)">
              {{ money(period.owed) }}
            </td>
            <td class="text-right">
              <q-btn
                dense
                unelevated
                no-caps
                label="Settle"
                class="q-px-sm text-weight-bold app-btn app-btn--positive"
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
  banks: { type: Object, default: () => ({}) },
})

const formatDate = useCalendarDay()

const money = useMoney()

const count = (n, noun) => `${n} ${noun}${n === 1 ? '' : 's'}`

const bankLine = group => {
  const bank = props.banks[group.card.id]

  return bank ? `Paid from ${bank.name}` : 'No bank named yet'
}

const dueBadge = useDueBadge()

// Red while it owes; a period paid beyond its charges is in credit and reads green.
const owedClass = period =>
  String(period.owed).startsWith('-') ? 'text-positive' : 'text-negative'

const covers = period => {
  const from = period.first_charge_date
  const to = period.last_charge_date

  if (!from) return ''

  return from === to ? formatDate(from) : `${formatDate(from)} – ${formatDate(to)}`
}

const pending = period => period.pending_count > 0

// Pending only: a card with no bank is what the dialog's picker is for.
const settleable = (group, period) => !pending(period)

const blockedReason = (group, period) => {
  if (pending(period)) {
    return `${period.pending_count} row${period.pending_count === 1 ? '' : 's'} not yet posted, so the total is not final`
  }

  return ''
}

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

// Passed to show() too: `chosen` reaches the dialog as props, which lag this tick.
const openSettle = (group, period) => {
  const bank = props.banks[group.card.id] ?? null

  chosen.value = { group, period, bank }
  dialog.value?.show(period, bank, group)
}

const openCorrect = (group, period) => {
  chosen.value = { group, period }
  dueDialog.value?.show(period)
}
</script>
