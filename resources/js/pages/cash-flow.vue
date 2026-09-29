<template>
  <div class="column no-wrap q-gutter-lg">
    <div>
      <div class="text-h6 text-weight-medium">Cash flow</div>
      <div class="text-caption text-grey-7">
        The last {{ months }} months. Paying a card and trading are moves between your own accounts,
        so neither counts as spending; pending rows are left out.
      </div>
    </div>

    <div v-if="!report.length" class="text-grey-6">
      No income or spending in the last {{ months }} months.
    </div>

    <q-card v-for="section in report" :key="section.ccy" flat bordered>
      <q-card-section class="row items-center q-gutter-sm">
        <q-icon name="insights" size="sm" color="grey-6" />
        <div class="text-subtitle1 text-weight-medium">{{ section.ccy }}</div>
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

            <template v-if="isOpen(section.ccy, month.month)">
              <tr
                v-for="category in month.categories"
                :key="`${month.month}-${category.id}`"
                class="bg-grey-1"
              >
                <td class="q-pl-xl text-grey-8">{{ category.name ?? 'No category' }}</td>
                <td />
                <td class="text-right money">{{ money(category.amount) }}</td>
                <td class="text-right text-caption text-grey-7">
                  {{ share(category.amount, month.spending) }}
                </td>
                <td />
                <td class="text-right">
                  <q-btn
                    flat
                    dense
                    round
                    size="sm"
                    color="grey-7"
                    icon="open_in_new"
                    @click="openTransactions(month, category)"
                  >
                    <q-tooltip :delay="500" :offset="[0, 6]">
                      This month's transactions in this category
                    </q-tooltip>
                  </q-btn>
                </td>
              </tr>
            </template>
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
})

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
