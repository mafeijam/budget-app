<template>
  <div class="column q-gutter-md">
    <FormAccount />

    <AppTable :rows="data.data" :columns="columns" title="Account">
      <template #top>
        <div class="row full-width">
          <div class="text-h6 text-weight-medium">Accounts</div>
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

  // Account id => a decimal string, computed server-side for the page. Declared
  // rather than read off the page props because the column is built in this
  // script, where only a declared prop is in scope.
  balances: {
    type: Object,
    default: () => ({}),
  },
})

const pagination = usePagination()
const formatDate = useHongKongTime()

// Absent rather than null: an account with no meta row at all hydrates to null,
// and Object.entries on that is a crash rather than an empty column. Back to
// null once the last key is dropped, so an account with nothing stored still
// reads as having nothing rather than as an empty object.
const stripNulls = meta => {
  const kept = Object.fromEntries(Object.entries(meta ?? {}).filter(([, value]) => value !== null))

  return Object.keys(kept).length ? kept : null
}

const columns = reactive([
  {
    name: 'name',
    label: 'Name',
    field: 'name',
    align: 'left',
    sortable: true,
  },
  {
    name: 'type',
    label: 'Type',
    field: 'type',
    align: 'left',
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
    name: 'balance',
    label: 'Balance',
    align: 'right',
    // Not sortable: the list orders by whatever `sort` says against the accounts
    // table, and a balance is a sum rather than a column on it. Blank for a
    // securities account, which has none -- a brokerage holds positions, and
    // '0.0000' there would read as an amount of money it does not hold.
    field: row => props.balances[row.id] ?? '',
    // Two places, rounded here rather than in the query. The amount column is
    // decimal(12,4) and the sum of it is exact at four, so a tenth of a cent is a
    // real figure the server holds and this column chooses not to show. Rounding
    // the query instead would lose it for every reader of the map.
    format: val => (val === '' ? '' : Number(val).toFixed(2)),
    sortable: false,
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
    // The nulls dropped, because meta_data arrives as the DTO rather than as
    // what is stored: every declared key is present whether or not this account
    // uses it, so a card would read {"term_days":15,"statement_day":25,
    // "settlement_account_id":null} and a brokerage would carry two card fields
    // it has no use for. Which keys appear is the whole content of this column.
    //
    // '' rather than JSON.stringify(stripNulls(...)), because stringify(null) is
    // the four-character string "null" and an account with no bag at all -- a
    // plain cash account -- would read as though it carried a value called null.
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
