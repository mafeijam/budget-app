<template>
  <div class="column no-wrap q-gutter-md">
    <FormTransaction :options="options" />

    <CardStatements
      :groups="statements"
      :banks="cardBanks"
      :shown="shownStatement"
      @filter="filterStatement"
    />

    <AppTable :rows="data.data" :columns="columns" title="Transaction" dense>
      <template #top>
        <TransactionFilters ref="filterBar" title="Transactions">
          <template #actions>
            <CreateBtn />
          </template>
        </TransactionFilters>
      </template>

      <template #body-cell-account="cell">
        <q-td :props="cell">
          {{ cell.value }}
          <q-badge
            v-bind="accountTypeBadges[cell.row.account_type] ?? {}"
            class="q-ml-xs text-weight-regular"
            :label="cell.row.account_type"
          />
        </q-td>
      </template>

      <template #body-cell-type="cell">
        <q-td :props="cell">
          <q-icon :name="typeIcons[cell.value] ?? 'help_outline'" size="xs" class="q-mr-xs" />
          {{ cell.value }}
        </q-td>
      </template>

      <template #body-cell-amount="cell">
        <q-td :props="cell" class="money" :class="amountClass(cell.row)">
          <span class="text-weight-medium">{{ signed(cell.row) }}</span>
          <span class="text-caption text-grey-7 q-ml-xs">{{ cell.row.ccy }}</span>
        </q-td>
      </template>

      <template #body-cell-status="cell">
        <q-td :props="cell">
          <q-badge v-bind="statusBadges[cell.value] ?? {}" :label="cell.value" />
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
  </div>
</template>

<script setup>
const filterBar = ref(null)

// The statement the table is filtered to, so its row in the panel reads as selected.
const shownStatement = computed(() => {
  const filter = usePage().props.params?.filter ?? {}

  return filter.due_date ? { cardId: Number(filter.account_id), dueDate: filter.due_date } : null
})

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
    name: 'account',
    width: '200px',
    label: 'Account',
    // Not sortable: the list orders against transactions, which has no account_name.
    field: 'account_name',
    align: 'left',
    classes: 'text-weight-medium text-grey-9',
    sortable: false,
  },
  {
    name: 'type',
    width: '120px',
    label: 'Type',
    field: 'type',
    align: 'left',
    sortable: true,
  },
  {
    name: 'category',
    width: '130px',
    label: 'Category',
    field: row => props.options?.categories?.find(c => c.value === row.category_id)?.label ?? '',
    align: 'left',
    sortable: false,
  },
  {
    name: 'description',
    width: '220px',
    label: 'Description',
    field: 'description',
    align: 'left',
    classes: 'text-grey-9',
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
    name: 'status',
    width: '100px',
    label: 'Status',
    field: 'status',
    align: 'left',
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
