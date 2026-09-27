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

// The same shape as the account list's Meta column: meta_data arrives as the DTO, so
// every declared key is present whether or not this row uses it. Back to null rather
// than {} so a row with nothing stored reads as having nothing.
const stripNulls = meta => {
  const kept = Object.fromEntries(Object.entries(meta ?? {}).filter(([, value]) => value !== null))

  return Object.keys(kept).length ? kept : null
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
    label: 'Meta',
    // Which keys appear is the whole content of this column: a card charge shows the
    // due date the server derived, or the card-currency figure it was given; a cash
    // expense shows nothing.
    //
    // '' rather than JSON.stringify(stripNulls(...)), because stringify(null) is the
    // four-character string "null" and the cell would read as though the row carried a
    // value called null.
    field: val => {
      const kept = stripNulls(val.meta_data)

      return kept ? JSON.stringify(kept) : ''
    },
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
