<template>
  <!-- The phone list's search and filters. In the URL, as the full list's are: a filter is what
       the list is, and a link to a filtered list is the point. -->
  <div class="column no-wrap q-gutter-y-sm">
    <div class="row no-wrap items-center q-gutter-x-sm">
      <q-input
        v-model="search"
        outlined
        dense
        clearable
        debounce="300"
        placeholder="Search descriptions"
        class="col app-simple-search"
        @update:model-value="apply"
      >
        <template #prepend>
          <q-icon name="search" />
        </template>
      </q-input>
      <q-btn
        unelevated
        round
        class="app-simple-filter-btn"
        :class="{ 'app-simple-filter-btn--on': chips.length }"
        icon="tune"
        aria-label="Filters"
        @click="openSheet"
      >
        <q-badge v-if="chips.length" floating rounded color="primary" :label="chips.length" />
      </q-btn>
    </div>

    <div v-if="chips.length" class="row q-gutter-xs">
      <q-chip
        v-for="chip in chips"
        :key="chip.key"
        removable
        dense
        class="app-simple-chip"
        :label="chip.label"
        @remove="drop(chip.key)"
      />
    </div>

    <q-dialog v-model="sheet" position="bottom">
      <q-card class="app-simple-sheet">
        <q-card-section class="row items-center no-wrap q-pb-sm">
          <div class="text-h6 text-weight-bold text-grey-9">Filters</div>
          <q-space />
          <q-btn v-close-popup flat round color="grey-6" icon="close" aria-label="Close" />
        </q-card-section>

        <q-card-section class="column q-gutter-y-md q-pt-none">
          <SimplePicker v-model="draft.account_id" :options="accountOptions" label="Account" />
          <SimplePicker v-model="draft.category_id" :options="categoryOptions" label="Category" />
          <div class="row no-wrap q-gutter-x-sm">
            <div class="col">
              <SimplePicker v-model="draft.type" :options="typeOptions" label="Type" />
            </div>
            <div class="col">
              <SimplePicker v-model="draft.ccy" :options="currencyOptions" label="Currency" />
            </div>
          </div>
          <SimplePicker
            v-model="draft.due_month"
            :options="dueMonthOptions"
            label="Statement month"
          />

          <q-btn-toggle
            v-model="draft.status"
            spread
            no-caps
            unelevated
            toggle-color="amber-3"
            toggle-text-color="grey-9"
            :options="statusButtons"
            class="app-simple-sheet__toggle"
          />

          <!-- A field for each end, so a range can be open. The mask goes on the q-date: a
               q-input mask is a different parser. -->
          <div class="row no-wrap q-gutter-x-sm">
            <div v-for="end in ends" :key="end.key" class="col">
              <q-input
                :model-value="draft[end.key]"
                :label="end.label"
                outlined
                readonly
                clearable
                class="app-date-field"
                @clear="draft[end.key] = null"
              >
                <template #prepend>
                  <q-icon name="event" />
                </template>
                <q-menu v-model="menus[end.key]" fit cover>
                  <q-date
                    v-model="draft[end.key]"
                    mask="YYYY-MM-DD"
                    minimal
                    no-unset
                    color="primary"
                    :options="end.options"
                    @update:model-value="menus[end.key] = false"
                  />
                </q-menu>
              </q-input>
            </div>
          </div>
        </q-card-section>

        <q-card-actions class="q-px-md q-pb-md row no-wrap q-gutter-x-sm">
          <q-btn
            flat
            no-caps
            color="grey-7"
            icon="restart_alt"
            label="Reset"
            class="col"
            @click="reset"
          />
          <q-btn
            unelevated
            no-caps
            icon="check"
            label="Show"
            class="col app-btn app-btn--positive text-weight-bold"
            @click="(apply(), (sheet = false))"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </div>
</template>

<script setup>
const props = defineProps({
  // The filter as the URL names it.
  filter: { type: Object, default: () => ({}) },
  options: { type: Object, default: () => ({}) },
  statusOptions: { type: Array, default: Array },
})

// The keys the sheet sets. Any other a link brought, a type or a month, is kept as it came.
const MANAGED = [
  'description',
  'account_id',
  'category_id',
  'type',
  'ccy',
  'due_month',
  'status',
  'date_from',
  'date_to',
]

// A list from the URL's comma form or an array, for the two multiple selects.
const list = value =>
  value === undefined || value === null || value === ''
    ? []
    : (Array.isArray(value) ? value : String(value).split(',')).filter(item => item !== '')

// Ids as numbers, so the select finds its option; No category stays the word it is.
const ids = value => list(value).map(id => (/^\d+$/.test(id) ? Number(id) : id))

const fromFilter = filter => ({
  account_id: ids(filter.account_id),
  category_id: ids(filter.category_id),
  type: list(filter.type),
  ccy: list(filter.ccy),
  due_month: list(filter.due_month),
  status: list(filter.status).length === 1 ? list(filter.status)[0] : null,
  date_from: filter.date_from ?? null,
  date_to: filter.date_to ?? null,
})

const search = ref(props.filter.description ?? '')
const draft = reactive(fromFilter(props.filter))
const sheet = ref(false)
const menus = reactive({ date_from: false, date_to: false })

// Opened on what the list shows, so a change made and not applied is not kept.
const openSheet = () => {
  Object.assign(draft, fromFilter(props.filter))
  sheet.value = true
}

const accountOptions = computed(() => props.options.accounts ?? [])
const categoryOptions = computed(() => props.options.categories ?? [])

const capital = word => word[0].toUpperCase() + word.slice(1)

const typeOptions = computed(() =>
  (props.options.types ?? []).map(type => ({ label: capital(type), value: type })),
)

const currencyOptions = computed(() =>
  (props.options.currencies ?? []).map(ccy => ({ label: ccy, value: ccy })),
)

// YYYY-MM as words, read in UTC so the month is the one named.
const monthName = new Intl.DateTimeFormat('en-US', {
  month: 'short',
  year: 'numeric',
  timeZone: 'UTC',
})

const monthLabel = month => monthName.format(new Date(`${month}-01T00:00:00Z`))

const dueMonthOptions = computed(() =>
  (props.options.dueMonths ?? []).map(month => ({ label: monthLabel(month), value: month })),
)

const statusButtons = computed(() => [
  { label: 'All', value: null },
  ...props.statusOptions.map(status => ({
    label: status[0].toUpperCase() + status.slice(1),
    value: status,
  })),
])

// A q-date hands its days to `options` as YYYY/MM/DD whatever the mask says.
const dashed = day => day.replaceAll('/', '-')

const ends = [
  {
    key: 'date_from',
    label: 'From',
    options: day => !draft.date_to || dashed(day) <= draft.date_to,
  },
  {
    key: 'date_to',
    label: 'To',
    options: day => !draft.date_from || dashed(day) >= draft.date_from,
  },
]

const apply = () => {
  const kept = Object.fromEntries(
    Object.entries(props.filter).filter(([key]) => !MANAGED.includes(key)),
  )

  const chosen = {
    description: (search.value ?? '').trim(),
    account_id: draft.account_id.join(','),
    category_id: draft.category_id.join(','),
    type: draft.type.join(','),
    ccy: draft.ccy.join(','),
    due_month: draft.due_month.join(','),
    status: draft.status ?? '',
    date_from: draft.date_from ?? '',
    date_to: draft.date_to ?? '',
  }

  const filter = {
    ...kept,
    ...Object.fromEntries(Object.entries(chosen).filter(([, value]) => value !== '')),
  }

  router.get('/transactions', Object.keys(filter).length ? { filter } : {}, {
    preserveState: true,
    replace: true,
  })
}

const reset = () => {
  Object.assign(draft, fromFilter({}))
  apply()
  sheet.value = false
}

const labelOf = (options, value) =>
  options.find(option => option.value === value)?.label ?? String(value)

// What the list is filtered to now, from the URL rather than the sheet, so a chip never names a
// choice that was not applied.
const chips = computed(() => {
  const on = fromFilter(props.filter)
  const out = []

  if (on.account_id.length)
    out.push({
      key: 'account_id',
      label: on.account_id.map(id => labelOf(accountOptions.value, id)).join(', '),
    })
  if (on.category_id.length)
    out.push({
      key: 'category_id',
      label: on.category_id.map(id => labelOf(categoryOptions.value, id)).join(', '),
    })
  if (on.type.length) out.push({ key: 'type', label: on.type.map(capital).join(', ') })
  if (on.ccy.length) out.push({ key: 'ccy', label: on.ccy.join(', ') })
  if (on.due_month.length)
    out.push({
      key: 'due_month',
      label: `Statements due ${on.due_month.map(monthLabel).join(', ')}`,
    })
  if (on.status) out.push({ key: 'status', label: capital(on.status) })
  if (on.date_from || on.date_to)
    out.push({
      key: 'date',
      label:
        on.date_from && on.date_to
          ? `${on.date_from} – ${on.date_to}`
          : on.date_from
            ? `From ${on.date_from}`
            : `To ${on.date_to}`,
    })

  return out
})

const drop = key => {
  Object.assign(draft, fromFilter(props.filter))

  if (key === 'date') Object.assign(draft, { date_from: null, date_to: null })
  else draft[key] = key === 'status' ? null : []

  apply()
}
</script>
