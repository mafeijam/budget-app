<template>
  <div class="column no-wrap q-gutter-lg">
    <!-- Wrapped: the column's gutter margin would undo the row's own negative one. -->
    <div>
      <div class="row items-end q-col-gutter-md">
        <div class="col">
          <div class="text-h6 text-weight-medium">Forecast</div>
          <div class="text-caption text-grey-7">
            Each cash account from today: rows dated ahead or still pending, recurring rules, and
            card statements paid from their bank on the due date. Typical spending is an estimate
            and shown apart.
          </div>
        </div>
        <div class="col-auto row items-center q-gutter-md">
          <q-toggle v-model="withTypical" label="Typical spending" color="warning" dense />
          <q-btn-toggle
            :model-value="months"
            :options="horizons.map(n => ({ label: `${n} months`, value: n }))"
            no-caps
            unelevated
            dense
            toggle-color="primary"
            color="grey-2"
            text-color="grey-8"
            padding="xs md"
            @update:model-value="choose"
          />
        </div>
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

    <div v-if="!projection.length" class="text-grey-6">No cash accounts to forecast.</div>

    <q-card v-for="section in projection" :key="section.ccy" flat bordered>
      <q-card-section class="row items-center q-gutter-sm">
        <q-icon name="query_stats" size="sm" color="grey-6" />
        <div class="text-subtitle1 text-weight-medium">{{ section.ccy }}</div>
        <q-space />
        <div v-for="figure in figures(section)" :key="figure.label" class="text-right q-ml-lg">
          <div class="text-caption text-grey-7">{{ figure.label }}</div>
          <div class="text-subtitle1 text-weight-bold money" :class="figure.class">
            {{ money(figure.value) }}
          </div>
          <div v-if="figure.caption" class="text-caption text-grey-6">{{ figure.caption }}</div>
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
            <td class="text-weight-medium">{{ account.name }}</td>
            <td class="text-right money">{{ money(account.opening) }}</td>
            <td class="text-right money text-weight-bold" :class="lowClass(account.lowest.amount)">
              {{ money(account.lowest.amount) }}
            </td>
            <td class="text-grey-7">{{ formatDate(account.lowest.date) }}</td>
            <td class="text-right money">{{ money(account.closing) }}</td>
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
})

const money = useMoney()
const formatDate = useCalendarDay()

// Remembered per browser: a view preference, not data anyone else needs.
const withTypical = useLocalStorage('forecast.typical', true)

// Three months is the default, so it stays off the URL.
const choose = n =>
  router.get('/forecast', n === 3 ? {} : { months: n }, { preserveScroll: true, replace: true })

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
          `${account.name} goes below zero: ${money(account.lowest.amount)} ${section.ccy} on ${formatDate(account.lowest.date)}.`,
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
