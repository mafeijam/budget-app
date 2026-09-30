<template>
  <div class="column no-wrap q-gutter-lg">
    <div>
      <div class="row items-center">
        <div class="text-h6 text-weight-medium q-mr-md">Cash flow</div>
        <q-select
          v-if="currencies.length > 1"
          :model-value="ccy ?? ''"
          :options="currencyOptions"
          class="app-broker-select"
          dense
          outlined
          emit-value
          map-options
          options-dense
          @update:model-value="value => visit({ ccy: value || null })"
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
        The last {{ months }} months. {{ cardNote }} Paying a card and trading are moves between
        your own accounts, so neither counts as spending; pending rows are left out.
      </div>
    </div>

    <div v-for="code in unconverted" :key="code" class="app-note app-note--warning row no-wrap">
      <q-icon name="warning_amber" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
      <div>
        Some {{ code }} rows are left out: there is no {{ code }} rate on their day yet. Fetch
        prices on the Positions page to add it.
      </div>
    </div>

    <div v-if="!report.length" class="text-grey-6">
      No income or spending in the last {{ months }} months.
    </div>

    <q-card v-for="section in report" :key="section.ccy" flat bordered>
      <q-card-section class="row items-center no-wrap q-col-gutter-md">
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
        <!-- On the chart it changes, as the net worth page keeps its spacing. -->
        <div class="col-auto app-toolbar row items-center no-wrap">
          <q-icon name="credit_card" size="xs" color="grey-6" class="q-mx-sm" />
          <q-btn-toggle
            :model-value="card"
            :options="[
              { label: 'By due date', value: 'due' },
              { label: 'By charge date', value: 'charged' },
            ]"
            no-caps
            unelevated
            dense
            toggle-color="blue-1"
            toggle-text-color="primary"
            text-color="grey-8"
            padding="xs md"
            class="app-toolbar__toggle text-weight-bold"
            @update:model-value="value => visit({ card: value })"
          />
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

      <q-markup-table flat dense>
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
              <td class="text-right money text-grey-7">{{ money(month.invested) }}</td>
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
                    @click="openTransactions(month, category)"
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
                      <q-icon name="open_in_new" size="14px" class="app-flow-tile__open" />
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
  </div>
</template>

<script setup>
const props = defineProps({
  report: { type: Array, default: () => [] },
  months: { type: Number, default: 12 },
  ccy: { type: String, default: null },
  card: { type: String, default: 'due' },
  currencies: { type: Array, default: () => [] },
  base: { type: String, default: 'HKD' },
  unconverted: { type: Array, default: () => [] },
})

const currencyOptions = computed(() => [
  { label: `All, in ${props.base}`, value: '', caption: "Converted at each day's rate" },
  ...props.currencies.map(code => ({ label: code, value: code, caption: `${code} accounts only` })),
])

// Each off the URL at its default: every currency, and card spending by due date.
const visit = ({ ccy = props.ccy, card = props.card }) =>
  router.get(
    '/cash-flow',
    { ...(ccy ? { ccy } : {}), ...(card === 'due' ? {} : { card }) },
    { preserveScroll: true, replace: true },
  )

const cardNote = computed(() =>
  props.card === 'due'
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

const monthFormat = new Intl.DateTimeFormat('en', {
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
})

const monthLabel = month => {
  const [year, number] = month.split('-').map(Number)

  return monthFormat.format(new Date(Date.UTC(year, number - 1, 1)))
}

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

const openTransactions = (month, category) =>
  router.visit('/transactions', {
    data: {
      filter: {
        ...(props.card === 'due'
          ? { counted_from: month.from, counted_to: month.to }
          : { date_from: month.from, date_to: month.to }),
        ...(category.id ? { category_id: category.id } : {}),
      },
    },
  })
</script>
