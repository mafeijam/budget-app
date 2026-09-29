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
          @update:model-value="value => visit(value || null)"
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
        The last {{ months }} months. Paying a card and trading are moves between your own accounts,
        so neither counts as spending; pending rows are left out.
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
      <q-card-section class="row items-center q-gutter-sm">
        <q-icon name="insights" size="sm" color="grey-6" />
        <div>
          <div class="text-subtitle1 text-weight-medium">Income and spending</div>
          <div class="text-caption text-grey-7">
            <template v-if="ccy">Every {{ ccy }} account, in {{ ccy }}.</template>
            <template v-else>Every account, in {{ base }} at the rate on each row's day.</template>
          </div>
        </div>
        <q-space />
        <div v-for="figure in figures(section)" :key="figure.label" class="text-right q-ml-lg">
          <div class="text-caption text-grey-7">{{ figure.label }}</div>
          <div class="text-subtitle1 text-weight-bold money" :class="figure.class">
            {{ money(figure.value) }}
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
            <tr v-if="isOpen(section.ccy, month.month)" class="app-flow-breakdown">
              <td colspan="6">
                <div class="text-caption text-grey-7 q-mb-xs">
                  Spending by category, of {{ money(month.spending) }}
                </div>
                <div
                  v-for="category in month.categories"
                  :key="`${month.month}-${category.id}`"
                  class="app-flow-breakdown__row cursor-pointer"
                  @click="openTransactions(month, category)"
                >
                  <div class="ellipsis text-grey-9">{{ category.name ?? 'No category' }}</div>
                  <q-linear-progress
                    :value="fraction(category.amount, month.spending)"
                    color="negative"
                    track-color="grey-3"
                    rounded
                    size="6px"
                  />
                  <div class="text-right money text-weight-medium">
                    {{ money(category.amount) }}
                  </div>
                  <div class="text-right text-grey-7">
                    {{ share(category.amount, month.spending) }}
                  </div>
                  <q-icon name="open_in_new" size="xs" color="grey-6">
                    <q-tooltip :delay="500" :offset="[0, 6]">
                      This month's transactions in this category
                    </q-tooltip>
                  </q-icon>
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
  currencies: { type: Array, default: () => [] },
  base: { type: String, default: 'HKD' },
  unconverted: { type: Array, default: () => [] },
})

const currencyOptions = computed(() => [
  { label: `All, in ${props.base}`, value: '', caption: "Converted at each day's rate" },
  ...props.currencies.map(code => ({ label: code, value: code, caption: `${code} accounts only` })),
])

const visit = ccy =>
  router.get('/cash-flow', ccy ? { ccy } : {}, { preserveScroll: true, replace: true })

const money = useMoney()

const figures = section => [
  { label: 'Income', value: section.totals.income },
  { label: 'Spending', value: section.totals.spending },
  { label: 'Net', value: section.totals.net, class: signClass(section.totals.net) },
  { label: 'Invested', value: section.totals.invested, class: 'text-grey-7' },
]

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
const fraction = (part, whole) =>
  Number(whole) > 0 ? Math.min(Number(part) / Number(whole), 1) : 0

// A share for reading, not money: rounded to a whole percent.
const share = (part, whole) =>
  Number(whole) > 0 ? `${Math.round((Number(part) / Number(whole)) * 100)}%` : ''

const openTransactions = (month, category) =>
  router.visit('/transactions', {
    data: {
      filter: {
        date_from: month.from,
        date_to: month.to,
        ...(category.id ? { category_id: category.id } : {}),
      },
    },
  })
</script>
