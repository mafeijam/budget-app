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
        <q-separator vertical inset class="q-mx-sm" />
        <q-toggle
          v-model="hideTransfers"
          label="Hide transfers"
          color="primary"
          dense
          class="q-px-sm"
        >
          <q-tooltip>The bank's side of a card payment, a buy and a sell</q-tooltip>
        </q-toggle>
        <q-separator vertical inset class="q-mx-sm" />
        <!-- The totals under the table, off unless asked for: a figure on every visit is one
             nobody reads, and the page is shorter without it. -->
        <q-toggle v-model="showTotals" label="Totals" color="primary" dense class="q-px-sm" />
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
    <!-- A field for each end, so a range can be open: everything since a day, or everything up
         to one. The mask goes on the q-date: a q-input mask is a different parser. -->
    <q-input
      v-for="end in rangeEnds"
      :key="end.key"
      :model-value="edge[end.key]"
      class="col-12 col-sm-6 col-md-3 app-date-field"
      :label="counted ? `Counted ${end.key}` : end.label"
      dense
      outlined
      bg-color="white"
      readonly
    >
      <template #append>
        <!-- Quasar draws its own clear icon only on a field that can be typed in, so on a
             readonly one `clearable` is accepted and never shown. -->
        <q-icon
          v-if="edge[end.key]"
          name="cancel"
          class="q-field__focusable-action"
          tabindex="0"
          role="button"
          aria-label="Clear"
          @click="edge[end.key] = null"
          @keyup.enter="edge[end.key] = null"
        />
        <q-btn flat dense round icon="event">
          <q-menu
            v-model="menus[end.key]"
            :offset="[10, 15]"
            anchor="bottom right"
            self="top right"
          >
            <!-- no-unset: a click on the day already picked would clear the filter, and the
                 field's own clear button is the way to do that. The options keep the ends in
                 order, so a From after the To is not one that can be picked. -->
            <q-date
              v-model="edge[end.key]"
              mask="YYYY-MM-DD"
              minimal
              no-unset
              color="primary"
              :options="end.options"
              @update:model-value="menus[end.key] = false"
            />
          </q-menu>
        </q-btn>
      </template>
    </q-input>

    <!-- A cash account's month by the row's own date, as the bank's statement shows it;
         beside the cards' statement month, which goes by when a charge is paid. -->
    <q-select
      v-model="filters.month"
      :options="shownMonths"
      class="col-12 col-sm-6 col-md-3"
      label="Cash month"
      outlined
      bg-color="white"
      dense
      options-dense
      autocomplete="off"
      use-input
      input-debounce="0"
      multiple
      clearable
      :display-value="shown(filters.month)"
      @filter="filterMonths"
    >
      <template #prepend>
        <q-icon name="calendar_month" size="xs" color="grey-6" />
      </template>
      <template #no-option>
        <q-item dense>
          <q-item-section class="text-grey">Nothing matches</q-item-section>
        </q-item>
      </template>
    </q-select>

    <!-- Every card's statements due in the months picked, from every month there has been one. -->
    <q-select
      v-model="filters.due_month"
      :options="shownDueMonths"
      class="col-12 col-sm-6 col-md-3"
      label="Statement month"
      outlined
      bg-color="white"
      dense
      options-dense
      autocomplete="off"
      use-input
      input-debounce="0"
      multiple
      clearable
      :display-value="shown(filters.due_month)"
      @filter="filterDueMonths"
    >
      <template #prepend>
        <q-icon name="event_note" size="xs" color="grey-6" />
      </template>
      <template #no-option>
        <q-item dense>
          <q-item-section class="text-grey">Nothing matches</q-item-section>
        </q-item>
      </template>
    </q-select>

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
      <!-- Under a heading per type, and each with its currency, since a card and its bank can
        share a name. -->
      <template #option="scope">
        <div>
          <q-item-label
            v-if="scope.index === 0 || accountOptions[scope.index - 1].type !== scope.opt.type"
            header
            class="app-filter-group q-py-xs"
          >
            {{ accountTypeTitles[scope.opt.type] ?? scope.opt.type }}
          </q-item-label>
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
            <q-item-section side>
              <span class="text-caption text-grey-6">{{ scope.opt.ccy }}</span>
            </q-item-section>
          </q-item>
        </div>
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
      :options="shownCategories"
      class="col-12 col-sm-6 col-md-3"
      label="Category"
      dense
      outlined
      bg-color="white"
      options-dense
      autocomplete="off"
      use-input
      input-debounce="0"
      multiple
      clearable
      emit-value
      map-options
      :display-value="shown(filters.category_id, categoryOptions)"
      @filter="filterCategories"
    >
      <template #prepend>
        <q-icon name="label" size="xs" color="grey-6" />
      </template>
      <template #no-option>
        <q-item dense>
          <q-item-section class="text-grey">Nothing matches</q-item-section>
        </q-item>
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

// In the enum's order of types, then by name as the server sent them, so each type's
// accounts sit together under the heading the option slot draws.
const accountOptions = computed(() => {
  const order = page.props.filterOptions?.accountTypes ?? []
  const rank = type => (order.includes(type) ? order.indexOf(type) : order.length)

  return [...(page.props.filterOptions?.accounts ?? [])].sort((a, b) => rank(a.type) - rank(b.type))
})

const accountTypeTitles = { cash: 'Cash', card: 'Cards', security: 'Securities' }

const monthOptions = computed(() => page.props.filterOptions?.months ?? [])
const shownMonths = ref([])
const filterMonths = filterInto(shownMonths, monthOptions, (month, needle) =>
  month.includes(needle),
)

const dueMonthOptions = computed(() => page.props.filterOptions?.dueMonths ?? [])
const shownDueMonths = ref([])
const filterDueMonths = filterInto(shownDueMonths, dueMonthOptions, (month, needle) =>
  month.includes(needle),
)
const typeOptions = computed(() => page.props.filterOptions?.types ?? [])
const accountTypeOptions = computed(() => page.props.filterOptions?.accountTypes ?? [])

const symbolOptions = computed(() => page.props.filterOptions?.symbols ?? [])

const shownSymbols = ref([])

const filterSymbols = filterInto(shownSymbols, symbolOptions, (symbol, needle) =>
  symbol.toLowerCase().includes(needle),
)
const statusOptions = computed(() => page.props.statusOptions ?? [])
// The server's own list, which carries the No category entry the plain categories list must
// not: the transaction form chooses from that one, and a row with no category is not a choice.
const categoryOptions = computed(() => page.props.options?.filterCategories ?? [])
const shownCategories = ref([])
const filterCategories = filterInto(shownCategories, categoryOptions, (category, needle) =>
  category.label.toLowerCase().includes(needle),
)
const currencyOptions = computed(() => page.props.currencyOptions ?? [])

const list = value => (value ? String(value).split(',') : [])
const ids = value => list(value).map(Number)
// A category, or NO_CATEGORY for the rows with none, which is a string and not an id:
// Number() on it is NaN, and the select then shows NaN as the label for the filter.
const categoryIds = value => list(value).map(id => (id === NO_CATEGORY ? id : Number(id)))

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

// Shared with the page, which draws the totals: the same key, so the two stay in step.
const showTotals = useShowTotals()
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
  category_id: categoryIds(filter.category_id),
  ccy: list(filter.ccy),
  symbol: list(filter.symbol),
  date_from: filter.date_from ?? null,
  date_to: filter.date_to ?? null,
  // A range of when a row counts, not of the day it carries: a card charge counts in the month
  // its statement is due. The cash flow page links here with it, and a filter that dropped it
  // on the next change would quietly hand back the whole month -- and the income in it.
  counted_from: filter.counted_from ?? null,
  counted_to: filter.counted_to ?? null,
  // Spending alone, as the report counts it. No control: it is a link's condition, and a chip
  // is the only place it can be seen and dropped.
  spending: filter.spending ?? '',
  due_date: filter.due_date ?? null,
  month: list(filter.month),
  due_month: list(filter.due_month),
  // A string, as in the URL: query() drops '', so off is no filter rather than one on false.
  unpaid: filter.unpaid ?? '',
})

const filters = reactive(parse(seeded))

// A choice about the page and not a filter on one search, so Clear all leaves it. Kept in a
// cookie and not in storage or the URL, so the server applies it to the first request: from
// storage the page loaded the whole list and then asked again. The server says whether it
// applied it, which a link naming it can change for one visit without changing the choice.
const hideTransfers = computed({
  get: () => page.props.hideTransfers === true,
  set: on => {
    writeCookie(HIDE_TRANSFERS_COOKIE, on ? '1' : '0')
    apply({ dropHideTransfers: true })
  },
})

// What the list on screen is filtered by: the server's echo of the last request, not the
// controls. The chips and Clear all read this, so they change when the rows do -- off the
// controls, a chip went at the click and the rows a fifth of a second later, and the table
// jumped up under the old rows before they were replaced.
const applied = computed(() => parse(page.props.params?.filter ?? {}))

const menus = reactive({ from: false, to: false })

// Which range the From and To fields are talking about. A link from the cash flow page arrives
// asking for a counted one, and the fields have to be talking about the same range the rows are:
// a month of statements due, where a card charge is dated by its due date, would show empty
// beside seven rows, and picking a day in it would quietly switch the question to the row's
// own date and bring the month's income back with it. Either end alone is enough to be a
// counted range, so clearing one end of it does not hand the other to the row's date.
const counted = computed(
  () => !filters.date_from && !filters.date_to && (!!filters.counted_from || !!filters.counted_to),
)

const rangeKeys = computed(() =>
  counted.value ? ['counted_from', 'counted_to'] : ['date_from', 'date_to'],
)

// One end of whichever range is on. Writing one leaves the other range empty on both ends: a
// hand-made URL can carry both and the server ANDs them, which is a list of nothing; and a
// field that kept the other would be showing a month the rows are not from.
const endOf = index =>
  computed({
    get: () => filters[rangeKeys.value[index]],
    set: value => {
      const keys = rangeKeys.value
      const other = ['date_from', 'date_to', 'counted_from', 'counted_to'].filter(
        key => !keys.includes(key),
      )

      other.forEach(key => (filters[key] = null))
      filters[keys[index]] = value || null
    },
  })

const edge = reactive({ from: endOf(0), to: endOf(1) })

// A q-date hands its days to `options` as YYYY/MM/DD whatever the mask says.
const dashed = day => day.replaceAll('/', '-')

const rangeEnds = [
  { key: 'from', label: 'From', options: day => !edge.to || dashed(day) <= edge.to },
  { key: 'to', label: 'To', options: day => !edge.from || dashed(day) >= edge.from },
]

const clearRange = () => {
  Object.assign(filters, { date_from: null, date_to: null, counted_from: null, counted_to: null })
}

const query = () =>
  Object.fromEntries(
    Object.entries(filters)
      .map(([key, value]) => [key, Array.isArray(value) ? value.join(',') : value])
      .filter(([, value]) => value !== null && value !== ''),
  )

const active = computed(
  () =>
    Object.keys(page.props.params?.filter ?? {}).filter(key => key !== 'hide_transfers').length > 0,
)

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
    ['month', 'Cash month', null],
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

  const month = on.due_month.length
    ? [
        {
          key: 'due_month',
          label: `Statements due ${on.due_month.join(', ')}`,
          remove: () => (filters.due_month = []),
        },
      ]
    : []

  // Either end may be open, and an open one says so rather than printing a dash with nothing
  // after it.
  const stated = (from, to) =>
    from && to ? (from === to ? from : `${from} – ${to}`) : from ? `from ${from}` : `until ${to}`

  // One chip for whichever range is on, named for it. A counted range is not the row's day
  // and a chip reading "Date" over a month of statements due would be the wrong name for it.
  const dated =
    on.date_from || on.date_to
      ? [
          {
            key: 'date',
            label: `Date: ${stated(on.date_from, on.date_to)}`,
            remove: clearRange,
          },
        ]
      : on.counted_from || on.counted_to
        ? [
            {
              key: 'date',
              label: `Counted: ${stated(on.counted_from, on.counted_to)}`,
              remove: clearRange,
            },
          ]
        : []

  // A value that does not say yes is no filter, so an off spending is no chip either.
  const spending =
    on.spending === '1'
      ? [{ key: 'spending', label: 'Spending only', remove: () => (filters.spending = '') }]
      : []

  return [...due, ...month, ...spending, ...dated, ...picked]
})

// The statement, the month and spending have no input in the panel, so their chips show even
// while the panel is open.
const shownChips = computed(() =>
  rowOpen.value
    ? chips.value.filter(chip => ['due_date', 'due_month', 'spending'].includes(chip.key))
    : chips.value,
)

// The cookie's name, as TransactionController::HIDE_TRANSFERS_COOKIE has it.
const HIDE_TRANSFERS_COOKIE = 'transactions_hide_transfers'

const apply = ({ dropHideTransfers = false } = {}) => {
  const { sort, dir, per_page: perPage } = page.props.params ?? {}
  const fallback = page.props.meta?.sort ?? {}
  const params = {}

  if (sort && (sort !== fallback.by || dir !== fallback.dir)) Object.assign(params, { sort, dir })
  if (perPage) params.per_page = perPage

  const filter = query()

  // A link's hide_transfers is the request's only while nothing is changed on it. Switching
  // the toggle hands the choice back to the cookie it just wrote.
  const linked = page.props.params?.filter?.hide_transfers

  if (linked !== undefined && !dropHideTransfers) filter.hide_transfers = linked

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
    counted_from: null,
    counted_to: null,
    spending: '',
    due_date: null,
    month: [],
    due_month: [],
    unpaid: '',
  })
}

// The statement panel's quick filter: that card's statement and nothing else.
const showStatement = (cardId, dueDate) => {
  clear()
  Object.assign(filters, { account_id: [cardId], due_date: dueDate })
}

defineExpose({ showStatement, clear })

watch(filters, () => apply(), { deep: true })
</script>
