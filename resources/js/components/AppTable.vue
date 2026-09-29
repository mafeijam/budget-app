<template>
  <div class="relative-position">
    <!--
      Two states per column, ascending and descending, because the third -- unsorted -- is a
      request this table cannot make. getQuery writes sort only for a truthy sortBy, so null
      sends nothing, the server re-applies its default, and syncPagination puts sortBy back:
      on a column the server already orders by, and orders descending, every click was a
      no-op and the column could not be flipped at all. Date, and Created At.
    -->
    <q-table
      v-bind="$attrs"
      v-model:pagination="pagination"
      binary-state-sort
      bordered
      flat
      :rows-per-page-options="[5, 10, 20]"
      :rows="rows"
      :columns="sized"
      wrap-cells
      :table-style="tableStyle"
      class="text-grey-8 sticky-table"
      @request="onPageRequest"
    >
      <template v-for="(_, slot) of $slots" #[slot]="scope">
        <slot :name="slot" v-bind="scope" />
      </template>
    </q-table>
  </div>
</template>

<script setup>
defineOptions({ inheritAttrs: false })

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

// A floor at the sum of the column widths: without it fixed layout silently shrinks them all.
const tableStyle = computed(() => {
  const total = props.columns.reduce((sum, column) => sum + (parseInt(column.width, 10) || 0), 0)

  // A custom property, not min-width: QTable puts tableStyle on .q-table__middle, where an
  // inline min-width silently undoes the sideways scroll.
  return { tableLayout: 'fixed', '--table-min-width': `${total}px` }
})

// Fixed widths so the columns hold still between pages.
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

  const fallback = usePage().props.meta?.sort ?? { by: 'created_at', dir: 'desc' }
  const isDefault = sortBy === fallback.by && descending === (fallback.dir === 'desc')

  if (sortBy && !isDefault) {
    query.sort = sortBy
    query.dir = descending ? 'desc' : 'asc'
  }

  // Controller::PER_PAGE, which the server applies unasked.
  if (rowsPerPage !== 10) {
    query.per_page = rowsPerPage
  }

  const filter = usePage().props.params?.filter

  if (filter && Object.keys(filter).length) {
    query.filter = filter
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
