<template>
  <div class="column no-wrap q-gutter-md">
    <FormTransaction :options="options" />

    <CardStatements
      :groups="statements"
      :banks="cardBanks"
      :shown="shownStatement"
      :shown-month="shownMonth"
      @filter="filterStatement"
      @filter-month="filterMonth"
    />

    <AppTable :rows="data.data" :columns="columns" title="Transaction" dense>
      <template #top>
        <TransactionFilters ref="filterBar" title="Transactions">
          <template #actions>
            <CreateBtn label="New transaction" />
          </template>
        </TransactionFilters>
      </template>

      <!-- The row in one cell: what it was, its kind, and where it is filed and held. -->
      <template #body-cell-description="cell">
        <q-td :props="cell">
          <div class="row items-center no-wrap">
            <span class="app-tx-icon q-mr-sm" :class="`app-tx-icon--${directionName(cell.row)}`">
              <q-icon :name="typeIcons[cell.row.type] ?? 'help_outline'" size="16px" />
              <q-tooltip :delay="500" :offset="[0, 6]">{{ cell.row.type }}</q-tooltip>
            </span>
            <div class="app-tx-what">
              <div class="row items-center no-wrap">
                <span class="text-weight-medium text-grey-9 ellipsis">
                  {{ cell.row.description }}
                </span>
                <!-- Only the exception is marked: almost every row is posted. -->
                <q-badge
                  v-if="cell.row.status === 'pending'"
                  class="app-tint app-tint--warning q-ml-sm"
                  label="pending"
                />
              </div>
              <div class="text-caption text-grey-6 ellipsis">
                {{ [categoryName(cell.row), cell.row.account_name].filter(Boolean).join(' · ') }}
                <q-badge
                  v-bind="accountTypeBadges[cell.row.account_type] ?? {}"
                  class="q-ml-xs text-weight-regular"
                  :label="cell.row.account_type"
                />
              </div>
            </div>
          </div>
        </q-td>
      </template>

      <template #body-cell-amount="cell">
        <q-td :props="cell" class="money" :class="amountClass(cell.row)">
          <span class="text-weight-medium">{{ signed(cell.row) }}</span>
          <span class="text-caption text-grey-7 q-ml-xs">{{ cell.row.ccy }}</span>
        </q-td>
      </template>

      <template #body-cell-metaData="cell">
        <q-td :props="cell">
          <!-- Badges, not chips: a dense chip is 21px tall against a badge's 16px. -->
          <q-badge
            v-for="chip in metaChips(cell.row)"
            :key="chip.label"
            class="q-mr-xs"
            :class="chip.class"
            :label="chip.label"
          />
        </q-td>
      </template>

      <template #body-cell-action="cell">
        <q-td :props="cell">
          <AppTableActions :cell="cell" />
        </q-td>
      </template>
    </AppTable>

    <!-- Every filtered row, not just this page, one line per currency: nothing is
         converted, so HKD and USD never add up. -->
    <q-card v-if="showTotals && totals.length" flat bordered>
      <div
        v-for="total in totals"
        :key="total.ccy"
        class="app-tx-totals"
        :class="{ 'app-tx-totals--trades': hasTrades }"
      >
        <div class="row items-center no-wrap q-gutter-x-sm">
          <q-icon name="functions" size="xs" color="grey-6" />
          <q-badge outline color="grey-7" :label="total.ccy" />
          <span class="text-caption text-grey-6">
            {{ total.count }} row{{ total.count === 1 ? '' : 's' }}, every page
          </span>
        </div>

        <!-- A column each, the same in every currency's strip, so the figures stack. -->
        <div class="app-tx-totals__figure">
          <div class="app-tx-totals__label">In</div>
          <div class="money text-body2" :class="isZero(total.in) ? 'text-grey-5' : 'text-positive'">
            {{ isZero(total.in) ? '—' : `+${formatMoney(total.in)}` }}
          </div>
        </div>
        <div class="app-tx-totals__figure">
          <div class="app-tx-totals__label">Out</div>
          <div
            class="money text-body2"
            :class="isZero(total.out) ? 'text-grey-5' : 'text-negative'"
          >
            {{ isZero(total.out) ? '—' : `−${formatMoney(total.out)}` }}
          </div>
        </div>
        <div v-if="hasTrades" class="app-tx-totals__figure">
          <div class="app-tx-totals__label">Trades</div>
          <div
            class="money text-body2"
            :class="isZero(total.trades) ? 'text-grey-5' : 'text-grey-9'"
          >
            {{ isZero(total.trades) ? '—' : formatMoney(total.trades) }}
          </div>
        </div>
        <div class="app-tx-totals__figure">
          <div class="app-tx-totals__label">Net</div>
          <div class="money text-body2 text-weight-bold" :class="netClass(total.net)">
            {{ signedNet(total.net) }}
          </div>
        </div>
      </div>
    </q-card>
  </div>
</template>

<script setup>
const filterBar = ref(null)

const totals = computed(() => usePage().props.totals ?? [])

// Off by default, and remembered per browser, as the filter panel is; the toggle is in it.
const showTotals = useStorage('transactions.totalsOpen', false)

// On the decimal string, not a float.
const isZero = value => /^-?0*(\.0*)?$/.test(String(value ?? '0'))

const signedNet = value =>
  String(value).startsWith('-') && !isZero(value)
    ? `−${formatMoney(String(value).slice(1))}`
    : `${isZero(value) ? '' : '+'}${formatMoney(value)}`

// A Trades column in every strip when any has trades, so the columns stay in line.
const hasTrades = computed(() => totals.value.some(total => !isZero(total.trades)))

const netClass = value =>
  isZero(value) ? 'text-grey-9' : String(value).startsWith('-') ? 'text-negative' : 'text-positive'

// The statement the table is filtered to, so its row in the panel reads as selected.
const shownStatement = computed(() => {
  const filter = usePage().props.params?.filter ?? {}

  return filter.due_date ? { cardId: Number(filter.account_id), dueDate: filter.due_date } : null
})

const shownMonth = computed(() => usePage().props.params?.filter?.due_month ?? null)

// A second click on the month already shown clears it, as a statement's does.
const filterMonth = month =>
  shownMonth.value === month ? filterBar.value?.clear() : filterBar.value?.showDueMonth(month)

// A second click on the statement already shown clears it.
const filterStatement = ({ cardId, dueDate }) =>
  shownStatement.value?.cardId === cardId && shownStatement.value?.dueDate === dueDate
    ? filterBar.value?.clear()
    : filterBar.value?.showStatement(cardId, dueDate)

const props = defineProps({
  ...hasTableProps,
  // Not in hasTableProps; without it the form's account select is silently empty.
  options: { type: Object, default: Object },
  statements: { type: Array, default: Array },
  cardBanks: { type: Object, default: () => ({}) },
})

const pagination = usePagination()
const formatMoney = useMoney()

// Quasar's ramp, not the brand: these hues only part card rows from bank rows.
const accountTypeBadges = {
  cash: { color: 'teal-1', textColor: 'teal-9' },
  card: { color: 'deep-purple-1', textColor: 'deep-purple-9' },
  security: { color: 'orange-1', textColor: 'orange-10' },
}

const typeIcons = {
  withdraw: 'shopping_cart',
  deposit: 'move_to_inbox',
  charge: 'credit_card',
  payment: 'task_alt',
  buy: 'trending_up',
  sell: 'trending_down',
  dividend: 'savings',
}

// A class, because Quasar's ramp has no entry for a brand colour.
const statusBadges = {
  posted: { class: 'app-tint app-tint--positive' },
  pending: { class: 'app-tint app-tint--warning' },
}

// From the server's movesBalanceOn(), since amount is always a positive magnitude.
const direction = row => usePage().props.directions?.[row.id] ?? 0

const signed = row => {
  const figure = formatMoney(row.amount)
  const sign = { 1: '+', '-1': '−' }[direction(row)] ?? ''

  return `${sign}${figure}`
}

const directionName = row => ({ 1: 'in', '-1': 'out' })[direction(row)] ?? 'none'

const categoryName = row =>
  props.options?.categories?.find(c => c.value === row.category_id)?.label ?? ''

const amountClass = row => {
  if (row.status === 'pending') return 'text-grey-6'

  return { 1: 'text-positive', '-1': 'text-negative' }[direction(row)] ?? 'text-grey-9'
}

const metaChips = row => {
  const meta = row.meta_data ?? {}
  const chips = []
  const plain = label => chips.push({ label, class: 'app-tint app-tint--muted' })

  if (meta.due_date) plain(`Due ${meta.due_date}`)
  if (meta.settled_by) chips.push({ label: 'Paid', class: 'app-tint app-tint--positive' })

  if (meta.card_amount) {
    const figure = formatMoney(meta.card_amount)

    plain(
      row.account_ccy
        ? `${figure} ${row.account_ccy} on the card`
        : `${figure} in the card's currency`,
    )
  }

  if (meta.paired_transaction_id) {
    const other = usePage().props.linked?.[row.id]

    chips.push({
      label: other ? `Settles with ${other.account_name}` : 'Settlement, other half gone',
      class: 'app-tint app-tint--info',
    })

    // The bank's half: which statement it paid, from the card's half.
    if (!meta.due_date && other?.kind === 'settlement' && other.due_date) {
      plain(`Pays statement ${other.due_date}`)
    }
  }

  if (meta.symbol) {
    if (meta.quantity) {
      const fees = meta.fees ? `, fees ${meta.fees}` : ''

      plain(`${meta.symbol} ${meta.quantity} @ ${meta.unit_price}${fees}`)
    } else {
      const brokerage = usePage().props.filterOptions?.accounts?.find(
        account => account.value === meta.brokerage_account_id,
      )

      plain(brokerage ? `${meta.symbol} from ${brokerage.label}` : meta.symbol)
    }
  }

  const known = [
    'due_date',
    'settled_by',
    'card_amount',
    'paired_transaction_id',
    'symbol',
    'quantity',
    'unit_price',
    'fees',
    'no_cash',
    'brokerage_account_id',
  ]

  Object.entries(meta)
    .filter(([key, value]) => value !== null && !known.includes(key))
    .forEach(([key, value]) => plain(`${key}: ${value}`))

  return chips
}

const columns = reactive([
  {
    name: 'date',
    width: '110px',
    label: 'Date',
    field: 'date',
    align: 'left',
    sortable: true,
  },
  {
    name: 'description',
    width: '420px',
    label: 'Description',
    field: 'description',
    align: 'left',
    sortable: true,
  },
  {
    name: 'amount',
    width: '160px',
    label: 'Amount',
    field: 'amount',
    align: 'right',
    sortable: true,
  },
  {
    name: 'metaData',
    width: '300px',
    label: 'Details',
    field: row =>
      metaChips(row)
        .map(chip => chip.label)
        .join(', '),
    align: 'left',
    sortable: false,
  },
  {
    name: 'action',
    width: '100px',
    label: 'Action',
    align: 'right',
  },
])

provide('pagination', pagination)
</script>
