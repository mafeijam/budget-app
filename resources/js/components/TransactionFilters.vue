<template>
  <!--
    The table's whole header, since the search sits in the title row: the title, the
    search, Clear all, and whatever the page puts in #actions (its Add button).
  -->
  <div class="row full-width items-center q-col-gutter-sm">
    <div class="col-auto text-h6 text-weight-medium q-mr-md">{{ title }}</div>

    <q-input
      v-model="filters.description"
      class="col-12 col-sm"
      style="max-width: 360px"
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

    <!--
      Show and hide for the row of pickers, which is most of this header and none of the
      time. Beside the search rather than pushed to the right with Clear all, because it
      is about the row below: it is the control that says the pickers are there at all.

      Not dense, and set off with q-mx-md rather than left on the row's gutter. Dense
      would make this 28px beside the search's 40px, and the space between them would be
      whatever the gutter's 8px and the button's own 4px added up to -- the control
      butting against the field it belongs with. Margins on both sides, so the toggle
      beside it is no closer to this than this is to the search.

      A chevron rather than a label that changes, so the button does not change width and
      shift what sits to its right.

      The div is the margin, so both sides of the gap belong to one element and the
      control inside carries nothing but what it does. It is not aligned on the search's
      text line: app-align-text-line is for Clear all only, and says why.
    -->
    <div class="col-auto q-mx-md">
      <q-btn
        flat
        no-caps
        color="grey-8"
        label="Filters"
        :icon="rowOpen ? 'expand_less' : 'expand_more'"
        @click="rowOpen = !rowOpen"
      />
    </div>

    <!--
      In this row rather than the row of pickers below, which is shut until the button
      above is pressed: this is the one filter worth reaching for without opening
      anything, so leaving it down there would put it behind a button to reach it.

      A toggle rather than another picker, because the filter is a yes or a no: a select
      would offer "all" as a third value to say what leaving it alone already says. The
      two values are what keep the key out of the URL when it is off, so the filter reads
      as inactive the way the others do and Clear all clears it with the rest.
    -->
    <div class="col-auto q-mr-md">
      <q-toggle
        v-model="filters.unpaid"
        true-value="1"
        false-value=""
        label="Unpaid only"
        color="primary"
        dense
      />
    </div>

    <q-space />

    <!--
      Only when there is something to clear, and its appearing is what says the list is
      filtered -- which a button that is always there cannot say, because a permanently
      visible control reads as merely being disabled and the eye stops giving it the
      second look that would catch it being live.
    -->
    <div v-if="active" class="col-auto">
      <q-btn
        class="text-weight-bold"
        color="grey-2"
        text-color="grey-9"
        unelevated
        no-caps
        label="Clear all"
        @click="clear"
      />
    </div>

    <div class="col-auto">
      <slot name="actions" />
    </div>
  </div>

  <div class="row q-col-gutter-sm full-width q-mt-xs items-center">
    <!--
      A read-only field showing the range, with the calendar in a menu, as the forms do.
      The mask is on the q-date: a q-input mask is a different parser whose only token is
      #, and would take no keystroke at all.
    -->
    <q-input
      :model-value="rangeLabel"
      class="col-12 col-md"
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
            <q-date v-model="range" range mask="YYYY-MM-DD" minimal color="primary" />
          </q-menu>
        </q-btn>
      </template>
    </q-input>

    <q-select
      v-model="filters.account_id"
      :options="accountOptions"
      class="col-6 col-md"
      label="Account"
      dense
      filled
      multiple
      clearable
      emit-value
      map-options
      :display-value="shown(filters.account_id, accountOptions)"
    />

    <q-select
      v-model="filters.account_type"
      :options="accountTypeOptions"
      class="col-6 col-md"
      label="Account type"
      dense
      filled
      multiple
      clearable
      :display-value="shown(filters.account_type)"
    />

    <q-select
      v-model="filters.type"
      :options="typeOptions"
      class="col-6 col-md"
      label="Type"
      dense
      filled
      multiple
      clearable
      :display-value="shown(filters.type)"
    />

    <q-select
      v-model="filters.status"
      :options="statusOptions"
      class="col-6 col-md"
      label="Status"
      dense
      filled
      multiple
      clearable
      :display-value="shown(filters.status)"
    />

    <q-select
      v-model="filters.ccy"
      :options="currencyOptions"
      class="col-6 col-md"
      label="Currency"
      dense
      filled
      multiple
      clearable
      emit-value
      map-options
      :display-value="shown(filters.ccy)"
    />

    <q-select
      v-model="filters.category_id"
      :options="categoryOptions"
      class="col-6 col-md"
      label="Category"
      dense
      filled
      multiple
      clearable
      emit-value
      map-options
      :display-value="shown(filters.category_id, categoryOptions)"
    />
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, default: '' },
})

const page = usePage()
const pagination = inject('pagination')

const accountOptions = computed(() => page.props.filterOptions?.accounts ?? [])
const typeOptions = computed(() => page.props.filterOptions?.types ?? [])
const accountTypeOptions = computed(() => page.props.filterOptions?.accountTypes ?? [])
const statusOptions = computed(() => page.props.statusOptions ?? [])
const categoryOptions = computed(() => page.props.options?.categories ?? [])
const currencyOptions = computed(() => page.props.currencyOptions ?? [])

// A comma-separated list in the URL, since that is what the server's exact filter
// splits on; an array here, which is what a multiple select binds.
const list = value => (value ? String(value).split(',') : [])
const ids = value => list(value).map(Number)

// What a multiple select shows closed: the one choice by name, or a count once there
// are several, since a row of names runs out of room in a field this narrow. Options
// are {label, value} for ids and bare strings for the enums.
const shown = (chosen, options = null) => {
  if (!chosen?.length) return ''

  if (chosen.length > 1) return `${chosen.length} selected`

  return options?.find(option => option.value === chosen[0])?.label ?? String(chosen[0])
}

// Seeded from the URL the server echoes back as params, so a reload or a shared link
// opens on the same filter it was taken with.
const seeded = page.props.params?.filter ?? {}

const filters = reactive({
  description: seeded.description ?? null,
  account_id: ids(seeded.account_id),
  account_type: list(seeded.account_type),
  type: list(seeded.type),
  status: list(seeded.status),
  category_id: ids(seeded.category_id),
  ccy: list(seeded.ccy),
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
    account_id: [],
    account_type: [],
    type: [],
    status: [],
    category_id: [],
    ccy: [],
    date_from: null,
    date_to: null,
  })
}

watch(filters, apply, { deep: true })
</script>
