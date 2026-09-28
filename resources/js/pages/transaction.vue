<template>
  <div class="column no-wrap q-gutter-md">
    <FormTransaction :options="options" />

    <CardStatements :groups="statements" :banks="cardBanks" />

    <AppTable :rows="data.data" :columns="columns" title="Transaction">
      <template #top>
        <TransactionFilters title="Transactions">
          <template #actions>
            <CreateBtn />
          </template>
        </TransactionFilters>
      </template>

      <!-- A tint per kind of account, so card rows and bank rows part at a glance. -->
      <template #body-cell-accountType="cell">
        <q-td :props="cell">
          <q-badge v-bind="accountTypeBadges[cell.value] ?? {}" :label="cell.value" />
        </q-td>
      </template>

      <template #body-cell-type="cell">
        <q-td :props="cell">
          <q-icon :name="typeIcons[cell.value] ?? 'help_outline'" size="xs" class="q-mr-xs" />
          {{ cell.value }}
        </q-td>
      </template>

      <!--
        The figure, signed and coloured by which way it moves the account's balance, with
        its currency beside it. A pending row is greyed, since it moves nothing yet.
      -->
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
          <!--
            Badges, not chips: q-chip--dense is 1.5em of its own 14px -- 21px -- against
            the 16px of q-badge, so a chip here reads as a different kind of thing from
            the badges either side of it. q-mr-xs is the gap the chip's 4px margin gave.
          -->
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
const props = defineProps({
  ...hasTableProps,
  // Not part of hasTableProps, and its absence is silent: the form reads
  // props.options.accounts, so without this the select renders with no options at all.
  options: { type: Object, default: Object },
  // What each card still owes, period by period. Outstanding periods only -- a card
  // with nothing due gets no heading, and the payments that closed the rest are in the
  // table below.
  statements: { type: Array, default: Array },
  // Which bank each card is paid from, keyed by card id, so the settle dialog can say
  // where the money leaves before the user commits.
  cardBanks: { type: Object, default: () => ({}) },
})

const pagination = usePagination()
const formatMoney = useMoney()

// Light fills in three distinct hues, the status badges' style, so the type reads by
// colour and does not compete with the Type column's icons beside it.
//
// Left on Quasar's own ramp rather than moved onto the brand like the status badges
// below, and that is the point of them: these three hues mean nothing in particular. They
// are here so a card row parts from a bank row at a glance, and drawing them from the
// brand would tie three arbitrary hues to one palette and make them look like meaning
// they do not carry.
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
}

// Posted and not yet posted are the brand's positive and warning, which is a real
// mapping rather than two colours that happen to differ: green-1/green-9 sat next to
// text-positive on the same row, and the two greens were visibly not the same green. A
// class, because Quasar's ramp has no entry for a brand colour.
const statusBadges = {
  posted: { class: 'app-tint app-tint--positive' },
  pending: { class: 'app-tint app-tint--warning' },
}

// 1, -1 or 0 from the server's movesBalanceOn(). Zero for a trade, which moves no
// balance, and for a row the server did not send one for.
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

// The bag in words, one chip per fact, rather than the JSON it is stored as, coloured
// by kind so the settled rows stand out. Keys this does not know fall through as
// `key: value`, so a field added to the bag shows up rough rather than not at all.
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
    // The other half, as the delete confirmation names it. Absent when it is gone.
    const other = usePage().props.linked?.[row.id]

    chips.push({
      label: other ? `Settles with ${other.account_name}` : 'Settlement, other half gone',
      class: 'app-tint app-tint--info',
    })
  }

  if (meta.symbol) {
    const fees = meta.fees ? `, fees ${meta.fees}` : ''

    plain(`${meta.symbol} ${meta.quantity} @ ${meta.unit_price}${fees}`)
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
    width: '130px',
    label: 'Account',
    // Whose money the row is. Without it a table of cash, card and trade rows gives a
    // number in the corner and nothing else to tell them apart.
    //
    // Not sortable, and not lazily so: the list orders against the transactions table
    // and account_name is not a column on it, so this means teaching the query to sort
    // through the relation.
    field: 'account_name',
    align: 'left',
    classes: 'text-weight-medium text-grey-9',
    sortable: false,
  },
  {
    name: 'accountType',
    width: '110px',
    label: 'Account type',
    // The account's, not the row's: not sortable, since the list orders against the
    // transactions table and this is a column on accounts.
    field: 'account_type',
    align: 'left',
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
    // By name, from the categories the form already receives -- every one, so a row's
    // category always resolves. Not sortable: the list orders against the transactions
    // table, and sorting by category_id would order by when a category was created.
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
    // Money arrives as a string precisely so a float never rounds it on the way here.
    // Rendered by the body-cell-amount slot, with the currency beside it rather than in
    // a column of its own.
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
    // Rendered by the body-cell-metaData slot above. The field is what the table sorts
    // and filters on, so it is the same words joined.
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
