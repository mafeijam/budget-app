<template>
  <div class="column no-wrap q-gutter-lg">
    <div>
      <div class="row items-center">
        <div class="text-h6 text-weight-medium q-mr-md">Year in review</div>
        <q-select
          v-if="yearOptions.length"
          v-model="picked"
          :options="yearOptions"
          class="app-broker-select"
          dense
          outlined
          emit-value
          map-options
          options-dense
        >
          <template #prepend>
            <q-icon name="event" size="xs" color="grey-7" />
          </template>
        </q-select>
      </div>
      <div class="text-caption text-grey-7 q-mt-sm">
        In {{ base }}, card spending on the day it was charged, as the categories page reads it.
        <template v-if="unconverted.length">
          {{ unconverted.join(', ') }} left out: no rate to {{ base }} yet.
        </template>
      </div>
    </div>

    <div v-if="!year" class="text-grey-6">No transactions yet.</div>

    <template v-else>
      <!-- The year in four figures, each against the one before. -->
      <div class="app-outlook">
        <div v-for="tile in headline" :key="tile.label" class="app-outlook__tile app-review-tile">
          <div class="text-caption text-grey-7">{{ tile.label }}</div>
          <div class="text-h5 text-weight-bold money" :class="tile.class">{{ tile.value }}</div>
          <div class="text-caption" :class="tile.noteClass ?? 'text-grey-6'">{{ tile.note }}</div>
        </div>
      </div>

      <!-- What the holdings did, as the headline is laid out: the money put in, what it
           earned, and where the value went. -->
      <q-card flat bordered>
        <q-card-section class="row items-center q-pb-sm">
          <q-icon name="show_chart" size="sm" color="grey-6" class="q-mr-sm" />
          <div class="text-subtitle1 text-weight-medium">Investing</div>
        </q-card-section>
        <q-card-section class="q-pt-none">
          <div class="app-review-investing">
            <div
              v-for="line in investing"
              :key="line.label"
              class="app-outlook__tile"
              :class="{ 'app-outlook__tile--total': line.total }"
            >
              <div class="text-caption text-grey-7">{{ line.label }}</div>
              <div class="text-h6 text-weight-bold money" :class="line.class">{{ line.value }}</div>
              <div class="text-caption text-grey-6">{{ line.note }}</div>
            </div>
          </div>
        </q-card-section>
      </q-card>

      <!-- Each month in against out, on one scale for the year, and the two that stood out. -->
      <q-card flat bordered>
        <q-card-section class="row items-center q-pb-sm">
          <q-icon name="calendar_month" size="sm" color="grey-6" class="q-mr-sm" />
          <div class="text-subtitle1 text-weight-medium">The months</div>
          <q-space />
          <div class="row items-center q-gutter-x-md text-caption text-grey-8">
            <span class="row items-center no-wrap">
              <span class="cash-flow-chart__swatch" style="background: #059669" />In
            </span>
            <span class="row items-center no-wrap">
              <span class="cash-flow-chart__swatch" style="background: #dc2626" />Out
            </span>
          </div>
        </q-card-section>
        <q-card-section class="q-pt-none">
          <div class="app-review-months">
            <div
              v-for="month in monthBars"
              :key="month.month"
              class="app-review-month"
              :class="{
                'app-review-month--best': month.month === year.best?.month,
                'app-review-month--worst': month.month === year.worst?.month,
              }"
            >
              <div class="app-review-month__bars">
                <span
                  class="app-review-month__bar"
                  :style="{ height: `${month.in}%`, background: '#059669' }"
                />
                <span
                  class="app-review-month__bar"
                  :style="{ height: `${month.out}%`, background: '#dc2626' }"
                />
              </div>
              <div class="text-caption text-grey-7">{{ month.short }}</div>
              <div class="text-caption money" :class="signClass(month.net)">
                {{ compact(month.net) }}
              </div>
              <q-tooltip :delay="200" :offset="[0, 6]">
                <div class="text-weight-bold">{{ month.label }}</div>
                <div class="money">In {{ money(month.income) }}</div>
                <div class="money">Out {{ money(month.spending) }}</div>
                <div class="money">Net {{ signed(month.net) }}</div>
              </q-tooltip>
            </div>
          </div>
          <div v-if="year.best" class="row q-gutter-x-lg text-caption q-mt-sm">
            <span>
              <span class="text-grey-7">Best</span>
              <span class="text-weight-medium q-ml-xs">{{ monthLabel(year.best.month) }}</span>
              <span class="money text-positive q-ml-xs">{{ signed(year.best.net) }}</span>
            </span>
            <span v-if="year.worst">
              <span class="text-grey-7">Worst</span>
              <span class="text-weight-medium q-ml-xs">{{ monthLabel(year.worst.month) }}</span>
              <span class="money q-ml-xs" :class="signClass(year.worst.net)">
                {{ signed(year.worst.net) }}
              </span>
            </span>
          </div>
        </q-card-section>
      </q-card>

      <!-- Where the spending went: the donut beside the categories, in two columns, each a way
           into its rows -- side by side, so the card is the donut's height and not a column. -->
      <q-card flat bordered>
        <q-card-section class="row items-center q-pb-sm">
          <q-icon name="donut_large" size="sm" color="grey-6" class="q-mr-sm" />
          <div class="text-subtitle1 text-weight-medium">Where it went</div>
          <q-space />
          <div v-if="year.compare" class="text-caption text-grey-7">
            against {{ year.compare.label }}
          </div>
        </q-card-section>
        <q-card-section class="q-pt-none app-review-where">
          <svg
            viewBox="0 0 120 120"
            class="app-review-donut"
            role="img"
            :aria-label="`Spending by category in ${year.year}`"
          >
            <circle cx="60" cy="60" r="44" fill="none" stroke="#fdf8ea" stroke-width="20" />
            <circle
              v-for="arc in arcs"
              :key="arc.key"
              cx="60"
              cy="60"
              r="44"
              fill="none"
              :stroke="arc.colour"
              :stroke-width="hoveredArc === arc.key ? 24 : 20"
              :stroke-dasharray="`${arc.length} ${circumference - arc.length}`"
              :stroke-dashoffset="-arc.offset"
              transform="rotate(-90 60 60)"
              class="app-review-donut__arc"
              @mouseenter="hoveredArc = arc.key"
              @mouseleave="hoveredArc = null"
            />
            <text x="60" y="56" text-anchor="middle" class="app-review-donut__label">
              {{ hoveredLabel.name }}
            </text>
            <text x="60" y="72" text-anchor="middle" class="app-review-donut__value">
              {{ hoveredLabel.value }}
            </text>
          </svg>
          <q-list class="app-review-categories">
            <q-item
              v-for="category in year.categories"
              :key="category.id ?? 'none'"
              clickable
              class="app-review-category"
              :class="{ 'app-review-category--on': hoveredArc === (category.id ?? 'none') }"
              @mouseenter="hoveredArc = category.id ?? 'none'"
              @mouseleave="hoveredArc = null"
              @click="openCategory(category)"
            >
              <q-item-section>
                <div class="row items-center no-wrap">
                  <span
                    class="app-review-dot q-mr-sm"
                    :style="{ background: colourOf(category) }"
                  />
                  <span class="text-weight-medium text-grey-9 ellipsis">{{ category.name }}</span>
                  <span class="text-caption text-grey-6 q-ml-sm">{{ category.share }}%</span>
                </div>
                <div class="app-review-share q-mt-xs">
                  <span :style="{ width: `${category.share}%`, background: colourOf(category) }" />
                </div>
              </q-item-section>
              <q-item-section side class="text-right">
                <div class="money text-weight-bold text-grey-9">
                  {{ money(category.amount) }}
                </div>
                <div class="text-caption money" :class="changeOf(category).class">
                  {{ changeOf(category).label }}
                </div>
              </q-item-section>
            </q-item>
            <q-item v-if="!isZero(year.other_categories)">
              <q-item-section class="text-grey-7">Everything else</q-item-section>
              <q-item-section side class="money text-grey-8">
                {{ money(year.other_categories) }}
              </q-item-section>
            </q-item>
          </q-list>
        </q-card-section>
      </q-card>

      <!-- The year's largest single spends. -->
      <q-card flat bordered>
        <q-card-section class="row items-center q-pb-sm">
          <q-icon name="shopping_bag" size="sm" color="grey-6" class="q-mr-sm" />
          <div class="text-subtitle1 text-weight-medium">Biggest spends</div>
          <div class="text-caption text-grey-7 q-ml-sm">
            of at least {{ money(year.purchase_floor) }}, a fiftieth of the year's spending
          </div>
        </q-card-section>
        <q-list separator>
          <q-item v-for="(purchase, i) in year.purchases" :key="purchase.id">
            <q-item-section avatar class="text-grey-6 text-weight-bold">{{ i + 1 }}</q-item-section>
            <q-item-section>
              <div class="text-weight-medium text-grey-9 ellipsis">{{ purchase.description }}</div>
              <div class="text-caption text-grey-6">
                {{
                  [
                    formatDate(purchase.date),
                    purchase.account,
                    purchase.category ?? 'No category',
                  ].join(' · ')
                }}
              </div>
            </q-item-section>
            <q-item-section side class="text-right">
              <div class="money text-weight-bold text-negative">{{ money(purchase.amount) }}</div>
              <div v-if="purchase.native" class="text-caption text-grey-6 money">
                {{ purchase.native.ccy }} {{ money(purchase.native.amount) }}
              </div>
            </q-item-section>
          </q-item>
          <q-item v-if="!year.purchases.length">
            <q-item-section class="text-grey-6">Nothing that large this year.</q-item-section>
          </q-item>
        </q-list>
      </q-card>

      <!-- A few things the figures above do not say. -->
      <div class="app-outlook">
        <div v-for="fact in facts" :key="fact.label" class="app-outlook__tile app-review-tile">
          <div class="text-caption text-grey-7">{{ fact.label }}</div>
          <div class="text-h6 text-weight-bold text-grey-9 ellipsis">{{ fact.value }}</div>
          <div class="text-caption text-grey-6 ellipsis">{{ fact.note }}</div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
const props = defineProps({
  // Year => its review, every year since the first transaction: see App\Support\YearReview.
  years: { type: Object, default: () => ({}) },
  unconverted: { type: Array, default: () => [] },
  base: { type: String, default: 'HKD' },
  thisYear: { type: Number, default: () => new Date().getFullYear() },
})

const money = useMoney()
const formatDate = useCalendarDay()

const yearKeys = computed(() =>
  Object.keys(props.years)
    .map(Number)
    .sort((a, b) => b - a),
)

// Remembered per browser, as the other pages' pickers are. A stored year the data no longer
// has falls back to the latest without overwriting the choice.
const stored = useLocalStorage('review.year', props.thisYear)
const picked = computed({
  get: () => (yearKeys.value.includes(stored.value) ? stored.value : (yearKeys.value[0] ?? null)),
  set: value => (stored.value = value),
})

const year = computed(() => (picked.value === null ? null : props.years[picked.value]))

const yearOptions = computed(() =>
  yearKeys.value.map(key => ({
    label: key === props.thisYear ? `${key}, so far` : String(key),
    value: key,
  })),
)

// On the decimal strings; floats only for a percentage to read and a bar's height.
const isZero = value => /^-?0*(\.0*)?$/.test(String(value ?? '0'))
const negative = value => String(value).startsWith('-') && !isZero(value)
const signClass = value =>
  isZero(value) ? 'text-grey-7' : negative(value) ? 'text-negative' : 'text-positive'
const signed = value => (negative(value) ? `−${money(String(value).slice(1))}` : `+${money(value)}`)
const compact = value =>
  `${negative(value) ? '−' : '+'}${new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(Math.abs(Number(value)))}`

const monthName = new Intl.DateTimeFormat('en', { month: 'short', timeZone: 'UTC' })
const monthLong = new Intl.DateTimeFormat('en', { month: 'long', year: 'numeric', timeZone: 'UTC' })
const monthDate = month =>
  new Date(Date.UTC(Number(month.slice(0, 4)), Number(month.slice(5, 7)) - 1, 1))
const monthLabel = month => monthLong.format(monthDate(month))

// The change on the year before, for reading, so a float percentage.
// `named` adds what it is against, which a tile needs and a list under a heading saying so
// does not.
const against = (now, then, upIsGood = true, named = true) => {
  if (then === null || then === undefined || Number(then) === 0)
    return { label: '', class: 'text-grey-6' }

  const change = ((Number(now) - Number(then)) / Math.abs(Number(then))) * 100
  const good = upIsGood ? change >= 0 : change <= 0

  return {
    label: `${change >= 0 ? '+' : '−'}${Math.abs(change).toFixed(0)}%${named ? ` on ${year.value.compare.label}` : ''}`,
    class: Math.abs(change) < 0.5 ? 'text-grey-6' : good ? 'text-positive' : 'text-negative',
  }
}

const headline = computed(() => {
  const y = year.value
  const p = y.compare
  const income = against(y.income, p?.income)
  const spending = against(y.spending, p?.spending, false)

  return [
    {
      label: 'Income',
      value: money(y.income),
      class: 'text-grey-9',
      note: income.label,
      noteClass: income.class,
    },
    {
      label: 'Spending',
      value: money(y.spending),
      class: 'text-grey-9',
      note: spending.label,
      noteClass: spending.class,
    },
    {
      label: 'Kept',
      value: signed(y.net),
      class: signClass(y.net),
      note:
        y.savings_rate === null
          ? ''
          : `${y.savings_rate}% of income${p?.savings_rate ? `, against ${p.savings_rate}%` : ''}`,
    },
    {
      label: 'Net worth',
      value: signed(y.net_worth.change),
      class: signClass(y.net_worth.change),
      note: `${money(y.net_worth.start)} → ${money(y.net_worth.end)}`,
    },
  ]
})

// Heights on one scale for the year, the larger of a month's two sides the tallest bar.
const monthBars = computed(() => {
  const months = year.value.months
  const top = Math.max(1, ...months.flatMap(m => [Number(m.income), Number(m.spending)]))

  return months.map(m => ({
    ...m,
    in: (Number(m.income) / top) * 100,
    out: (Number(m.spending) / top) * 100,
    short: monthName.format(monthDate(m.month)),
    label: monthLabel(m.month),
  }))
})

// The card's heading says what the year is against, so each row only says by how much.
const changeOf = category => against(category.amount, category.previous, false, false)

// The donut: an arc a named category, in the list's order, and everything else as one.
const palette = [
  '#2563eb',
  '#059669',
  '#f59e0b',
  '#dc2626',
  '#7c3aed',
  '#0891b2',
  '#db2777',
  '#65a30d',
]
const colourOf = category => palette[year.value.categories.indexOf(category) % palette.length]
const circumference = 2 * Math.PI * 44
const hoveredArc = ref(null)

// Floats for the arcs' lengths only; every figure shown is the server's.
const arcs = computed(() => {
  const total = Number(year.value.spending)

  if (total <= 0) return []

  const rest = isZero(year.value.other_categories)
    ? []
    : [
        {
          key: 'rest',
          name: 'Everything else',
          amount: year.value.other_categories,
          share: ((Number(year.value.other_categories) / total) * 100).toFixed(1),
          colour: '#cbd5e1',
        },
      ]
  let offset = 0

  return [
    ...year.value.categories.map(category => ({
      key: category.id ?? 'none',
      name: category.name,
      amount: category.amount,
      share: category.share,
      colour: colourOf(category),
    })),
    ...rest,
  ].map(part => {
    const length = (Number(part.amount) / total) * circumference
    const arc = { ...part, length, offset }
    offset += length

    return arc
  })
})

const hoveredLabel = computed(() => {
  const arc = arcs.value.find(a => a.key === hoveredArc.value)

  if (!arc) return { name: 'Spending', value: compactMoney(year.value.spending) }

  return {
    name: arc.name.length > 14 ? `${arc.name.slice(0, 13)}…` : arc.name,
    value: `${arc.share}%`,
  }
})

const compactMoney = value =>
  new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(
    Number(value),
  )

// The category's rows for the year, on the transactions list. NO_CATEGORY is the list's own
// name for the rows with none (TransactionController::NO_CATEGORY).
const openCategory = category =>
  router.visit('/transactions', {
    data: {
      filter: {
        category_id: category.id ?? 'none',
        date_from: year.value.from,
        date_to: year.value.to,
      },
    },
  })

const investing = computed(() => {
  const i = year.value.investing

  return [
    { label: 'Put in', note: 'bought less sold', value: signed(i.invested), class: 'text-grey-9' },
    {
      label: 'Dividends',
      note: 'received',
      value: signed(i.dividends),
      class: signClass(i.dividends),
    },
    {
      label: 'Realised on sales',
      note: 'over what the shares sold had cost',
      value: signed(i.realised),
      class: signClass(i.realised),
    },
    {
      label: 'Market value',
      note: `${money(i.value_start)} → ${money(i.value_end)}`,
      value: signed(minus(i.value_end, i.value_start)),
      class: signClass(minus(i.value_end, i.value_start)),
    },
    {
      label: 'What the holdings earned',
      note: 'the value’s change less what was put in, with the dividends',
      value: signed(i.total),
      class: signClass(i.total),
      total: true,
    },
  ]
})

const facts = computed(() => {
  const f = year.value.facts
  const streak = f.longest_no_spend

  return [
    {
      label: 'Transactions',
      value: f.transactions.toLocaleString('en'),
      note: 'recorded in the year',
    },
    {
      label: 'Days with spending',
      value: `${f.spending_days} of ${f.days}`,
      note: `${Math.round((f.spending_days / Math.max(f.days, 1)) * 100)}% of the days`,
    },
    {
      label: 'Most often',
      value: f.top_merchant?.description ?? '—',
      note: f.top_merchant ? `${f.top_merchant.count} times, ${money(f.top_merchant.amount)}` : '',
    },
    {
      label: 'Longest without spending',
      value: streak.days ? `${streak.days} day${streak.days === 1 ? '' : 's'}` : 'none',
      note: streak.days
        ? `${formatDate(streak.from)} to ${formatDate(streak.to)}`
        : 'something every day',
    },
  ]
})
</script>
