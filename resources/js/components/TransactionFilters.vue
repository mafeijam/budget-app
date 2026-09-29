<template>
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

    <!-- Not dense: it would stand 28px beside the search's 40px. -->
    <div class="col-auto q-mx-md">
      <q-btn
        flat
        no-caps
        color="grey-8"
        :label="chips.length ? `Filters (${chips.length})` : 'Filters'"
        :icon="rowOpen ? 'expand_less' : 'expand_more'"
        @click="rowOpen = !rowOpen"
      />
    </div>

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

  <!--
    v-if rather than v-show, so an open menu goes with the row. options-dense is separate
    from dense: dense sizes the field, not its menu.
  -->
  <div v-if="rowOpen" class="row q-col-gutter-sm full-width q-mt-xs items-center">
    <!-- The mask goes on the q-date: a q-input mask is a different parser. -->
    <q-input
      :model-value="rangeLabel"
      class="col-12 col-sm-6 col-md-3"
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
      class="col-12 col-sm-6 col-md-3"
      label="Account"
      dense
      filled
      options-dense
      multiple
      clearable
      emit-value
      map-options
      :display-value="shown(filters.account_id, accountOptions)"
    />

    <q-select
      v-model="filters.account_type"
      :options="accountTypeOptions"
      class="col-12 col-sm-6 col-md-3"
      label="Account type"
      dense
      filled
      options-dense
      multiple
      clearable
      :display-value="shown(filters.account_type)"
    />

    <q-select
      v-model="filters.type"
      :options="typeOptions"
      class="col-12 col-sm-6 col-md-3"
      label="Type"
      dense
      filled
      options-dense
      multiple
      clearable
      :display-value="shown(filters.type)"
    />

    <q-select
      v-model="filters.status"
      :options="statusOptions"
      class="col-12 col-sm-6 col-md-3"
      label="Status"
      dense
      filled
      options-dense
      multiple
      clearable
      :display-value="shown(filters.status)"
    />

    <q-select
      v-model="filters.ccy"
      :options="currencyOptions"
      class="col-12 col-sm-6 col-md-3"
      label="Currency"
      dense
      filled
      options-dense
      multiple
      clearable
      emit-value
      map-options
      :display-value="shown(filters.ccy)"
    />

    <q-select
      v-model="filters.category_id"
      :options="categoryOptions"
      class="col-12 col-sm-6 col-md-3"
      label="Category"
      dense
      filled
      options-dense
      multiple
      clearable
      emit-value
      map-options
      :display-value="shown(filters.category_id, categoryOptions)"
    />

    <!--
      add-unique lets a fragment through, which the server searches as a phrase, so "700"
      finds 0700.HK. input-debounce because on a QSelect it is the model update that waits.
    -->
    <q-select
      v-model="filters.symbol"
      :options="shownSymbols"
      class="col-12 col-sm-6 col-md-3"
      label="Symbol"
      filled
      dense
      options-dense
      autocomplete="off"
      use-input
      input-debounce="300"
      new-value-mode="add-unique"
      multiple
      clearable
      @filter="filterSymbols"
    >
      <template #no-option>
        <q-item dense>
          <q-item-section class="text-grey"> Nothing matches; Enter adds it </q-item-section>
        </q-item>
      </template>
    </q-select>
  </div>

  <!-- Below the bar rather than in it, so nothing there moves when a filter is set. -->
  <div v-if="shownChips.length" class="row full-width items-center q-mt-sm">
    <q-chip
      v-for="chip in shownChips"
      :key="chip.key"
      dense
      removable
      square
      class="app-tint app-tint--info q-my-none q-ml-none q-mr-sm"
      :label="chip.label"
      @remove="chip.remove"
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

const symbolOptions = computed(() => page.props.filterOptions?.symbols ?? [])

const shownSymbols = ref([])

const filterSymbols = filterInto(shownSymbols, symbolOptions, (symbol, needle) =>
  symbol.toLowerCase().includes(needle),
)
const statusOptions = computed(() => page.props.statusOptions ?? [])
const categoryOptions = computed(() => page.props.options?.categories ?? [])
const currencyOptions = computed(() => page.props.currencyOptions ?? [])

const list = value => (value ? String(value).split(',') : [])
const ids = value => list(value).map(Number)

const shown = (chosen, options = null) => {
  if (!chosen?.length) return ''

  if (chosen.length > 1) return `${chosen.length} selected`

  return options?.find(option => option.value === chosen[0])?.label ?? String(chosen[0])
}

const seeded = page.props.params?.filter ?? {}

// Open on arrival only when the URL carries a filter, so a followed link shows what applied.
const rowOpen = ref(Object.keys(seeded).length > 0)

const filters = reactive({
  description: seeded.description ?? null,
  account_id: ids(seeded.account_id),
  account_type: list(seeded.account_type),
  type: list(seeded.type),
  status: list(seeded.status),
  category_id: ids(seeded.category_id),
  ccy: list(seeded.ccy),
  symbol: list(seeded.symbol),
  date_from: seeded.date_from ?? null,
  date_to: seeded.date_to ?? null,
  due_date: seeded.due_date ?? null,
  // A string, as in the URL: query() drops '', so off is no filter rather than one on false.
  unpaid: seeded.unpaid ?? '',
})

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

const query = () =>
  Object.fromEntries(
    Object.entries(filters)
      .map(([key, value]) => [key, Array.isArray(value) ? value.join(',') : value])
      .filter(([, value]) => value !== null && value !== ''),
  )

const active = computed(() => Object.keys(query()).length > 0)

const chips = computed(() => {
  const picked = [
    ['account_id', 'Account', accountOptions.value],
    ['account_type', 'Account type', null],
    ['type', 'Type', null],
    ['status', 'Status', null],
    ['ccy', 'Currency', null],
    ['category_id', 'Category', categoryOptions.value],
    ['symbol', 'Symbol', null],
  ]
    .filter(([key]) => filters[key].length)
    .map(([key, name, options]) => ({
      key,
      label: `${name}: ${shown(filters[key], options)}`,
      remove: () => (filters[key] = []),
    }))

  const due = filters.due_date
    ? [
        {
          key: 'due_date',
          label: `Statement due ${filters.due_date}`,
          remove: () => (filters.due_date = null),
        },
      ]
    : []

  const dated = filters.date_from
    ? [{ key: 'date', label: `Date: ${rangeLabel.value}`, remove: () => (range.value = null) }]
    : []

  return [...due, ...dated, ...picked]
})

// The statement has no input in the panel, so its chip shows even while the panel is open.
const shownChips = computed(() =>
  rowOpen.value ? chips.value.filter(chip => chip.key === 'due_date') : chips.value,
)

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
    symbol: [],
    date_from: null,
    date_to: null,
    due_date: null,
    unpaid: '',
  })
}

// The statement panel's quick filter: that card's statement and nothing else.
const showStatement = (cardId, dueDate) => {
  clear()
  Object.assign(filters, { account_id: [cardId], due_date: dueDate })
}

defineExpose({ showStatement, clear })

watch(filters, apply, { deep: true })
</script>
