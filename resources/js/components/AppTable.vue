<template>
  <q-table
    v-model:pagination="pagination"
    bordered
    flat
    :rows-per-page-options="[5, 10, 20]"
    :rows="rows"
    :columns="sized"
    wrap-cells
    table-style="table-layout: fixed"
    class="text-grey-8 sticky-table"
    @request="onPageRequest"
  >
    <template v-for="(_, slot) of $slots" #[slot]="scope">
      <slot :name="slot" v-bind="scope" />
    </template>
  </q-table>
</template>

<script setup>
const props = defineProps({
  columns: {
    type: Array,
    default: () => [],
  },
  rows: {
    type: Array,
    default: () => [],
  },
})

const pagination = inject('pagination')

// Fixed layout, with each column's `width` on its header, so the columns hold still
// when the page changes. The default layout sizes every column to the rows on screen,
// and a longer description on page two moved every column after it. Widths are shares
// as much as sizes: a wider screen spreads the spare room across them, a narrower one
// scrolls. wrap-cells above, because with the width fixed, Quasar's no-wrap cells
// would run into the next column instead.
const sized = computed(() =>
  props.columns.map(column =>
    column.width
      ? {
          ...column,
          headerStyle: [column.headerStyle, `width: ${column.width}`].filter(Boolean).join('; '),
        }
      : column,
  ),
)

function getQuery(pagination) {
  const { page, rowsPerPage, sortBy, descending } = pagination

  const query = {}

  if (page > 1) {
    query.page = page
  }

  if (sortBy) {
    query.sort = sortBy
    query.dir = descending ? 'desc' : 'asc'
  } else {
    query.sort = 'created_at'
    query.dir = 'asc'
  }

  if (rowsPerPage !== 5) {
    query.per_page = rowsPerPage
  }

  if (sortBy === 'created_at' && descending === true) {
    delete query.sort
    delete query.dir
  }

  return query
}

function onPageRequest(props) {
  const page = usePage()
  const query = getQuery(props.pagination)

  router.get(page.props.data.meta.path, query, {
    preserveScroll: true,
    preserveState: true,
    replace: true,
    onSuccess: resp => syncPagination(pagination, resp),
  })
}
</script>
