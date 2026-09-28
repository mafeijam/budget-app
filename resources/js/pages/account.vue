<template>
  <div class="column no-wrap q-gutter-md">
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

      <template #body-cell-type="cell">
        <q-td :props="cell">
          <q-icon :name="typeIcons[cell.value] ?? 'help_outline'" size="xs" class="q-mr-xs" />
          {{ cell.value }}
        </q-td>
      </template>

      <!--
        A brokerage has no cash balance, so its cell is the market value of what it
        holds, captioned as such so the figure is not read as money in an account.
      -->
      <template #body-cell-balance="cell">
        <q-td :props="cell" class="money" :class="cell.col.classes?.(cell.row)">
          {{ cell.value }}
          <div v-if="marketValue(cell.row) !== null" class="text-caption text-grey-6">
            market value{{
              marketValues[cell.row.id].unpriced
                ? ` · ${marketValues[cell.row.id].unpriced} unpriced`
                : ''
            }}
          </div>
        </q-td>
      </template>

      <template #body-cell-status="cell">
        <q-td :props="cell">
          <q-badge
            :class="
              cell.value === 'active' ? 'app-tint app-tint--positive' : 'app-tint app-tint--muted'
            "
            :label="cell.value"
          />
        </q-td>
      </template>

      <template #body-cell-metaData="cell">
        <q-td :props="cell">
          <!-- Badges, for the size reason given in the transaction table's meta column. -->
          <q-badge
            v-for="label in metaLabels(cell.row)"
            :key="label"
            class="app-tint app-tint--muted q-mr-xs"
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

  // Account id => a decimal string, computed server-side. Declared rather than read
  // off the page props because the column is built here, where only a declared prop is
  // in scope.
  balances: {
    type: Object,
    default: () => ({}),
  },

  // Brokerage id => {market_value, unpriced, open}, for the balance a brokerage lacks.
  marketValues: {
    type: Object,
    default: () => ({}),
  },
})

const pagination = usePagination()
const formatDate = useHongKongTime()

const formatMoney = useMoney()

// A brokerage holding nothing shows nothing, as before; one holding shares shows their
// worth, zero included when none of them has a price yet.
const marketValue = row => {
  const valued = props.marketValues[row.id]

  return valued?.open ? valued.market_value : null
}

const typeIcons = { cash: 'account_balance', card: 'credit_card', security: 'show_chart' }

// The cash account a card is paid from or a brokerage settles into, by name. From
// settlementOptions, which lists every cash account, so a link to one on another page
// of this table still resolves.
const accountLabel = id =>
  usePage().props.settlementOptions?.find(option => option.value === id)?.label ?? `#${id}`

const ordinal = day => {
  const tens = day % 100

  if (tens >= 11 && tens <= 13) return `${day}th`

  return `${day}${{ 1: 'st', 2: 'nd', 3: 'rd' }[day % 10] ?? 'th'}`
}

// The bag in words, one chip per fact, rather than the JSON it is stored as. Nulls
// dropped, because meta_data arrives as the DTO and a card would otherwise carry every
// key the other types use. A key this does not know falls through as `key: value`, so
// a field added to the bag shows up rough rather than not at all.
const metaLabels = row => {
  const meta = row.meta_data ?? {}
  const labels = []

  if (meta.statement_day) labels.push(`Closes on the ${ordinal(meta.statement_day)}`)
  if (meta.term_days) labels.push(`${meta.term_days} days to pay`)

  if (meta.settlement_account_id) {
    const verb = row.type === 'card' ? 'Paid from' : 'Settles into'

    labels.push(`${verb} ${accountLabel(meta.settlement_account_id)}`)
  }

  const known = ['statement_day', 'term_days', 'settlement_account_id']

  Object.entries(meta)
    .filter(([key, value]) => value !== null && !known.includes(key))
    .forEach(([key, value]) => labels.push(`${key}: ${value}`))

  return labels
}

const columns = reactive([
  {
    name: 'name',
    width: '180px',
    label: 'Name',
    field: 'name',
    align: 'left',
    classes: 'text-weight-medium text-grey-9',
    sortable: true,
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
    name: 'ccy',
    width: '80px',
    label: 'CCY',
    field: 'ccy',
    align: 'left',
    sortable: true,
  },
  {
    name: 'balance',
    width: '150px',
    label: 'Balance',
    align: 'right',
    // Not sortable: the list orders against the accounts table and a balance is a sum
    // rather than a column on it. Blank for a securities account, which has none -- a
    // brokerage holds positions, and '0.0000' there would read as money it does not hold.
    field: row => props.balances[row.id] ?? marketValue(row) ?? '',
    // Two places, rounded here rather than in the query: decimal(12,4) sums exactly at
    // four, so a tenth of a cent is a real figure the server holds and this column
    // chooses not to show. Rounded as digits, never through Number() -- see money.js.
    format: formatMoney,
    // Red when negative, which on a card is what it owes. A position rather than a
    // direction of travel, so a card paid beyond its charges reads black.
    classes: row => (String(props.balances[row.id] ?? '').startsWith('-') ? 'text-negative' : ''),
    sortable: false,
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
    width: '380px',
    label: 'Details',
    // Rendered by the body-cell-metaData slot above; the field is the same words
    // joined, for the table to sort and filter on.
    field: row => metaLabels(row).join(', '),
    align: 'left',
    sortable: false,
  },
  {
    name: 'created_at',
    width: '170px',
    label: 'Created At',
    field: 'created_at',
    format: val => formatDate(val),
    sortable: true,
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
