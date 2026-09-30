<template>
  <div class="column no-wrap q-gutter-md">
    <FormCategory />

    <div>
      <div class="row items-center">
        <div class="text-h6 text-weight-medium q-mr-md">Categories</div>
        <q-space />
        <CreateBtn />
      </div>
      <div class="text-caption text-grey-7 q-mt-xs">
        {{ base }} {{ money(spending.total) }} spent in the last {{ months.length }} months, a card
        charge on the day it was made. Click a category for its transactions in those months.
      </div>
    </div>

    <div v-for="code in unconverted" :key="code" class="app-note app-note--warning row no-wrap">
      <q-icon name="warning_amber" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
      <div>No {{ code }} rate for some days, so part of {{ code }} spending is left out.</div>
    </div>

    <q-card v-if="spent.length" flat bordered>
      <q-card-section class="row items-center no-wrap q-py-sm">
        <q-icon name="shopping_cart" size="sm" color="grey-7" class="q-mr-sm" />
        <div class="text-subtitle1 text-weight-medium">Spending</div>
        <div class="text-caption text-grey-6 q-ml-sm">{{ spent.length }}</div>
        <q-space />
        <div class="money text-weight-bold">{{ base }} {{ money(spending.total) }}</div>
      </q-card-section>

      <q-separator />

      <div
        v-for="row in spent"
        :key="row.id"
        class="app-category-row"
        :class="{ 'cursor-pointer': row.category }"
        @click="row.category && openTransactions(row.category)"
      >
        <div class="app-category-row__name">
          <div
            class="text-weight-medium ellipsis"
            :class="row.category ? 'text-grey-9' : 'text-grey-6 text-italic'"
          >
            {{ row.category?.name ?? 'No category' }}
          </div>
          <div class="text-caption text-grey-6 ellipsis">{{ caption(row) }}</div>
        </div>

        <div class="app-category-row__share text-grey-7 text-right">{{ share(row) }}</div>

        <div class="app-category-row__figures">
          <div class="money text-weight-medium">{{ base }} {{ money(row.total) }}</div>
          <div class="text-caption text-grey-6 money">{{ money(row.average) }} a month</div>
        </div>

        <div class="app-category-row__trend">
          <HomeSpark
            :values="row.months"
            colour="#dc2626"
            :label="`${row.category?.name ?? 'Uncategorised'} spending by month`"
            class="app-account-spark"
          />
        </div>

        <div class="app-category-row__actions row items-center justify-end no-wrap" @click.stop>
          <AppTableActions v-if="row.category" :cell="{ row: row.category }" />
        </div>
      </div>
    </q-card>

    <q-card v-if="unspent.length" flat bordered>
      <q-card-section class="row items-center no-wrap q-py-sm">
        <q-icon name="label_outline" size="sm" color="grey-7" class="q-mr-sm" />
        <div class="text-subtitle1 text-weight-medium">
          No spending in {{ months.length }} months
        </div>
        <div class="text-caption text-grey-6 q-ml-sm">{{ unspent.length }}</div>
        <q-space />
        <div class="text-caption text-grey-6">Income, or not used lately</div>
      </q-card-section>

      <q-separator />

      <div
        v-for="row in unspent"
        :key="row.id"
        class="app-category-row app-category-row--quiet cursor-pointer"
        @click="openTransactions(row.category)"
      >
        <div class="app-category-row__name">
          <div class="text-weight-medium text-grey-9 ellipsis">{{ row.category.name }}</div>
          <div class="text-caption text-grey-6 ellipsis">{{ caption(row) }}</div>
        </div>
        <div class="app-category-row__actions row items-center justify-end no-wrap" @click.stop>
          <AppTableActions :cell="{ row: row.category }" />
        </div>
      </div>
    </q-card>

    <q-card
      v-if="!spent.length && !unspent.length"
      flat
      bordered
      class="q-pa-lg text-center text-grey-7"
    >
      No categories yet.
    </q-card>
  </div>
</template>

<script setup>
const props = defineProps({
  ...hasTableProps,

  // Category id (0 for none) => its spending per month of the report, and in total.
  spending: { type: Object, default: () => ({ total: '0', categories: {} }) },

  // Category id => {transactions, last_date, recurring}.
  usage: { type: Object, default: () => ({}) },

  base: { type: String, default: 'HKD' },
  unconverted: { type: Array, default: () => [] },
  months: { type: Array, default: () => [] },
  window: { type: Object, default: () => ({ from: null, to: null }) },
})

const pagination = usePagination()
provide('pagination', pagination)

const money = useMoney()
const formatDay = useCalendarDay()

const isZero = value => /^-?0*(\.0*)?$/.test(String(value ?? '0'))

const rows = computed(() => {
  const categories = props.data.data.map(category => ({
    id: category.id,
    category,
    ...(props.spending.categories[category.id] ?? { months: [], total: '0' }),
  }))
  const none = props.spending.categories[0]

  return none ? [...categories, { id: 0, category: null, ...none }] : categories
})

// Ranked on the figure for reading only; every figure shown is the server's string. No
// category goes last whatever its size, since it is not a category to compare with the rest.
const spent = computed(() =>
  rows.value
    .filter(row => !isZero(row.total))
    .sort((a, b) => !a.category - !b.category || Number(b.total) - Number(a.total)),
)

const unspent = computed(() => rows.value.filter(row => row.category && isZero(row.total)))

const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`

const caption = row => {
  if (!row.category) return 'Spending filed under no category'

  const usage = props.usage[row.id] ?? { transactions: 0, last_date: null, recurring: 0 }
  const parts = [plural(usage.transactions, 'transaction')]

  if (usage.last_date) parts.push(`last ${formatDay(usage.last_date)}`)
  if (usage.recurring) parts.push(plural(usage.recurring, 'recurring rule'))

  return parts.join(' · ')
}

// A share for reading, not money, so a float is fine. Of the categorised spending only:
// with the uncategorised in the whole, every share shrinks by an amount that is no category's.
const categorised = computed(
  () => Number(props.spending.total) - Number(props.spending.categories[0]?.total ?? 0),
)

// Whole percents from 1% up; below it one place, or "<0.1%", so a category with spending
// never reads 0%.
const share = row => {
  if (!row.category || categorised.value <= 0) return ''

  const percent = (Number(row.total) / categorised.value) * 100

  if (percent >= 1) return `${Math.round(percent)}%`

  return percent >= 0.05 ? `${percent.toFixed(1)}%` : '<0.1%'
}

// The window the figures cover, so the list adds up to the row that was clicked.
const openTransactions = category =>
  router.visit('/transactions', {
    data: {
      filter: {
        category_id: category.id,
        ...(props.window.from ? { date_from: props.window.from, date_to: props.window.to } : {}),
      },
    },
  })
</script>
