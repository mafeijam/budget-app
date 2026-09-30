<template>
  <q-card v-if="groups.length" flat bordered>
    <q-card-section class="row items-center q-pb-sm">
      <q-icon name="credit_card" size="sm" color="grey-7" class="q-mr-sm" />
      <div class="text-h6 text-weight-medium">Card statements</div>
      <div class="text-caption text-grey-6 q-ml-sm">{{ tiles.length }} still owing</div>
      <q-space />
      <!-- The tiles cut to what is paid from them, remembered per browser. -->
      <q-btn
        flat
        dense
        no-caps
        color="grey-8"
        :icon="compact ? 'unfold_more' : 'unfold_less'"
        :label="compact ? 'Expand' : 'Compact'"
        @click="compact = !compact"
      >
        <q-tooltip :delay="500" :offset="[0, 6]">
          {{
            compact ? 'Show each statement in full' : 'Only the card, when, how much, and Settle'
          }}
        </q-tooltip>
      </q-btn>
    </q-card-section>

    <!-- A tile per statement still owing, across every card, soonest due first. -->
    <q-card-section class="q-pt-none">
      <div class="app-statement-tiles" :class="{ 'app-statement-tiles--compact': compact }">
        <div
          v-for="{ group, period } in tiles"
          :key="`${group.card.id}-${period.due_date}`"
          class="app-statement-tile"
          :class="{
            'app-statement-tile--shown': isShown(group, period),
            'app-statement-tile--late': period.days_until_due < 0,
          }"
        >
          <!-- Compact: the card and its due day, what it owes, and Settle. The full tile
               below is untouched, only not drawn. -->
          <!-- The tile itself filters to its statement, as the full tile's Transactions
               does; Settle is stopped so paying does not also filter. -->
          <div
            v-if="compact"
            class="row items-center no-wrap cursor-pointer"
            @click="emit('filter', { cardId: group.card.id, dueDate: period.due_date })"
          >
            <div class="col" style="min-width: 0">
              <div class="text-subtitle2 text-weight-bold text-grey-9 ellipsis">
                {{ group.card.name }}
              </div>
              <div class="text-caption text-grey-7">
                due <span class="text-weight-bold">{{ formatDate(period.due_date) }}</span>
              </div>
            </div>
            <div class="text-subtitle1 text-weight-bold money q-mx-sm" :class="owedClass(period)">
              {{ money(period.owed) }}
            </div>
            <!-- The icon alone here: the tooltip names it, and the row has little room. -->
            <q-btn
              unelevated
              dense
              size="sm"
              padding="4px 6px"
              icon="payments"
              class="app-btn app-btn--positive"
              :aria-label="`Settle ${group.card.name}`"
              :disable="!settleable(group, period)"
              @click.stop="openSettle(group, period)"
            >
              <q-tooltip :delay="500" :offset="[0, 6]">
                {{
                  settleable(group, period) ? 'Pay this statement' : blockedReason(group, period)
                }}
              </q-tooltip>
            </q-btn>
          </div>

          <template v-else>
            <div class="row items-center no-wrap">
              <span class="text-subtitle2 text-weight-bold text-grey-9 ellipsis">
                {{ group.card.name }}
              </span>
              <q-badge outline color="grey-7" class="q-ml-sm" :label="group.card.ccy" />
              <q-space />
              <q-badge v-bind="dueBadge(period)" />
            </div>

            <div class="text-h5 text-weight-bold money q-mt-sm" :class="owedClass(period)">
              {{ money(period.owed) }}
            </div>
            <div class="text-body2 text-grey-8">
              due <span class="text-weight-bold">{{ formatDate(period.due_date) }}</span> ·
              {{ bankLine(group) }}
            </div>

            <div class="text-caption text-grey-7 q-mt-xs money">
              {{ count(period.charge_count, 'charge') }} · {{ money(period.charged) }}
              <!-- Paid only once something is, since it is almost always nothing. -->
              <template v-if="!isZero(period.paid)">
                · {{ money(period.paid) }} paid, {{ count(period.payment_count, 'payment') }}
              </template>
            </div>
            <div class="text-caption text-grey-6">{{ covers(period) }}</div>

            <!-- A pending row means the owed total is not final yet. -->
            <q-badge
              v-if="period.pending_count"
              class="q-mt-xs app-tint app-tint--warning"
              :label="`${period.pending_count} not yet posted`"
            />

            <div class="app-statement-tile__spacer" />

            <!-- Every action on the statement, labelled, along the tile's foot. -->
            <div class="row items-center no-wrap app-statement-tile__actions">
              <q-btn
                flat
                dense
                no-caps
                :color="isShown(group, period) ? 'primary' : 'grey-8'"
                icon="filter_list"
                :label="isShown(group, period) ? 'Showing' : 'Transactions'"
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
                flat
                dense
                round
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
              <q-space />
              <q-btn
                unelevated
                dense
                no-caps
                icon="payments"
                label="Settle"
                class="app-btn app-btn--positive text-weight-bold q-px-sm"
                :disable="!settleable(group, period)"
                @click="openSettle(group, period)"
              >
                <q-tooltip :delay="500" :offset="[0, 6]">
                  {{
                    settleable(group, period) ? 'Pay this statement' : blockedReason(group, period)
                  }}
                </q-tooltip>
              </q-btn>
            </div>
          </template>
        </div>
      </div>
    </q-card-section>

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

const compact = useStorage('transactions.statementsCompact', false)

// Every card's statements in one run, soonest due first, each tile keeping its card.
const tiles = computed(() =>
  props.groups
    .flatMap(group => group.periods.map(period => ({ group, period })))
    .sort((a, b) => a.period.due_date.localeCompare(b.period.due_date)),
)

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
