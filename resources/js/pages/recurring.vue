<template>
  <div class="column no-wrap q-gutter-md">
    <FormRecurring :options="options" />

    <AppTable :rows="data.data" :columns="columns" title="Recurring">
      <template #top>
        <div class="row full-width items-center">
          <div>
            <div class="text-h6 text-weight-medium">Recurring</div>
            <div class="text-caption text-grey-6">
              Each is recorded as a pending transaction on the day it falls due
            </div>
          </div>
          <q-space />
          <div>
            <CreateBtn />
          </div>
        </div>
      </template>

      <template #body-cell-next="cell">
        <q-td :props="cell">
          <template v-if="cell.row.active && cell.value">
            {{ formatDay(cell.value) }}
            <!-- The recorder stops at a refused occurrence rather than skipping it. -->
            <q-badge
              v-if="cell.value < today"
              class="app-tint app-tint--warning q-ml-xs"
              label="overdue"
            >
              <q-tooltip :delay="500" :offset="[0, 6]">
                Not recorded yet. Saving the rule again shows why.
              </q-tooltip>
            </q-badge>
          </template>
          <span v-else-if="!cell.value" class="text-grey-6">ended</span>
          <span v-else class="text-grey-6">paused</span>
        </q-td>
      </template>

      <template #body-cell-active="cell">
        <q-td :props="cell">
          <q-badge
            :class="cell.value ? 'app-tint app-tint--positive' : 'app-tint app-tint--muted'"
            :label="cell.value ? 'active' : 'paused'"
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
  options: { type: Object, default: Object },
  nextDates: { type: Object, default: Object },
})

const pagination = usePagination()
const formatDay = useCalendarDay()
const formatMoney = useMoney()

// Hong Kong's day, as the server counts it, not the browser's.
const today = computed(() =>
  new Intl.DateTimeFormat('en-CA', { timeZone: usePage().props.tz }).format(new Date()),
)

const accountName = id => props.options?.accounts?.find(a => a.value === id)?.label ?? ''

const schedule = row => {
  const [, month, day] = row.start_date.split('-')

  const repeats =
    row.frequency === 'yearly' ? `Yearly on ${month}-${day}` : `Monthly on day ${Number(day)}`

  return row.end_date ? `${repeats}, until ${formatDay(row.end_date)}` : repeats
}

const columns = reactive([
  {
    name: 'description',
    label: 'Description',
    field: 'description',
    align: 'left',
    sortable: true,
  },
  {
    name: 'account',
    label: 'Account',
    field: row => accountName(row.account_id),
    align: 'left',
  },
  {
    name: 'type',
    label: 'Type',
    field: 'type',
    align: 'left',
    sortable: true,
  },
  {
    name: 'amount',
    label: 'Amount',
    field: 'amount',
    format: (val, row) => `${formatMoney(val)} ${row.ccy}`,
    classes: 'money',
    sortable: true,
  },
  {
    name: 'start_date',
    label: 'Schedule',
    field: 'start_date',
    format: (val, row) => schedule(row),
    align: 'left',
    sortable: true,
  },
  {
    name: 'next',
    label: 'Next',
    field: row => props.nextDates?.[row.id] ?? null,
    align: 'left',
  },
  {
    name: 'active',
    label: 'Status',
    field: 'active',
    align: 'left',
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
