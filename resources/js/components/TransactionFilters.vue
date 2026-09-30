<template>
  <div class="row full-width items-center q-col-gutter-sm">
    <div class="col-auto text-h6 text-weight-medium q-mr-md">{{ title }}</div>

    <q-input
      v-model="filters.description"
      class="col-12 col-sm app-filter-search"
      style="max-width: 380px"
      placeholder="Search description"
      dense
      outlined
      rounded
      clearable
      debounce="300"
    >
      <template #prepend>
        <q-icon name="search" />
      </template>
    </q-input>

    <!-- The two ways to narrow the list, on one surface as the other pages' controls are. -->
    <div class="col-auto q-ml-sm">
      <div class="app-toolbar row items-center no-wrap">
        <q-btn
          flat
          dense
          no-caps
          icon="tune"
          :color="rowOpen || chips.length ? 'primary' : 'grey-8'"
          class="q-px-sm text-weight-bold"
          @click="toggleRow"
        >
          <span class="q-ml-xs">Filters</span>
          <q-badge
            v-if="chips.length"
            rounded
            color="primary"
            class="q-ml-xs"
            :label="chips.length"
          />
          <q-icon :name="rowOpen ? 'expand_less' : 'expand_more'" size="xs" class="q-ml-xs" />
        </q-btn>
        <q-separator vertical inset class="q-mx-sm" />
        <q-toggle
          v-model="filters.unpaid"
          true-value="1"
          false-value=""
          label="Unpaid only"
          color="primary"
          dense
          class="q-px-sm"
        />
      </div>
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
  <div v-if="rowOpen" class="app-filter-panel row q-col-gutter-sm items-center">
    <!-- The mask goes on the q-date: a q-input mask is a different parser. -->
    <q-input
      :model-value="rangeLabel"
      class="col-12 col-sm-6 col-md-3"
      label="Date"
      dense
      outlined
      bg-color="white"
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
      outlined
      bg-color="white"
      options-dense
      multiple
      clearable
      emit-value
      map-options
      :display-value="shown(filters.account_id, accountOptions)"
    >
      <!-- Each account's type and currency, since a card and its bank can share a name. -->
      <template #option="scope">
        <q-item v-bind="scope.itemProps" dense>
          <q-item-section side>
            <q-checkbox
              :model-value="scope.selected"
              dense
              size="xs"
              @update:model-value="scope.toggleOption(scope.opt)"
            />
          </q-item-section>
          <q-item-section>{{ scope.opt.label }}</q-item-section>
          <q-item-section side class="row no-wrap items-center">
            <div class="row no-wrap items-center q-gutter-x-xs">
              <q-badge
                v-bind="accountTypeBadges[scope.opt.type] ?? {}"
                class="text-weight-regular"
                :label="scope.opt.type"
              />
              <span class="text-caption text-grey-6">{{ scope.opt.ccy }}</span>
            </div>
          </q-item-section>
        </q-item>
      </template>
      <template #prepend>
        <q-icon name="account_balance" size="xs" color="grey-6" />
      </template>
    </q-select>

    <q-select
      v-model="filters.account_type"
      :options="accountTypeOptions"
      class="col-12 col-sm-6 col-md-3"
      label="Account type"
      dense
      outlined
      bg-color="white"
      options-dense
      multiple
      clearable
      :display-value="shown(filters.account_type)"
    >
      <template #prepend>
        <q-icon name="category" size="xs" color="grey-6" />
      </template>
    </q-select>

    <q-select
      v-model="filters.type"
      :options="typeOptions"
      class="col-12 col-sm-6 col-md-3"
      label="Type"
      dense
      outlined
      bg-color="white"
      options-dense
      multiple
      clearable
      :display-value="shown(filters.type)"
    >
      <template #prepend>
        <q-icon name="swap_vert" size="xs" color="grey-6" />
      </template>
    </q-select>

    <q-select
      v-model="filters.status"
      :options="statusOptions"
      class="col-12 col-sm-6 col-md-3"
      label="Status"
      dense
      outlined
      bg-color="white"
      options-dense
      multiple
      clearable
      :display-value="shown(filters.status)"
    >
      <template #prepend>
        <q-icon name="pending_actions" size="xs" color="grey-6" />
      </template>
    </q-select>

    <q-select
      v-model="filters.ccy"
      :options="currencyOptions"
      class="col-12 col-sm-6 col-md-3"
      label="Currency"
      dense
      outlined
      bg-color="white"
      options-dense
      multiple
      clearable
      emit-value
      map-options
      :display-value="shown(filters.ccy)"
    >
      <template #prepend>
        <q-icon name="payments" size="xs" color="grey-6" />
      </template>
    </q-select>

    <q-select
      v-model="filters.category_id"
      :options="categoryOptions"
      class="col-12 col-sm-6 col-md-3"
      label="Category"
      dense
      outlined
      bg-color="white"
      options-dense
      multiple
      clearable
      emit-value
      map-options
      :display-value="shown(filters.category_id, categoryOptions)"
    >
      <template #prepend>
        <q-icon name="label" size="xs" color="grey-6" />
      </template>
    </q-select>

    <!--
      add-unique lets a fragment through, which the server searches as a phrase, so "700"
      finds 0700.HK. input-debounce because on a QSelect it is the model update that waits.
    -->
    <q-select
      v-model="filters.symbol"
      :options="shownSymbols"
      class="col-12 col-sm-6 col-md-3"
      label="Symbol"
      outlined
      bg-color="white"
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
      <template #prepend>
        <q-icon name="show_chart" size="xs" color="grey-6" />
      </template>
      <template #no-option>
        <q-item dense>
          <q-item-section class="text-grey"> Nothing matches; Enter adds it </q-item-section>
        </q-item>
      </template>
    </q-select>

    <!-- Every card's statements due in one month, from every month there has been one. -->
    <q-select
      v-model="filters.due_month"
      :options="dueMonthOptions"
      class="col-12 col-sm-6 col-md-3"
      label="Statement month"
      outlined
      bg-color="white"
      dense
      options-dense
      clearable
      emit-value
      map-options
    >
      <template #prepend>
        <q-icon name="event_note" size="xs" color="grey-6" />
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

// Quasar's ramp, as the table's badges are: the hues only part card rows from bank rows.
const accountTypeBadges = {
  cash: { color: 'teal-1', textColor: 'teal-9' },
  card: { color: 'deep-purple-1', textColor: 'deep-purple-9' },
  security: { color: 'orange-1', textColor: 'orange-10' },
}

const dueMonthOptions = computed(() =>
  (page.props.filterOptions?.dueMonths ?? []).map(month => ({
    label: monthLabel(month),
    value: month,
  })),
)
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

// Remembered per browser, as the other pages' view choices are, and opened on arrival
// anyway when the URL carries a filter, so a followed link shows what applied. Only a click
// is remembered: a link that opened the row does not leave it open for the next visit.
const keptOpen = useStorage('transactions.filtersOpen', false)
const rowOpen = ref(keptOpen.value || Object.keys(seeded).length > 0)

const toggleRow = () => {
  rowOpen.value = !rowOpen.value
  keptOpen.value = rowOpen.value
}

// A filter as the URL carries it, in the shape the controls hold it.
const parse = filter => ({
  description: filter.description ?? null,
  account_id: ids(filter.account_id),
  account_type: list(filter.account_type),
  type: list(filter.type),
  status: list(filter.status),
  category_id: ids(filter.category_id),
  ccy: list(filter.ccy),
  symbol: list(filter.symbol),
  date_from: filter.date_from ?? null,
  date_to: filter.date_to ?? null,
  due_date: filter.due_date ?? null,
  due_month: filter.due_month ?? null,
  // A string, as in the URL: query() drops '', so off is no filter rather than one on false.
  unpaid: filter.unpaid ?? '',
})

const filters = reactive(parse(seeded))

// What the list on screen is filtered by: the server's echo of the last request, not the
// controls. The chips and Clear all read this, so they change when the rows do -- off the
// controls, a chip went at the click and the rows a fifth of a second later, and the table
// jumped up under the old rows before they were replaced.
const applied = computed(() => parse(page.props.params?.filter ?? {}))

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

const active = computed(() => Object.keys(page.props.params?.filter ?? {}).length > 0)

const chips = computed(() => {
  const on = applied.value

  const picked = [
    ['account_id', 'Account', accountOptions.value],
    ['account_type', 'Account type', null],
    ['type', 'Type', null],
    ['status', 'Status', null],
    ['ccy', 'Currency', null],
    ['category_id', 'Category', categoryOptions.value],
    ['symbol', 'Symbol', null],
  ]
    .filter(([key]) => on[key].length)
    .map(([key, name, options]) => ({
      key,
      label: `${name}: ${shown(on[key], options)}`,
      remove: () => (filters[key] = []),
    }))

  const due = on.due_date
    ? [
        {
          key: 'due_date',
          label: `Statement due ${on.due_date}`,
          remove: () => (filters.due_date = null),
        },
      ]
    : []

  const month = on.due_month
    ? [
        {
          key: 'due_month',
          label: `Statements due ${monthLabel(on.due_month)}`,
          remove: () => (filters.due_month = null),
        },
      ]
    : []

  const appliedRange =
    on.date_from === on.date_to ? on.date_from : `${on.date_from} – ${on.date_to}`

  const dated = on.date_from
    ? [{ key: 'date', label: `Date: ${appliedRange}`, remove: () => (range.value = null) }]
    : []

  return [...due, ...month, ...dated, ...picked]
})

// The statement and the month have no input in the panel, so their chips show even while
// the panel is open.
const shownChips = computed(() =>
  rowOpen.value
    ? chips.value.filter(chip => ['due_date', 'due_month'].includes(chip.key))
    : chips.value,
)

const monthFormat = new Intl.DateTimeFormat('en', {
  month: 'short',
  year: 'numeric',
  timeZone: 'UTC',
})
const monthLabel = month => monthFormat.format(new Date(`${month}-01T12:00:00Z`))

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
    due_month: null,
    unpaid: '',
  })
}

// The statement panel's quick filter: that card's statement and nothing else.
const showStatement = (cardId, dueDate) => {
  clear()
  Object.assign(filters, { account_id: [cardId], due_date: dueDate })
}

// The statement panel's month shortcut: every card's statements due that month.
const showDueMonth = month => {
  clear()
  filters.due_month = month
}

defineExpose({ showStatement, showDueMonth, clear })

watch(filters, apply, { deep: true })
</script>
