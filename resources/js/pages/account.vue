<template>
  <div class="column no-wrap q-gutter-md">
    <FormAccount />

    <div>
      <div class="row items-center">
        <div class="text-h6 text-weight-medium q-mr-md">Accounts</div>
        <q-space />
        <div class="row items-center q-gutter-sm no-wrap">
          <div v-if="hiddenCount" class="app-toolbar row items-center no-wrap">
            <q-toggle
              v-model="showClosed"
              :label="`Show closed (${hiddenCount})`"
              color="primary"
              dense
              class="q-px-sm"
            />
          </div>
          <CreateBtn label="New account" />
        </div>
      </div>
      <div class="text-caption text-grey-7 q-mt-xs">
        Net worth {{ base }} {{ money(summary.net_worth) }}, cash and stocks only. Click an account
        for its transactions.
      </div>
    </div>

    <div
      v-for="code in summary.unconverted ?? []"
      :key="code"
      class="app-note app-note--warning row no-wrap"
    >
      <q-icon name="warning_amber" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
      <div>No {{ code }} rate for today, so {{ code }} accounts are left out of the totals.</div>
    </div>

    <q-card v-for="section in sections" :key="section.type" flat bordered>
      <q-card-section class="row items-center no-wrap q-py-sm">
        <q-icon :name="typeIcons[section.type]" size="sm" color="grey-7" class="q-mr-sm" />
        <div class="text-subtitle1 text-weight-medium">{{ typeTitles[section.type] }}</div>
        <div class="text-caption text-grey-6 q-ml-sm">{{ section.accounts.length }}</div>
        <q-space />
        <div class="text-right">
          <div
            class="money text-weight-bold"
            :class="{ 'text-negative': String(section.total).startsWith('-') }"
          >
            {{ base }} {{ money(section.total) }}
          </div>
          <div
            v-if="section.type === 'security'"
            class="text-caption money"
            :class="signClass(summary.unrealised)"
          >
            {{ signed(summary.unrealised) }} unrealised
          </div>
        </div>
      </q-card-section>

      <q-separator />

      <div
        v-for="account in section.accounts"
        :key="account.id"
        class="app-account-row cursor-pointer"
        :class="{ 'app-account-row--closed': account.status !== 'active' }"
        @click="openTransactions(account)"
      >
        <div class="app-account-row__name">
          <div class="row items-center no-wrap">
            <span class="text-weight-medium text-grey-9 ellipsis">{{ account.name }}</span>
            <q-badge
              v-if="account.status !== 'active'"
              class="app-tint app-tint--muted q-ml-sm"
              :label="account.status"
            />
          </div>
          <div class="text-caption text-grey-6 ellipsis">
            {{ [typeLabels[account.type], account.ccy, ...details(account)].join(' · ') }}
          </div>
        </div>

        <!-- A security's value, cost and profit; a card's balance and its next bill. -->
        <div class="app-account-row__figures">
          <template v-if="account.type === 'security'">
            <template v-if="brokerage(account)">
              <div class="money text-weight-medium">
                {{ account.ccy }} {{ money(brokerage(account).value) }}
              </div>
              <div
                v-if="account.ccy !== base && brokerage(account).value_base !== null"
                class="text-caption text-grey-6 money"
              >
                ≈ {{ base }} {{ money(brokerage(account).value_base) }}
              </div>
              <div class="text-caption text-grey-6 money">
                cost {{ money(brokerage(account).cost) }} ·
                <span :class="signClass(brokerage(account).unrealised)">
                  {{ signed(brokerage(account).unrealised) }}{{ percentOf(brokerage(account)) }}
                </span>
              </div>
              <div v-if="marketValues[account.id]?.unpriced" class="text-caption app-text-estimate">
                {{ marketValues[account.id].unpriced }} unpriced, counted at cost
              </div>
            </template>
            <div v-else class="text-caption text-grey-6">No holdings</div>
          </template>

          <template v-else>
            <div
              class="money text-weight-medium"
              :class="{ 'text-negative': String(balances[account.id] ?? '').startsWith('-') }"
            >
              {{ account.ccy }} {{ money(balances[account.id] ?? '0') }}
            </div>
            <div v-if="inBase(account) !== null" class="text-caption text-grey-6 money">
              ≈ {{ base }} {{ money(inBase(account)) }}
            </div>

            <template v-if="account.type === 'card'">
              <div v-if="statements[account.id]" class="row items-center justify-end q-gutter-x-xs">
                <span class="text-caption text-grey-8 money">
                  {{ money(statements[account.id].owed) }} due
                  {{ formatDay(statements[account.id].due_date) }}
                </span>
                <q-badge v-bind="dueBadge(statements[account.id])" />
                <q-badge
                  v-if="statements[account.id].pending_count"
                  class="app-tint app-tint--warning"
                  :label="`${statements[account.id].pending_count} pending`"
                />
                <q-badge
                  v-if="statements[account.id].more"
                  class="app-tint app-tint--muted"
                  :label="`+${statements[account.id].more} more`"
                />
              </div>
              <div v-else class="text-caption text-grey-6">Nothing due</div>
            </template>
          </template>
        </div>

        <div class="app-account-row__trend">
          <HomeSpark
            v-if="hasTrend(account)"
            :values="trends[account.id]"
            :colour="typeColours[account.type]"
            :label="`${account.name} over the last 12 months`"
            class="app-account-spark"
          />
        </div>

        <!-- Stopped here, so an edit or a delete does not also open the transactions. -->
        <div class="app-account-row__actions row items-center justify-end no-wrap" @click.stop>
          <AppTableActions :cell="{ row: account }" />
        </div>
      </div>
    </q-card>

    <q-card v-if="!sections.length" flat bordered class="q-pa-lg text-center text-grey-7">
      No accounts yet.
    </q-card>
  </div>
</template>

<script setup>
const props = defineProps({
  ...hasTableProps,

  // Account id => balance in its own currency; a brokerage has none.
  balances: { type: Object, default: () => ({}) },

  // Brokerage id => {market_value, unpriced, open}, for the unpriced count.
  marketValues: { type: Object, default: () => ({}) },

  // NetWorth's snapshot for today, so the subtotals are the Net worth page's figures.
  summary: { type: Object, default: () => ({ accounts: [], brokerages: [] }) },

  // Account id => its figure at the last 12 month ends and today, oldest first.
  trends: { type: Object, default: () => ({}) },

  // Card id => its earliest statement with something owed.
  statements: { type: Object, default: () => ({}) },

  // Account id => the date of its latest transaction.
  lastUsed: { type: Object, default: () => ({}) },

  typeOptions: { type: Array, default: () => ['cash', 'card', 'security'] },
  base: { type: String, default: 'HKD' },
})

const pagination = usePagination()
provide('pagination', pagination)

const money = useMoney()
const formatDay = useCalendarDay()
const dueBadge = useDueBadge()

const base = computed(() => props.base)

const typeIcons = { cash: 'account_balance', card: 'credit_card', security: 'show_chart' }
const typeTitles = { cash: 'Cash', card: 'Cards', security: 'Securities' }
const typeLabels = { cash: 'Cash', card: 'Card', security: 'Securities' }
const typeColours = { cash: '#059669', card: '#dc2626', security: '#2563eb' }

// The snapshot's own keys per type, so a subtotal is the Net worth card beside it.
const totalKeys = { cash: 'cash', card: 'cards', security: 'value' }

const showClosed = ref(false)

const brokerage = account => props.summary.brokerages?.find(row => row.id === account.id) ?? null

const inBase = account => {
  if (account.ccy === props.base) return null

  return props.summary.accounts?.find(row => row.id === account.id)?.base ?? null
}

const isZero = value => /^-?0*(\.0*)?$/.test(String(value ?? '0'))

// A closed account still holding money is left in view: hidden, it would be missing from
// the list while its money stayed in the subtotal above it.
const isEmpty = account =>
  account.type === 'security'
    ? !brokerage(account)
    : isZero(props.balances[account.id]) && !props.statements[account.id]

const hidden = account => account.status !== 'active' && isEmpty(account)

const hiddenCount = computed(() => props.data.data.filter(hidden).length)

// typeOptions is AccountType::cases(), so a new type gets a section rather than vanishing.
// Cards only: those with a bill first, the soonest due at the top, then the ones with
// nothing due, the most recently used first. Every other section keeps its name order.
const byBill = accounts =>
  [...accounts].sort((a, b) => {
    const [x, y] = [props.statements[a.id]?.due_date, props.statements[b.id]?.due_date]

    if (x && y) return x.localeCompare(y)
    if (x || y) return x ? -1 : 1

    return (props.lastUsed[b.id] ?? '').localeCompare(props.lastUsed[a.id] ?? '')
  })

const sections = computed(() =>
  props.typeOptions
    .map(type => ({
      type,
      total: props.summary[totalKeys[type]] ?? '0',
      accounts: (type === 'card' ? byBill : list => list)(
        props.data.data.filter(
          account => account.type === type && (showClosed.value || !hidden(account)),
        ),
      ),
    }))
    .filter(section => section.accounts.length),
)

const hasTrend = account => (props.trends[account.id] ?? []).some(value => !isZero(value))

const signClass = value =>
  isZero(value) ? '' : String(value).startsWith('-') ? 'text-negative' : 'text-positive'

const signed = value =>
  `${!isZero(value) && !String(value).startsWith('-') ? '+' : ''}${money(value)}`

// A share for reading, not money, so a float is fine.
const percentOf = row =>
  Number(row.cost) === 0
    ? ''
    : ` (${((Number(row.unrealised) / Math.abs(Number(row.cost))) * 100).toFixed(1)}%)`

// From settlementOptions, so the name is the one the form's picker shows.
const accountName = id =>
  usePage()
    .props.settlementOptions?.find(option => option.value === id)
    ?.label?.replace(/ \([A-Z]{3}\)$/, '') ?? `#${id}`

const ordinal = day => {
  const tens = day % 100

  if (tens >= 11 && tens <= 13) return `${day}th`

  return `${day}${{ 1: 'st', 2: 'nd', 3: 'rd' }[day % 10] ?? 'th'}`
}

// Nulls dropped: meta_data arrives as the DTO, carrying every account type's keys.
const details = account => {
  const meta = account.meta_data ?? {}
  const parts = []

  if (meta.statement_day) parts.push(`closes ${ordinal(meta.statement_day)}`)
  if (meta.term_days) parts.push(`${meta.term_days} days to pay`)

  if (meta.settlement_account_id) {
    const verb = account.type === 'card' ? 'from' : 'into'

    parts.push(`${verb} ${accountName(meta.settlement_account_id)}`)
  }

  // A card is the account most often left idle, so when it was last used is worth saying.
  if (account.type === 'card') {
    const last = props.lastUsed[account.id]

    parts.push(last ? `last used ${formatDay(last)}` : 'never used')
  }

  return parts
}

const openTransactions = account =>
  router.visit('/transactions', { data: { filter: { account_id: account.id } } })
</script>
