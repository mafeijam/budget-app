<template>
  <div class="column no-wrap q-gutter-lg">
    <div>
      <div class="row items-center">
        <div class="text-h6 text-weight-medium q-mr-md">Cash flow</div>
        <q-select
          v-if="currencies.length > 1"
          v-model="ccy"
          :options="currencyOptions"
          class="app-broker-select"
          dense
          outlined
          emit-value
          map-options
          options-dense
        >
          <template #prepend>
            <q-icon name="payments" size="xs" color="grey-7" />
          </template>

          <template #option="scope">
            <q-item v-bind="scope.itemProps">
              <q-item-section>
                {{ scope.opt.label }}
                <q-item-label caption>{{ scope.opt.caption }}</q-item-label>
              </q-item-section>
            </q-item>
          </template>
        </q-select>
      </div>
      <div class="text-caption text-grey-7 q-mt-xs">
        {{ cardNote }} Paying a card and trading are moves between your own accounts, so neither
        counts as spending; pending rows are left out.
      </div>
    </div>

    <div v-for="code in unconverted" :key="code" class="app-note app-note--warning row no-wrap">
      <q-icon name="warning_amber" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
      <div>
        Some {{ code }} rows are left out: there is no {{ code }} rate on their day yet. Fetch
        prices on the Positions page to add it.
      </div>
    </div>

    <!-- A window with nothing in it still shows the control that left it, or arrows that
         cannot come back are a one-way trip: the reader landed here by stepping. -->
    <q-card v-if="!report.length" flat bordered>
      <q-card-section class="row items-center no-wrap q-col-gutter-md">
        <div class="col text-caption text-grey-7">
          No income or spending in the {{ months }} months since {{ monthLabel(windowSince) }}.
        </div>
        <div class="col-auto">
          <div class="app-toolbar row items-center no-wrap">
            <FlowPeriodControl
              :last="windowLastMonth"
              :earliest="earliestLast"
              :latest="latestLast"
              :months="months"
              :label="monthLabel"
              :shift-month="shiftMonth"
              @choose="travelTo"
            />
          </div>
        </div>
      </q-card-section>
    </q-card>

    <q-card v-for="section in report" :key="section.ccy" flat bordered>
      <q-card-section class="row items-center q-col-gutter-md">
        <div class="col row items-center no-wrap">
          <q-icon name="insights" size="sm" color="grey-6" class="q-mr-sm" />
          <div>
            <div class="text-subtitle1 text-weight-medium">Income and spending</div>
            <div class="text-caption text-grey-7">
              <template v-if="ccy">Every {{ ccy }} account, in {{ ccy }}.</template>
              <template v-else
                >Every account, in {{ base }} at the rate on each row's day.</template
              >
            </div>
          </div>
        </div>
        <!-- On the chart it changes, as the net worth page keeps its spacing. One box, as it
             keeps one: the window's own control joins the toggle's rather than sitting beside
             it in a second, and it is the taller of the two, so the box grows to it. -->
        <div class="col-auto">
          <div class="app-toolbar row items-center no-wrap">
            <template v-if="furthest">
              <FlowPeriodControl
                :last="windowLastMonth"
                :earliest="earliestLast"
                :latest="latestLast"
                :months="months"
                :label="monthLabel"
                :shift-month="shiftMonth"
                @choose="travelTo"
              />
              <q-separator vertical inset class="q-mx-sm" />
            </template>
            <q-icon name="credit_card" size="xs" color="grey-6" class="q-mx-sm" />
            <q-btn-toggle
              v-model="card"
              :options="[
                { label: 'By due date', value: 'due' },
                { label: 'By charge date', value: 'charged' },
              ]"
              no-caps
              unelevated
              dense
              toggle-color="amber-3"
              toggle-text-color="grey-9"
              text-color="grey-8"
              padding="xs md"
              class="app-toolbar__toggle text-weight-bold"
            />
          </div>
        </div>
      </q-card-section>

      <!-- The year's four figures, each with what it is made of. -->
      <q-card-section class="q-pt-none">
        <div class="app-outlook">
          <div
            v-for="figure in figures(section)"
            :key="figure.label"
            class="app-outlook__tile"
            :class="{ 'app-outlook__tile--total': figure.total }"
          >
            <div class="text-caption text-grey-7">{{ figure.label }}</div>
            <div class="text-h6 text-weight-bold money" :class="figure.class">
              {{ money(figure.value) }}
            </div>
            <div class="text-caption money text-grey-6">{{ figure.note }}</div>
          </div>
        </div>
      </q-card-section>

      <q-separator />

      <q-card-section>
        <CashFlowChart :months="section.months" :ccy="section.ccy" />
      </q-card-section>

      <q-separator />

      <q-markup-table flat dense class="app-flow-table app-head-table">
        <thead>
          <tr class="text-grey-7">
            <th class="text-left">Month</th>
            <th class="text-right">Income</th>
            <th class="text-right">Spending</th>
            <th class="text-right">Net</th>
            <th class="text-right">Invested</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <template v-for="month in newestFirst(section)" :key="month.month">
            <tr
              class="cursor-pointer"
              :class="{ 'text-grey-5': quiet(month) }"
              @click="toggle(section.ccy, month.month)"
            >
              <td class="text-weight-medium">{{ monthLabel(month.month) }}</td>
              <td class="text-right money">{{ money(month.income) }}</td>
              <td class="text-right money">{{ money(month.spending) }}</td>
              <td class="text-right money text-weight-bold" :class="signClass(month.net)">
                {{ money(month.net) }}
              </td>
              <td
                class="text-right money"
                :class="Number(month.invested) === 0 ? 'text-grey-5' : 'text-primary'"
              >
                {{ money(month.invested) }}
              </td>
              <td class="text-right">
                <q-icon
                  v-if="month.categories.length"
                  :name="isOpen(section.ccy, month.month) ? 'expand_less' : 'expand_more'"
                  color="grey-7"
                />
              </td>
            </tr>

            <!-- One row spanning the table: the breakdown is spending only, and under the
                 month's own columns a share read as its net. -->
            <tr v-if="isOpen(section.ccy, month.month)" class="app-flow-breakdown q-tr--no-hover">
              <td colspan="6">
                <div class="row items-baseline q-mb-sm">
                  <div class="text-subtitle2 text-weight-medium text-grey-9">
                    Where {{ money(month.spending) }} went
                  </div>
                  <div class="text-caption text-grey-6 q-ml-sm">
                    {{ month.categories.length }} categories · click one for its transactions
                  </div>
                </div>

                <!-- The whole month's spending as one bar, each category its share of it. -->
                <div class="app-allocation q-mb-md" role="img" :aria-label="breakdownLabel(month)">
                  <div
                    v-for="(category, i) in named(month)"
                    :key="`bar-${month.month}-${category.id}`"
                    class="app-allocation__slice"
                    :style="{
                      flexGrow: Number(category.amount),
                      background: categoryColour(category, i),
                    }"
                  >
                    <q-tooltip :offset="[0, 8]">
                      {{ category.name ?? 'No category' }} · {{ money(category.amount) }} ·
                      {{ share(category.amount, month.spending) }}
                    </q-tooltip>
                  </div>
                </div>

                <div class="app-flow-tiles">
                  <div
                    v-for="(category, i) in [...named(month), ...unnamed(month)]"
                    :key="`${month.month}-${category.id}`"
                    class="app-flow-tile cursor-pointer"
                    :class="{ 'app-flow-tile--busy': peek.busy }"
                    @click="peekTile(month, category, categoryColour(category, i))"
                  >
                    <div class="row items-center no-wrap">
                      <span
                        class="cash-flow-chart__swatch"
                        :style="{ background: categoryColour(category, i) }"
                      />
                      <span
                        class="ellipsis"
                        :class="category.name ? 'text-grey-9' : 'text-grey-6 text-italic'"
                      >
                        {{ category.name ?? 'No category' }}
                      </span>
                      <q-space />
                      <!-- The tile opens its rows here; this goes on to the full list. -->
                      <q-btn
                        flat
                        round
                        dense
                        size="xs"
                        icon="open_in_new"
                        color="grey-5"
                        class="app-open-link"
                        aria-label="Open in Transactions"
                        @click.stop="openTransactions(month, category)"
                      />
                    </div>
                    <div class="row items-baseline no-wrap q-mt-xs">
                      <span class="money text-weight-bold text-grey-9">
                        {{ money(category.amount) }}
                      </span>
                      <q-space />
                      <span class="text-caption text-grey-7">
                        {{ share(category.amount, month.spending) }}
                      </span>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </q-markup-table>
    </q-card>

    <!-- One tile's rows, read when it is opened. -->
    <PeekDialog
      v-model="peek.open"
      :title="peek.meta?.category.name ?? 'No category'"
      :muted="!peek.meta?.category.name"
      :subtitle="peek.meta ? `${monthLabel(peek.meta.month.month)} · spending` : ''"
      :colour="peek.meta?.colour"
      :total="peek.data?.total"
      :unit="peek.data?.ccy"
      :count="peek.data?.count"
      :notice="peekNotice"
      :more="peekMore"
      :rows="peekRows"
      @open="openPeeked"
    />
  </div>
</template>

<script setup>
const props = defineProps({
  views: { type: Object, default: () => ({}) },
  months: { type: Number, default: 12 },
  currencies: { type: Array, default: () => [] },
  base: { type: String, default: 'HKD' },
  since: { type: String, default: '' },
  back: { type: Number, default: 0 },
  furthest: { type: Number, default: 0 },
})

// The currency and how a card is counted are the browser's to keep, as the other pages'
// dropdowns are, and not in the URL: the server sends a report for each choice. A stored
// currency the accounts no longer hold reads as all of them, and is left in storage.
const keptCcy = useLocalStorage('cashFlow.ccy', '')
const keptCard = useLocalStorage('cashFlow.card', 'due')
const ccy = computed({
  get: () => (props.currencies.includes(keptCcy.value) ? keptCcy.value : ''),
  set: value => (keptCcy.value = value || ''),
})
const card = computed({
  get: () => (keptCard.value === 'charged' ? 'charged' : 'due'),
  set: value => (keptCard.value = value),
})
const view = computed(() => props.views[card.value]?.[ccy.value || 'all'] ?? {})
const report = computed(() => view.value.report ?? [])
const unconverted = computed(() => view.value.unconverted ?? [])

const currencyOptions = computed(() => [
  { label: `All, in ${props.base}`, value: '', caption: "Converted at each day's rate" },
  ...props.currencies.map(code => ({ label: code, value: code, caption: `${code} accounts only` })),
])

const cardNote = computed(() =>
  card.value === 'due'
    ? 'A card charge counts in the month its statement is due.'
    : 'A card charge counts in the month it was made.',
)

const money = useMoney()

// A share and an average for reading, not money, so floats; every sum is the server's.
const perMonth = value => money((Number(value) / Math.max(props.months, 1)).toFixed(2))

const figures = section => {
  const t = section.totals
  const kept = Number(t.income) > 0 ? Math.round((Number(t.net) / Number(t.income)) * 100) : null

  return [
    {
      label: 'Income',
      value: t.income,
      class: 'text-positive',
      note:
        Number(t.dividend) > 0
          ? `${money(t.dividend)} of it dividends`
          : `${perMonth(t.income)} a month`,
    },
    {
      label: 'Spending',
      value: t.spending,
      class: 'text-negative',
      note: `${money(t.card_spending)} on cards · ${money(t.cash_spending)} cash`,
    },
    {
      label: 'Net',
      value: t.net,
      class: signClass(t.net),
      note:
        kept === null
          ? `${perMonth(t.net)} a month`
          : `${kept}% of income kept · ${perMonth(t.net)} a month`,
      total: true,
    },
    {
      label: 'Invested',
      value: t.invested,
      class: 'text-grey-9',
      note: `into the brokerages · ${perMonth(t.invested)} a month`,
    },
  ]
}

const signClass = value =>
  String(value).startsWith('-') ? 'text-negative' : Number(value) > 0 ? 'text-positive' : ''

const newestFirst = section => [...section.months].reverse()

const quiet = month => [month.income, month.spending, month.invested].every(v => Number(v) === 0)

// Short, not long, in the window's own range: the two months share the chart's toolbar with
// the card toggle, and "November 2025 – October 2026" is twice the width of "Nov 2025 – Oct
// 2026" for no gain -- the table below names every one of the twelve in full.
const monthFormat = new Intl.DateTimeFormat('en', {
  month: 'short',
  year: 'numeric',
  timeZone: 'UTC',
})

const monthLabel = month => {
  const [year, number] = month.split('-').map(Number)

  return monthFormat.format(new Date(Date.UTC(year, number - 1, 1)))
}

// A month as a name, or as a name moved whole months. The first of the month in UTC, so no
// month is ever short of a day it does not have -- and shifted on the server's own anchor
// rather than off the browser's Date, which disagrees with the app's day six hours a day.
const shiftMonth = (month, by) => {
  const [year, number] = month.split('-').map(Number)
  const shifted = new Date(Date.UTC(year, number - 1 + by, 1))

  return `${shifted.getUTCFullYear()}-${String(shifted.getUTCMonth() + 1).padStart(2, '0')}`
}

// The window the server resolved, and how far either way its last month can go: back counts
// months behind this month's window, so this month's ends at since + back + months - 1.
const windowSince = computed(() => props.since)
const windowLastMonth = computed(() => shiftMonth(props.since, props.months - 1))
const latestLast = computed(() => shiftMonth(windowLastMonth.value, props.back))
const earliestLast = computed(() => shiftMonth(latestLast.value, -props.furthest))

// The window ending this month stays off the URL whatever the server resolved, as on the
// other pages. Not by comparing to props.since: that would keep it on the way back to a window
// already on screen, and the page would open a year later than it just showed.
const travelTo = last =>
  router.get(
    '/cash-flow',
    last === latestLast.value ? {} : { since: shiftMonth(last, 1 - props.months) },
    // Scrolling to the top is wrong for the arrows, which a reader steps while reading the
    // chart below them.
    { preserveScroll: true, replace: true },
  )

const open = ref(new Set())
const key = (ccy, month) => `${ccy}:${month}`
const isOpen = (ccy, month) => open.value.has(key(ccy, month))

const toggle = (ccy, month) => {
  const next = new Set(open.value)

  next.has(key(ccy, month)) ? next.delete(key(ccy, month)) : next.add(key(ccy, month))
  open.value = next
}

// The bar's length only; the figure beside it is the server's string.
// A share for reading, not money. Whole percents from 1% up and one place below, so a
// category with spending never reads 0%, as on the categories page.
const share = (part, whole) => {
  if (Number(whole) <= 0) return ''

  const percent = (Number(part) / Number(whole)) * 100

  if (percent >= 1) return `${Math.round(percent)}%`

  return percent >= 0.05 ? `${percent.toFixed(1)}%` : '<0.1%'
}

// No category goes last and out of the bar: it is not a category to compare with the rest,
// and in the bar it took a slice as wide as a real one.
const named = month => month.categories.filter(category => category.name)
const unnamed = month => month.categories.filter(category => !category.name)

// The shared categorical palette in rank order, and the neutral past it, which spending
// with no category also takes: a colour always means one named category.
const categoryColour = (category, i) => (category.name ? seriesColour(i) : neutral)

const breakdownLabel = month =>
  `Spending by category: ${month.categories
    .filter(c => c.name)
    .map(c => `${c.name} ${share(c.amount, month.spending)}`)
    .join(', ')}`

// The breakdown is spending, so the link says so: a category on its own is not enough, because
// the uncategorised block also catches the month's uncategorised income -- two dividends and
// a credit interest sat above the cash withdrawals in the list it opened, and their total was
// nothing like the figure on the block. A type list cannot do it either, since a card payment
// is a withdrawal on the bank.
//
// NO_CATEGORY is what the filter calls a row with no category; it has no id to send, and
// sending nothing at all read as an unfiltered month.
const openTransactions = (month, category) =>
  router.visit('/transactions', {
    data: {
      filter: {
        ...(card.value === 'due'
          ? { counted_from: month.from, counted_to: month.to }
          : { date_from: month.from, date_to: month.to }),
        spending: '1',
        category_id: category.id ?? NO_CATEGORY,
      },
    },
  })

// The tile whose rows are open in the quick view.
const peek = usePeek()

const peekTile = (month, category, colour) =>
  peek.show(
    '/cash-flow/transactions',
    {
      from: month.from,
      to: month.to,
      card: card.value,
      category: category.id ?? NO_CATEGORY,
      ...(ccy.value ? { ccy: ccy.value } : {}),
    },
    { month, category, colour },
  )

// Largest first, each at its base figure with its own beneath where that differs.
const peekRows = computed(() =>
  (peek.data?.rows ?? []).map(row => ({
    id: row.id,
    label: row.description,
    amount: row.base ?? row.amount,
    tag: row.one_off ? 'one-off' : null,
    native: row.ccy !== peek.data.ccy ? `${row.ccy} ${money(row.amount)}` : null,
    missing: row.base === null ? 'no rate' : null,
  })),
)

const peekNotice = computed(() => {
  const left = peek.data?.unconverted

  return left
    ? `${left} without a rate on their day ${left === 1 ? 'is' : 'are'} left out of the total.`
    : ''
})

const peekMore = computed(() =>
  peek.data && peek.data.count > peek.data.rows.length
    ? `The largest ${peek.data.rows.length} of ${peek.data.count}: the total is of all of them.`
    : '',
)

const openPeeked = () => {
  peek.open = false
  openTransactions(peek.meta.month, peek.meta.category)
}
</script>
