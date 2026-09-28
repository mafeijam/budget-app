<template>
  <q-table
    v-model:pagination="pagination"
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

// A floor for the table at the sum of the declared column widths, which is the whole
// point of declaring them: without it table-layout: fixed honours the widths only while
// they happen to fit, and the moment they do not the browser shrinks every column
// proportionally instead. Nothing reports that. The columns simply get narrower than
// they were specified to be and their contents start wrapping -- on this table the
// transaction type's icon dropped onto its own line above the word, and the two action
// buttons stacked instead of sitting side by side, both in cells that had been written
// to be wide enough. Declaring the floor makes "a narrower one scrolls" true, which is
// what the sticky action column in app.css is already built to handle.
const tableStyle = computed(() => {
  const total = props.columns.reduce((sum, column) => sum + (parseInt(column.width, 10) || 0), 0)

  // A custom property rather than min-width, because QTable applies tableStyle to the
  // .q-table__middle div and not to the table inside it. A min-width set there lands as an
  // inline style on the middle -- which is the one element that has to be allowed to
  // shrink for the sideways scroll to exist at all, and which app.css gives min-width: 0.
  // An inline min-width beats that and silently undoes the scroll. So the number is handed
  // over as a property and the table reads it.
  return { tableLayout: 'fixed', '--table-min-width': `${total}px` }
})

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

  // The page's own default order, which the server applies when the URL names none --
  // so it is left out of the URL, and clearing the sort falls back to it. Created-at
  // newest first for a page that does not say.
  const fallback = usePage().props.meta?.sort ?? { by: 'created_at', dir: 'desc' }
  const isDefault = sortBy === fallback.by && descending === (fallback.dir === 'desc')

  if (sortBy && !isDefault) {
    query.sort = sortBy
    query.dir = descending ? 'desc' : 'asc'
  }

  if (rowsPerPage !== 5) {
    query.per_page = rowsPerPage
  }

  // Whatever the page is filtered by, carried along, or turning a page would quietly
  // drop the filter and show the next page of everything.
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
