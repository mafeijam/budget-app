<template>
  <div class="column no-wrap q-gutter-lg">
    <div>
      <div class="row items-center">
        <div class="text-h6 text-weight-medium q-mr-md">Forecast</div>
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

        <q-space />

        <!-- The Positions page's toolbar, so the two pages' controls read alike. -->
        <div class="app-toolbar row items-center no-wrap">
          <q-toggle
            v-model="withTypical"
            label="Typical spending"
            color="warning"
            dense
            class="q-px-sm"
          />

          <q-separator vertical inset class="q-mx-sm" />

          <q-icon name="date_range" size="xs" color="grey-6" class="q-mx-sm" />
          <q-btn-toggle
            :model-value="months"
            :options="horizons.map(n => ({ label: `${n} months`, value: n }))"
            no-caps
            unelevated
            dense
            toggle-color="blue-1"
            toggle-text-color="primary"
            text-color="grey-8"
            padding="xs md"
            class="app-toolbar__toggle text-weight-bold"
            @update:model-value="choose"
          />
        </div>
      </div>
      <div class="text-caption text-grey-7 q-mt-xs">
        Each cash account from today: rows dated ahead or still pending, recurring rules, and card
        statements paid from their bank on the due date. Typical spending is an estimate and shown
        apart.
      </div>
    </div>

    <div
      v-for="warning in allWarnings"
      :key="warning"
      class="app-note app-note--warning row no-wrap"
    >
      <q-icon name="warning_amber" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
      <div>{{ warning }}</div>
    </div>

    <q-card v-if="outlook.length" flat bordered>
      <q-card-section class="q-pb-sm">
        <div class="text-subtitle1 text-weight-medium">
          {{ monthName(outlook[0].month) }} outlook
        </div>
        <div class="text-caption text-grey-7">
          This month so far, what is known still to come, and typical spending for the
          {{ outlook[0].days_left }} day{{ outlook[0].days_left === 1 ? '' : 's' }} left.
        </div>
      </q-card-section>
      <q-markup-table flat dense>
        <thead>
          <tr class="text-grey-7">
            <th class="text-left">Currency</th>
            <th class="text-right">Net so far</th>
            <th class="text-right">Still to come</th>
            <th v-if="withTypical" class="text-right">Typical spending</th>
            <th class="text-right">Likely month end</th>
            <th class="text-right">Monthly average</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in outlook" :key="row.ccy">
            <td class="text-weight-medium">{{ row.ccy }}</td>
            <td class="text-right money">{{ signed(row.so_far.net) }}</td>
            <td class="text-right money text-grey-8">
              <span class="text-positive">+{{ money(row.to_come.income) }}</span>
              <span class="q-mx-xs text-grey-5">/</span>
              <span class="text-negative">−{{ money(row.to_come.spending) }}</span>
            </td>
            <td v-if="withTypical" class="text-right money text-warning">
              −{{ money(row.typical_rest) }}
            </td>
            <td class="text-right money text-weight-bold" :class="signClass(likely(row))">
              {{ signed(likely(row)) }}
            </td>
            <td class="text-right money text-grey-7">
              {{ signed(row.average_net) }}
              <span v-if="versus(row)" class="q-ml-xs" :class="versus(row).class">
                ({{ versus(row).label }})
              </span>
            </td>
          </tr>
        </tbody>
      </q-markup-table>
    </q-card>

    <div v-if="!projection.length" class="text-grey-6">No cash accounts to forecast.</div>

    <q-card v-for="section in projection" :key="section.ccy" flat bordered>
      <q-card-section class="row items-center q-gutter-sm">
        <q-icon name="query_stats" size="sm" color="grey-6" />
        <div>
          <div class="text-subtitle1 text-weight-medium">Cash runway</div>
          <div class="text-caption text-grey-7">
            <template v-if="ccy">Every {{ ccy }} account, in {{ ccy }}.</template>
            <template v-else>Every cash account, in {{ base }} at today's rate.</template>
          </div>
        </div>
        <q-space />
        <!-- Top-aligned, so a figure with a caption under it does not lift the rest. -->
        <div class="row items-start no-wrap">
          <div v-for="figure in figures(section)" :key="figure.label" class="text-right q-ml-lg">
            <div class="text-caption text-grey-7">{{ figure.label }}</div>
            <div class="text-subtitle1 text-weight-bold money" :class="figure.class">
              {{ money(figure.value) }}
            </div>
            <div v-if="figure.caption" class="text-caption text-grey-6">{{ figure.caption }}</div>
          </div>
        </div>
      </q-card-section>

      <q-separator />

      <q-card-section>
        <ForecastChart
          :points="section.points"
          :ccy="section.ccy"
          :lowest="section.lowest"
          :typical="withTypical && !isZero(section.typical_monthly)"
        />
        <div
          v-if="withTypical && section.typical_basis && !isZero(section.typical_monthly)"
          class="text-caption text-grey-7 q-mt-sm money"
        >
          Typical spending {{ money(section.typical_monthly) }} a month: the last 12 months' average
          of {{ money(section.typical_basis.average) }}, less
          {{ money(section.typical_basis.recurring) }} the recurring rules already cover.
        </div>
      </q-card-section>

      <q-separator />

      <q-markup-table flat dense>
        <thead>
          <tr class="text-grey-7">
            <th class="text-left">Account</th>
            <th class="text-right">Today</th>
            <th class="text-right">Lowest</th>
            <th class="text-left">On</th>
            <th class="text-right">In {{ months }} months</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="account in section.accounts" :key="account.id">
            <td class="text-weight-medium">
              {{ account.name }}
              <q-badge v-if="foreign(account)" outline color="grey-7" :label="account.ccy" />
            </td>
            <td class="text-right money">
              {{ money(account.opening) }}
              <div v-if="foreign(account)" class="text-caption text-grey-6">
                {{ money(account.native.opening) }} {{ account.ccy }}
              </div>
            </td>
            <td class="text-right money text-weight-bold" :class="lowClass(account.lowest.amount)">
              {{ money(account.lowest.amount) }}
            </td>
            <td class="text-grey-7">{{ formatDate(account.lowest.date) }}</td>
            <td class="text-right money">
              {{ money(account.closing) }}
              <div v-if="foreign(account)" class="text-caption text-grey-6">
                {{ money(account.native.closing) }} {{ account.ccy }}
              </div>
            </td>
          </tr>
        </tbody>
      </q-markup-table>
    </q-card>

    <q-card flat bordered>
      <q-card-section>
        <div class="text-subtitle1 text-weight-medium">Next {{ upcomingDays }} days</div>
        <div class="text-caption text-grey-7">What is known to move cash, earliest first.</div>
      </q-card-section>

      <q-separator />

      <q-markup-table v-if="upcoming.length" flat dense>
        <tbody>
          <tr v-for="(event, i) in upcoming" :key="i">
            <td class="text-grey-8" style="width: 110px">{{ formatDate(event.date) }}</td>
            <td style="width: 110px">
              <q-badge v-bind="kinds[event.kind]" />
            </td>
            <td>{{ event.description }}</td>
            <td class="text-grey-7">{{ event.account }}</td>
            <td class="text-right money text-weight-medium" :class="signClass(event.amount)">
              {{ signed(event.amount) }}
              <span class="text-caption text-grey-7">{{ event.ccy }}</span>
            </td>
            <td class="text-right" style="width: 48px">
              <q-btn
                flat
                dense
                round
                size="sm"
                color="grey-7"
                icon="open_in_new"
                @click="open(event)"
              >
                <q-tooltip :delay="500" :offset="[0, 6]">{{ openLabel(event) }}</q-tooltip>
              </q-btn>
            </td>
          </tr>
        </tbody>
      </q-markup-table>
      <q-card-section v-else class="text-grey-6"
        >Nothing known in the next {{ upcomingDays }} days.</q-card-section
      >
    </q-card>
  </div>
</template>

<script setup>
const props = defineProps({
  projection: { type: Array, default: () => [] },
  upcoming: { type: Array, default: () => [] },
  warnings: { type: Array, default: () => [] },
  months: { type: Number, default: 3 },
  horizons: { type: Array, default: () => [3, 6, 12] },
  upcomingDays: { type: Number, default: 30 },
  outlook: { type: Array, default: () => [] },
  ccy: { type: String, default: null },
  currencies: { type: Array, default: () => [] },
  base: { type: String, default: 'HKD' },
})

const currencyOptions = computed(() => [
  { label: `All, in ${props.base}`, value: '', caption: "Converted at today's rate" },
  ...props.currencies.map(code => ({ label: code, value: code, caption: `${code} accounts only` })),
])

// Held in another currency than the one shown, so its own figure is given too.
const foreign = account => account.ccy !== (props.ccy ?? props.base)

// The horizon and currency together, each off the URL at its default.
const visit = ({ months = props.months, ccy = props.ccy }) =>
  router.get(
    '/forecast',
    { ...(months === 3 ? {} : { months }), ...(ccy ? { ccy } : {}) },
    { preserveScroll: true, replace: true },
  )

// The known figures alone while the estimate is switched off.
const likely = row => (withTypical.value ? row.likely_net : row.likely_known)

// A comparison for reading, not money, so a float percentage is fine.
const versus = row => {
  const average = Number(row.average_net)

  if (average === 0) return null

  const change = ((Number(likely(row)) - average) / Math.abs(average)) * 100

  return {
    label: `${change >= 0 ? '+' : ''}${change.toFixed(0)}%`,
    class: change >= 0 ? 'text-positive' : 'text-negative',
  }
}

const monthFormat = new Intl.DateTimeFormat('en', {
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
})

const monthName = month => {
  const [year, number] = month.split('-').map(Number)

  return monthFormat.format(new Date(Date.UTC(year, number - 1, 1)))
}

const money = useMoney()
const formatDate = useCalendarDay()

// Remembered per browser: a view preference, not data anyone else needs.
const withTypical = useLocalStorage('forecast.typical', true)

// Three months is the default, so it stays off the URL.
const choose = n => visit({ months: n })

// On the decimal string, not a float.
const isZero = value => /^-?0*(\.0*)?$/.test(String(value ?? '0'))
const negative = value => String(value).startsWith('-') && !isZero(value)

const signClass = value =>
  negative(value) ? 'text-negative' : isZero(value) ? '' : 'text-positive'
const lowClass = value => (negative(value) ? 'text-negative' : '')

const signed = value => (negative(value) ? `−${money(String(value).slice(1))}` : `+${money(value)}`)

// Only the known figures raise a warning: an estimate crossing zero is not a fact.
const allWarnings = computed(() => [
  ...props.projection.flatMap(section =>
    section.accounts
      .filter(account => negative(account.lowest.amount))
      .map(
        account =>
          `${account.name} goes below zero: ${money(account.native.lowest)} ${account.ccy} on ${formatDate(account.lowest.date)}.`,
      ),
  ),
  ...props.warnings,
])

const figures = section => {
  const last = section.points.at(-1)

  return [
    { label: 'Today', value: section.points[0]?.known },
    {
      label: 'Lowest',
      value: section.lowest.amount,
      class: lowClass(section.lowest.amount),
      caption: formatDate(section.lowest.date),
    },
    { label: `In ${props.months} months`, value: last?.known },
    ...(withTypical.value && !isZero(section.typical_monthly)
      ? [{ label: 'With typical spending', value: last?.typical, class: 'text-warning' }]
      : []),
  ]
}

const kinds = {
  scheduled: { label: 'scheduled', class: 'app-tint app-tint--info' },
  pending: { label: 'pending', class: 'app-tint app-tint--warning' },
  recurring: { label: 'recurring', class: 'app-tint app-tint--positive' },
  statement: { label: 'card statement', class: 'app-tint app-tint--negative' },
}

const openLabel = event =>
  event.link.recurring
    ? 'Open the recurring rules'
    : event.link.card
      ? "This statement's transactions"
      : 'Open the transaction'

const open = event => {
  if (event.link.recurring) return router.visit('/recurring')

  if (event.link.card) {
    return router.visit('/transactions', {
      data: { filter: { account_id: event.link.card, due_date: event.link.due_date } },
    })
  }

  // By description, not date: a pending row from an earlier day is listed under today.
  return router.visit('/transactions', {
    data: { filter: { account_id: event.account_id, description: event.description } },
  })
}
</script>
