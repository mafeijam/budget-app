<template>
  <div class="column q-gutter-md">
    <FormTransaction :options="options" />

    <CardStatements :groups="statements" :banks="cardBanks" />

    <AppTable :rows="data.data" :columns="columns" title="Transaction">
      <template #top>
        <div class="row full-width">
          <div class="text-h6 text-weight-medium">Transactions</div>
          <q-space />
          <div>
            <CreateBtn />
          </div>
        </div>
      </template>

      <template #body-cell-metaData="cell">
        <q-td :props="cell">
          <q-chip
            v-for="label in metaLabels(cell.row)"
            :key="label"
            dense
            square
            color="grey-2"
            text-color="grey-9"
            :label="label"
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
const formatDate = useHongKongTime()

// The bag in words, one chip per fact, rather than the JSON it is stored as. Keys this
// does not know fall through as `key: value`, so a field added to the bag shows up
// rough rather than not at all.
const metaLabels = row => {
  const meta = row.meta_data ?? {}
  const labels = []

  if (meta.due_date) labels.push(`Due ${meta.due_date}`)
  if (meta.settled_by) labels.push('Paid')
  if (meta.card_amount) labels.push(`${meta.card_amount} in the card's currency`)

  if (meta.paired_transaction_id) {
    // The other half, as the delete confirmation names it. Absent when it is gone.
    const other = usePage().props.linked?.[row.id]

    labels.push(other ? `Settles with ${other.account_name}` : 'Settlement, other half gone')
  }

  if (meta.symbol) {
    const fees = meta.fees ? `, fees ${meta.fees}` : ''

    labels.push(`${meta.symbol} ${meta.quantity} @ ${meta.unit_price}${fees}`)
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
  ]

  Object.entries(meta)
    .filter(([key, value]) => value !== null && !known.includes(key))
    .forEach(([key, value]) => labels.push(`${key}: ${value}`))

  return labels
}

const columns = reactive([
  {
    name: 'date',
    label: 'Date',
    field: 'date',
    align: 'left',
    sortable: true,
  },
  {
    name: 'account',
    label: 'Account',
    // Whose money the row is. Without it a table of cash, card and trade rows gives a
    // number in the corner and nothing else to tell them apart.
    //
    // Not sortable, and not lazily so: the list orders against the transactions table
    // and account_name is not a column on it, so this means teaching the query to sort
    // through the relation.
    field: 'account_name',
    align: 'left',
    sortable: false,
  },
  {
    name: 'type',
    label: 'Type',
    field: 'type',
    align: 'left',
    sortable: true,
  },
  {
    name: 'description',
    label: 'Description',
    field: 'description',
    align: 'left',
    sortable: true,
  },
  {
    name: 'amount',
    label: 'Amount',
    // Money arrives as a string precisely so a float never rounds it on the way here.
    // Formatted for display and nothing else.
    field: 'amount',
    align: 'right',
    sortable: true,
  },
  {
    name: 'ccy',
    label: 'CCY',
    field: 'ccy',
    align: 'left',
    sortable: true,
  },
  {
    name: 'status',
    label: 'Status',
    field: 'status',
    align: 'left',
    sortable: true,
  },
  {
    name: 'metaData',
    label: 'Details',
    // Rendered by the body-cell-metaData slot above. The field is what the table sorts
    // and filters on, so it is the same words joined.
    field: row => metaLabels(row).join(', '),
    align: 'left',
    sortable: false,
  },
  {
    name: 'created_at',
    label: 'Created At',
    field: 'created_at',
    format: val => formatDate(val),
    sortable: true,
  },
  {
    name: 'action',
    label: 'Action',
    align: 'right',
  },
])

provide('pagination', pagination)
</script>
