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
})

const pagination = usePagination()
const formatDate = useHongKongTime()

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
    name: 'status',
    label: 'Status',
    field: 'status',
    align: 'left',
    sortable: true,
  },
  {
    name: 'metaData',
    label: 'Meta',
    field: val => JSON.stringify(val.meta_data),
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
