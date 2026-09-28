<template>
  <div class="row q-col-gutter-sm full-width q-mt-sm items-center">
    <q-input
      v-model="filters.description"
      class="col-12 col-md-3"
      label="Search description"
      dense
      filled
      clearable
      debounce="300"
    >
      <template #prepend>
        <q-icon name="search" />
      </template>
    </q-input>

    <q-select
      v-model="filters.account_id"
      :options="accountOptions"
      class="col-6 col-md-2"
      label="Account"
      dense
      filled
      clearable
      emit-value
      map-options
    />

    <q-select
      v-model="filters.type"
      :options="typeOptions"
      class="col-6 col-md-2"
      label="Type"
      dense
      filled
      multiple
      clearable
    />

    <q-select
      v-model="filters.status"
      :options="statusOptions"
      class="col-6 col-md-1"
      label="Status"
      dense
      filled
      multiple
      clearable
    />

    <q-select
      v-model="filters.category_id"
      :options="categoryOptions"
      class="col-6 col-md-2"
      label="Category"
      dense
      filled
      clearable
      emit-value
      map-options
    />

    <!--
      A read-only field showing the range, with the calendar in a menu, as the forms do.
      The mask is on the q-date: a q-input mask is a different parser whose only token is
      #, and would take no keystroke at all.
    -->
    <q-input
      :model-value="rangeLabel"
      class="col-12 col-md-2"
      label="Date"
      dense
      filled
      readonly
      clearable
      @clear="range = null"
    >
      <template #append>
        <q-btn flat dense round icon="event">
          <q-menu :offset="[10, 15]" anchor="bottom right" self="top right">
            <q-date v-model="range" range mask="YYYY-MM-DD" minimal color="green-7" />
          </q-menu>
        </q-btn>
      </template>
    </q-input>
  </div>
</template>

<script setup>
const page = usePage()
const pagination = inject('pagination')

const accountOptions = computed(() => page.props.filterOptions?.accounts ?? [])
const typeOptions = computed(() => page.props.filterOptions?.types ?? [])
const statusOptions = computed(() => page.props.statusOptions ?? [])
const categoryOptions = computed(() => page.props.options?.categories ?? [])

// A comma-separated list in the URL, since that is what the server's exact filter
// splits on; an array here, which is what a multiple select binds.
const list = value => (value ? String(value).split(',') : [])
const id = value => (value ? Number(value) : null)

// Seeded from the URL the server echoes back as params, so a reload or a shared link
// opens on the same filter it was taken with.
const seeded = page.props.params?.filter ?? {}

const filters = reactive({
  description: seeded.description ?? null,
  account_id: id(seeded.account_id),
  type: list(seeded.type),
  status: list(seeded.status),
  category_id: id(seeded.category_id),
  date_from: seeded.date_from ?? null,
  date_to: seeded.date_to ?? null,
})

// q-date's range model is a string for a single day and {from, to} for a span; the
// server takes the two ends separately. One day is a range from it to itself.
const range = computed({
  get: () => {
    if (!filters.date_from) return null

    return filters.date_from === filters.date_to
      ? filters.date_from
      : { from: filters.date_from, to: filters.date_to }
  },
  set: value => {
    filters.date_from = typeof value === 'string' ? value : (value?.from ?? null)
    filters.date_to = typeof value === 'string' ? value : (value?.to ?? null)
  },
})

const rangeLabel = computed(() => {
  if (!filters.date_from) return ''

  return filters.date_from === filters.date_to
    ? filters.date_from
    : `${filters.date_from} – ${filters.date_to}`
})

// Only what is set, so an empty filter is no filter at all rather than a key the
// server has to read as "match nothing".
const query = () =>
  Object.fromEntries(
    Object.entries(filters)
      .map(([key, value]) => [key, Array.isArray(value) ? value.join(',') : value])
      .filter(([, value]) => value !== null && value !== ''),
  )

const active = computed(() => Object.keys(query()).length > 0)

// Back to page one, since page three of the old result is not a page of the new one.
// The sort goes along when it is not the page's default, the way AppTable sends it.
const apply = () => {
  const { sort, dir, per_page: perPage } = page.props.params ?? {}
  const fallback = page.props.meta?.sort ?? {}
  const params = {}

  if (sort && (sort !== fallback.by || dir !== fallback.dir)) Object.assign(params, { sort, dir })
  if (perPage) params.per_page = perPage

  const filter = query()

  if (Object.keys(filter).length) params.filter = filter

  router.get(page.props.meta.path, params, {
    preserveScroll: true,
    preserveState: true,
    replace: true,
    onSuccess: resp => syncPagination(pagination, resp),
  })
}

const clear = () => {
  Object.assign(filters, {
    description: null,
    account_id: null,
    type: [],
    status: [],
    category_id: null,
    date_from: null,
    date_to: null,
  })
}

watch(filters, apply, { deep: true })

// For the Clear all button, which sits in the table's header beside Add rather than in
// this row.
defineExpose({ clear, active })
</script>
