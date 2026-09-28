<template>
  <div class="column no-wrap q-gutter-md">
    <FormCategory />

    <AppTable :rows="data.data" :columns="columns" title="Category">
      <template #top>
        <div class="row full-width">
          <div class="text-h6 text-weight-medium">Categories</div>
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
    width: '300px',
    label: 'Name',
    field: 'name',
    align: 'left',
    sortable: true,
  },
  {
    name: 'created_at',
    width: '200px',
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
