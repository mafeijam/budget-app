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
          <th class="text-right">Owes</th>
          <th />
        </tr>
      </thead>
      <tbody>
        <template v-for="group in groups" :key="group.card.id">
          <tr class="bg-grey-1">
            <th colspan="5" class="text-left q-py-sm">
              <!-- A flex row: inline, the icon, badge and caption each sat on their own line. -->
              <div class="row items-center no-wrap">
                <q-icon name="credit_card" size="xs" color="grey-7" class="q-mr-sm" />
                <span class="text-subtitle2 text-weight-medium">{{ group.card.name }}</span>
                <q-badge outline color="grey-7" class="q-ml-sm" :label="group.card.ccy" />
                <span class="text-caption text-grey-7 text-weight-regular q-ml-md">
                  {{ bankLine(group) }}
                </span>
              </div>
            </th>
          </tr>
          <tr
            v-for="period in group.periods"
            :key="`${group.card.id}-${period.due_date}`"
            class="app-statement-period"
            :class="{ 'bg-blue-1': isShown(group, period) }"
          >
            <td class="text-grey-8">{{ covers(period) }}</td>
            <td>
              <div class="row items-center no-wrap">
                <span class="text-weight-medium">{{ formatDate(period.due_date) }}</span>
                <q-badge v-bind="dueBadge(period)" class="q-ml-sm" />
                <!-- A pending row means the owed total is not final yet. -->
                <q-badge
                  v-if="period.pending_count"
                  class="q-ml-sm app-tint app-tint--warning"
                  :label="`${period.pending_count} not yet posted`"
                />
              </div>
            </td>
            <td class="text-right money">
              <span class="text-caption text-grey-6 q-mr-sm">
                {{ count(period.charge_count, 'charge') }}
              </span>
              {{ money(period.charged) }}
            </td>
            <td class="text-right money">
              <!-- Paid only once something is, since it is almost always nothing. -->
              <span v-if="!isZero(period.paid)" class="text-caption text-grey-6 q-mr-sm">
                {{ money(period.paid) }} paid · {{ count(period.payment_count, 'payment') }}
              </span>
              <span class="text-subtitle1 text-weight-bold" :class="owedClass(period)">
                {{ money(period.owed) }}
              </span>
            </td>
            <!-- Every action on the period, as icons, so the figures keep the width. -->
            <td class="text-right">
              <div class="row items-center justify-end no-wrap">
                <q-btn
                  dense
                  flat
                  round
                  size="sm"
                  :color="isShown(group, period) ? 'primary' : 'grey-7'"
                  icon="filter_list"
                  @click="emit('filter', { cardId: group.card.id, dueDate: period.due_date })"
                >
                  <q-tooltip :delay="500" :offset="[0, 6]">
                    {{
                      isShown(group, period)
                        ? 'Show every transaction'
                        : "Show this statement's transactions"
                    }}
                  </q-tooltip>
                </q-btn>
                <q-btn
                  dense
                  flat
                  round
                  size="sm"
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
                <q-btn
                  dense
                  flat
                  round
                  size="sm"
                  :color="settleable(group, period) ? 'positive' : 'grey-7'"
                  icon="payments"
                  :disable="!settleable(group, period)"
                  @click="openSettle(group, period)"
                >
                  <q-tooltip :delay="500" :offset="[0, 6]">
                    {{
                      settleable(group, period)
                        ? 'Pay this statement'
                        : blockedReason(group, period)
                    }}
                  </q-tooltip>
                </q-btn>
              </div>
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
  shown: { type: Object, default: null },
})

const emit = defineEmits(['filter'])

const isShown = (group, period) =>
  props.shown?.cardId === group.card.id && props.shown?.dueDate === period.due_date

const formatDate = useCalendarDay()

const money = useMoney()

const count = (n, noun) => `${n} ${noun}${n === 1 ? '' : 's'}`

// On the decimal string, not a float.
const isZero = value => /^-?0*(\.0*)?$/.test(String(value ?? '0'))

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
