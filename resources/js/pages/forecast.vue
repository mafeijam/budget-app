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

    <!-- Near a month's end the month is decided, so the one ahead is the outlook. -->
    <q-card v-if="outlookCard" flat bordered>
      <q-card-section class="row items-center no-wrap q-pb-none">
        <q-icon name="event_note" size="sm" color="grey-7" class="q-mr-sm" />
        <div>
          <div class="text-subtitle1 text-weight-medium">{{ outlookCard.title }}</div>
          <div class="text-caption text-grey-7">{{ outlookCard.caption }}</div>
        </div>
      </q-card-section>

      <q-card-section v-for="row in outlookCard.rows" :key="row.ccy" class="q-pt-md">
        <div v-if="outlookCard.rows.length > 1" class="text-caption text-weight-bold q-mb-xs">
          {{ row.ccy }}
        </div>
        <div class="app-outlook">
          <div
            v-for="tile in row.tiles"
            :key="tile.label"
            class="app-outlook__tile"
            :class="{ 'app-outlook__tile--total': tile.total }"
          >
            <div class="text-caption text-grey-7">{{ tile.label }}</div>
            <div class="text-h6 text-weight-bold money" :class="tile.class">{{ tile.value }}</div>
            <div class="text-caption money" :class="tile.noteClass ?? 'text-grey-6'">
              {{ tile.note }}
            </div>
          </div>
        </div>

        <!-- In against out on one scale: the solid part known, the pale part typical. -->
        <div class="q-mt-md">
          <div
            v-for="bar in row.bars"
            :key="bar.label"
            class="app-outlook__bar row items-center no-wrap"
          >
            <div class="app-outlook__bar-label text-caption text-grey-7">{{ bar.label }}</div>
            <div class="app-outlook__track col">
              <div :style="{ width: `${bar.known}%`, background: bar.colours[0] }" />
              <div :style="{ width: `${bar.typical}%`, background: bar.colours[1] }" />
            </div>
          </div>
        </div>
      </q-card-section>
    </q-card>

    <div v-if="!projection.length" class="text-grey-6">No cash accounts to forecast.</div>

    <q-card v-for="section in projection" :key="section.ccy" flat bordered>
      <q-card-section class="row items-center no-wrap">
        <q-icon name="query_stats" size="sm" color="grey-6" class="q-mr-sm" />
        <div>
          <div class="text-subtitle1 text-weight-medium">Cash runway</div>
          <div class="text-caption text-grey-7">
            <template v-if="ccy">Every {{ ccy }} account, in {{ ccy }}.</template>
            <template v-else>Every cash account, in {{ base }} at today's rate.</template>
          </div>
        </div>
        <q-space />

        <!-- On the chart it changes, as the cash flow page keeps its: the toggle draws the
             pale second line, and the horizon is the one the figures below count to. -->
        <div class="col-auto app-toolbar row items-center no-wrap">
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
      </q-card-section>

      <!-- What the chart is showing, as figures rather than a row of columns beside the
           title: three of them, or five or six with the estimates on. -->
      <q-card-section class="q-pt-none">
        <div class="app-outlook app-outlook--runway">
          <div v-for="figure in figures(section)" :key="figure.label" class="app-outlook__tile">
            <div class="text-caption text-grey-7">{{ figure.label }}</div>
            <div class="text-h6 text-weight-bold money" :class="figure.class">
              {{ figure.value }}
            </div>
            <div class="text-caption text-grey-6">{{ figure.caption }}</div>
          </div>
        </div>
      </q-card-section>

      <q-separator />

      <q-card-section>
        <ForecastChart
          :points="section.points"
          :ccy="section.ccy"
          :lowest="section.lowest_ahead"
          :typical="withTypical && !isZero(section.typical_monthly)"
          :events="section.events"
          :what-if="whatIf"
        />

        <div class="row items-center q-gutter-md q-mt-sm app-forecast-whatif">
          <div class="text-caption text-weight-medium text-grey-8">What if</div>
          <div class="row items-center no-wrap" style="min-width: 280px">
            <span class="text-caption text-grey-7 q-mr-sm">Typical spending</span>
            <q-slider
              v-model="spendingChange"
              :min="-50"
              :max="50"
              :step="10"
              :disable="!withTypical"
              markers
              snap
              dense
              color="warning"
              class="col"
            />
            <span class="text-caption money q-ml-sm" style="width: 44px">
              {{ spendingChange > 0 ? '+' : '' }}{{ spendingChange }}%
            </span>
          </div>
          <q-toggle v-model="noIncome" label="No income" dense color="negative" />
          <q-btn
            v-if="whatIfOn"
            flat
            dense
            no-caps
            color="primary"
            label="Reset"
            @click="resetWhatIf"
          />
        </div>
        <!-- Laid out as sums, so each estimate can be checked from its parts. -->
        <div
          v-if="withTypical && section.typical_basis && !isZero(section.typical_monthly)"
          class="row q-col-gutter-lg q-mt-xs text-caption"
        >
          <div class="col-12 col-md-6">
            <div class="app-basis">
              <div class="app-basis__title">Typical spending</div>
              <div class="app-basis__row">
                <span>Cash, from tomorrow</span>
                <span class="money">{{ money(section.typical_basis.cash) }}</span>
              </div>
              <div class="app-basis__row">
                <span>Cards, on each card's statement due dates</span>
                <span class="money">{{ money(section.typical_basis.card) }}</span>
              </div>
              <div class="app-basis__row app-basis__row--total">
                <span>A month</span>
                <span class="money app-text-estimate">{{ money(section.typical_monthly) }}</span>
              </div>
              <div class="app-basis__note">
                Each is the median month of the last 12, less the recurring rules the chart already
                has as their own payments, so one large month does not set it. For comparison, all
                spending averaged {{ money(section.typical_basis.average) }} a month,
                {{ money(section.typical_basis.recurring) }} of it recurring rules.
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="app-basis">
              <div class="app-basis__title">Typical income</div>
              <div class="app-basis__row">
                <span>Last 12 months' median month</span>
                <span class="money">{{ money(section.typical_income_basis.median) }}</span>
              </div>
              <div class="app-basis__row">
                <span>Less what the recurring rules bring</span>
                <span class="money">−{{ money(section.typical_income_basis.recurring) }}</span>
              </div>
              <div class="app-basis__row app-basis__row--total">
                <span>A month, from tomorrow</span>
                <span class="money app-text-estimate">{{ money(section.typical_income) }}</span>
              </div>
              <div class="app-basis__note">
                The median month of the last 12, less the recurring rules the chart already has as
                their own payments, so a bonus or a refund month does not set it. For comparison,
                income averaged {{ money(section.typical_income_basis.average) }} a month. Dividends
                are each holding's last year of payments a year on,
                {{ money(section.expected_dividends) }} over the {{ months }} months. A bonus is
                placed on the date it was paid, {{ money(section.expected_bonuses) }}, and a month's
                double pay on the date it was doubled, {{ money(section.expected_double_pay) }}.
              </div>
            </div>
          </div>
        </div>
      </q-card-section>

      <q-separator />

      <q-card-section>
        <div class="text-subtitle2 text-weight-medium q-mb-xs">Month by month</div>
        <ForecastMonths
          :months="monthsShown(section)"
          :current="section.months[0]?.month"
          :ccy="section.ccy"
          :typical="withTypical && !isZero(section.typical_monthly)"
        />
      </q-card-section>

      <q-separator />

      <!-- Each account from today to the horizon: where it ends, by how much, and how low it
           goes on the way, with a bar on one scale across them all. -->
      <div class="app-runway-accounts">
        <div class="app-runway-accounts__head text-caption text-grey-7">
          <span>Account</span>
          <span>Month ends</span>
          <span class="text-right">Today</span>
          <span class="text-right">In {{ months }} months</span>
        </div>
        <div v-for="account in section.accounts" :key="account.id" class="app-runway-account">
          <div class="app-runway-account__name">
            <div class="text-weight-medium text-grey-9 ellipsis">{{ account.name }}</div>
            <div
              class="text-caption ellipsis"
              :class="lowClass(account.lowest.amount) || 'text-grey-6'"
            >
              <template v-if="fallsBelowToday(account)">
                {{ withTypical ? 'known lowest' : 'lowest' }} {{ money(account.lowest.amount) }} on
                {{ formatDate(account.lowest.date) }}
              </template>
              <template v-else>never below today</template>
            </div>
          </div>

          <!-- Today and each month end, so a dip on the way and the direction both show. -->
          <div class="app-runway-account__bar">
            <HomeSpark
              v-if="account.path.length > 1"
              :values="account.path.map(point => (withTypical ? point.expected : point.known))"
              :colour="negative(change(account)) ? '#dc2626' : '#059669'"
              :label="`${account.name}, today and each month end`"
              class="app-account-spark"
            />
          </div>

          <div class="text-right money">
            <div class="text-grey-8">{{ money(account.opening) }}</div>
            <div v-if="foreign(account)" class="text-caption text-grey-6">
              {{ account.ccy }} {{ money(account.native.opening) }}
            </div>
          </div>

          <div class="text-right money">
            <div class="text-weight-bold text-grey-9">{{ money(closingOf(account)) }}</div>
            <div class="text-caption" :class="signClass(change(account))">
              {{ isZero(change(account)) ? 'no change' : signed(change(account)) }}
            </div>
            <div v-if="expects(account)" class="text-caption app-text-estimate">
              with ~{{ money(account.dividends) }} dividends
            </div>
            <div v-if="foreign(account)" class="text-caption text-grey-6">
              {{ account.ccy }}
              {{ money(withTypical ? account.native.closing_expected : account.native.closing) }}
            </div>
          </div>
        </div>
      </div>
    </q-card>

    <q-card flat bordered>
      <q-card-section>
        <div class="text-subtitle1 text-weight-medium">Next {{ upcomingDays }} days</div>
        <div class="text-caption text-grey-7">
          What is known to move cash, earliest first, and the balance each leaves.
        </div>
      </q-card-section>

      <q-separator />

      <q-markup-table v-if="soon.length" flat dense>
        <thead>
          <tr class="text-grey-7">
            <th class="text-left" colspan="4" />
            <th class="text-right">Amount</th>
            <th class="text-right">Balance after</th>
            <th v-if="withTypical" class="text-right">With typical</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="(event, i) in soon" :key="i">
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
            <!-- An estimate moves only the typical line, so it has no known balance of its own. -->
            <td v-if="event.estimate" class="text-right text-grey-5">—</td>
            <td v-else class="text-right money" :class="lowClass(event.balance)">
              {{ money(event.balance) }}
            </td>
            <td v-if="withTypical" class="text-right money app-text-estimate">
              {{ money(event.with_typical) }}
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
      <q-card-section
        v-if="withTypical && soonAllowance && !isZero(soonAllowance)"
        class="text-caption text-grey-7 money q-pt-sm"
      >
        Typical spending over these {{ upcomingDays }} days comes to about
        {{ money(soonAllowance) }}, and typical income to {{ money(soonEarned) }}, spread across
        them rather than on any one day, so the balance with both is in the last column.
      </q-card-section>
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

// Both halves when there is money in each. Early in a month one side is usually empty, and
// "+0.00 / −0.00" says less than the side that is not empty.
const stillToCome = row => {
  const [comingIn, comingOut] = [row.to_come.income, row.to_come.spending]

  if (isZero(comingIn) && isZero(comingOut)) return 'nothing cash left to come'

  return isZero(comingIn)
    ? `−${money(comingOut)}`
    : isZero(comingOut)
      ? `+${money(comingIn)}`
      : `+${money(comingIn)} / −${money(comingOut)}`
}

// A comparison for reading, not money, so a float percentage is fine.
const versus = row => against(likely(row), row)

const against = (value, row) => {
  const average = Number(row.average_net)

  if (average === 0) return null

  const change = ((Number(value) - average) / Math.abs(average)) * 100

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

// The what-if is a question asked of the chart, so it is not remembered.
const spendingChange = ref(0)
const noIncome = ref(false)
const whatIf = computed(() => ({
  factor: withTypical.value ? 1 + spendingChange.value / 100 : 0,
  noIncome: noIncome.value,
}))
const whatIfOn = computed(() => spendingChange.value !== 0 || noIncome.value)
const resetWhatIf = () => {
  spendingChange.value = 0
  noIncome.value = false
}

// Past the 27th this month is all but over, and its outlook is zeros to come.
const nextMonth = computed(() => {
  const section = props.projection[0]

  if (!props.outlook.length || props.outlook[0].days_left > 3 || !section) return null

  return section.months.find(m => m.month > props.outlook[0].month) ?? null
})

const nextNet = computed(() =>
  withTypical.value ? nextMonth.value.net_typical : nextMonth.value.net_known,
)

const inColours = ['#059669', '#86efac']
const outColours = ['#dc2626', '#fca5a5']

// Widths only, on one scale for the in and out bars, so a float is fine. known and typical
// are [in, out] pairs; the typical part is left off when the page is showing known only.
const flowBars = (known, typical) => {
  const extra = i => (withTypical.value ? typical[i] : 0)
  const scale = Math.max(1, known[0] + extra(0), known[1] + extra(1))
  const bar = (label, i, colours) => ({
    label,
    known: (known[i] / scale) * 100,
    typical: (extra(i) / scale) * 100,
    colours,
  })

  return [bar('In', 0, inColours), bar('Out', 1, outColours)]
}

const averageTile = (value, row) => {
  const change = against(value, row)

  return {
    label: 'Against an average month',
    value: change?.label ?? '—',
    class: change?.class ?? 'text-grey-7',
    note: `an average month nets ${signed(row.average_net)}`,
  }
}

// One shape for both outlooks, the month ahead and the rest of this one, so they read alike.
const outlookCard = computed(() => {
  if (nextMonth.value) {
    const month = nextMonth.value
    const row = props.outlook[0]

    return {
      title: `${monthName(month.month)} ahead`,
      caption: `${monthName(row.month)} is all but over, so this is next month.`,
      rows: [
        {
          ccy: props.projection[0].ccy,
          tiles: [
            {
              label: 'Known in',
              value: `+${money(month.in)}`,
              class: 'text-positive',
              note: withTypical.value ? `and +${money(month.typical_in)} typical income` : '',
            },
            {
              label: 'Known out',
              value: `−${money(month.out)}`,
              class: 'text-negative',
              note: withTypical.value ? `and −${money(month.typical)} typical spending` : '',
            },
            {
              label: 'Likely net',
              value: signed(nextNet.value),
              class: signClass(nextNet.value),
              note: withTypical.value ? 'known and typical together' : 'the known figures only',
              total: true,
            },
            averageTile(nextNet.value, row),
          ],
          bars: flowBars(
            [Number(month.in), Number(month.out)],
            [Number(month.typical_in), Number(month.typical)],
          ),
        },
      ],
    }
  }

  if (!props.outlook.length) return null

  const first = props.outlook[0]
  const days = `${first.days_left} day${first.days_left === 1 ? '' : 's'}`

  return {
    title: `${monthName(first.month)} outlook`,
    caption: `This month so far, and what is still to come in the ${days} left.`,
    rows: props.outlook.map(row => ({
      ccy: row.ccy,
      tiles: [
        {
          label: 'Net so far',
          value: signed(row.so_far.net),
          class: signClass(row.so_far.net),
          note: `+${money(row.so_far.income)} in · −${money(row.so_far.spending)} out`,
        },
        {
          label: 'Still to come',
          value: stillToCome(row),
          class: 'text-grey-9',
          note: withTypical.value
            ? `and +${money(row.typical_income_rest)} / −${money(row.typical_rest)} typical`
            : 'cash only',
        },
        {
          label: 'Likely month end',
          value: signed(likely(row)),
          class: signClass(likely(row)),
          note: withTypical.value ? 'known and typical together' : 'the known figures only',
          total: true,
        },
        averageTile(likely(row), row),
      ],
      bars: flowBars(
        [
          Number(row.so_far.income) + Number(row.to_come.income),
          Number(row.so_far.spending) + Number(row.to_come.spending),
        ],
        [Number(row.typical_income_rest), Number(row.typical_rest)],
      ),
    })),
  }
})

// The events the projection placed within the list's reach, with the balance each leaves.
const soon = computed(() => {
  const section = props.projection[0]

  if (!section) return []

  const until = section.points[Math.min(props.upcomingDays, section.points.length - 1)]?.date

  // An expected dividend or bonus is an estimate, so only while the estimates are shown.
  return section.events.filter(
    event => event.date <= until && (withTypical.value || !event.estimate),
  )
})

// The month the forecast starts in, left off when nothing is left of it: on its last day
// it is an empty column labelled "rest of".
const monthsShown = section =>
  section.months.filter(
    (month, i) => i > 0 || [month.in, month.out, month.typical].some(value => !isZero(value)),
  )

// The what-if's end of horizon: an estimate of an estimate, so whole units, never a
// figure to the cent -- the same arithmetic the chart draws its line with.
const whatIfEnd = section => {
  const last = section.points.at(-1)
  const known = Number(last.known) - (noIncome.value ? Number(last.recurring_in) : 0)
  const earned = noIncome.value ? 0 : Number(last.earned)

  return known - Number(last.allowance) * whatIf.value.factor + earned
}

const soonPoint = computed(() => {
  const points = props.projection[0]?.points ?? []

  return points[Math.min(props.upcomingDays, points.length - 1)] ?? null
})

const soonAllowance = computed(() => soonPoint.value?.allowance ?? null)
const soonEarned = computed(() => soonPoint.value?.earned ?? '0')

// Three months is the default, so it stays off the URL.
const choose = n => visit({ months: n })

// Closing less opening, on the decimal strings: BigInt at four places, so the change shown
// is the exact difference of the two figures beside it and not a float's rounding of it.
const scaled = value => {
  const [, sign, whole, fraction = ''] = String(value ?? '0').match(/^(-?)(\d*)\.?(\d*)$/) ?? []
  const units = BigInt((whole || '0') + fraction.padEnd(4, '0').slice(0, 4))

  return sign ? -units : units
}

// With the estimates shown, an account's end has its expected dividends in it; the known
// closing alone otherwise, as the chart's two lines are.
const expects = account => withTypical.value && !isZero(account.dividends)
const closingOf = account => (expects(account) ? account.closing_expected : account.closing)

const change = account => {
  const units = scaled(closingOf(account)) - scaled(account.opening)
  const negative = units < 0n
  const digits = (negative ? -units : units).toString().padStart(5, '0')

  return `${negative ? '-' : ''}${digits.slice(0, -4)}.${digits.slice(-4)}`
}

const fallsBelowToday = account => Number(account.lowest.amount) < Number(account.opening)

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
  const typical = withTypical.value && !isZero(section.typical_monthly)
  const low = section.lowest_ahead?.[typical ? 'typical' : 'known']

  return [
    { label: `In ${props.months} months`, value: money(last?.known) },
    ...(typical
      ? [
          {
            label: 'With typical spending',
            value: money(last?.typical),
            class: 'app-text-estimate',
          },
        ]
      : []),
    ...(low
      ? [
          {
            label: 'Lowest ahead',
            value: money(low.amount),
            class: lowClass(low.amount),
            caption: formatDate(low.date),
          },
        ]
      : []),
    ...(typical
      ? [
          {
            label: 'A month',
            value: signed(section.saving_monthly),
            class: signClass(section.saving_monthly),
            caption: 'with typical spending',
          },
        ]
      : []),
    ...(whatIfOn.value
      ? [
          {
            label: 'What if',
            value: `≈ ${money(String(Math.round(whatIfEnd(section))))}`,
            class: 'text-primary',
            caption: `in ${props.months} months`,
          },
        ]
      : []),
    ...(section.runway_months
      ? [
          {
            label: 'Runway',
            value: `${section.runway_months} months`,
            caption: 'at the average spend, no income',
          },
        ]
      : []),
  ]
}

const kinds = {
  scheduled: { label: 'scheduled', class: 'app-tint app-tint--info' },
  pending: { label: 'pending', class: 'app-tint app-tint--warning' },
  recurring: { label: 'recurring', class: 'app-tint app-tint--positive' },
  statement: { label: 'card statement', class: 'app-tint app-tint--negative' },
  'expected dividend': { label: 'expected dividend', class: 'app-tint app-tint--muted' },
  'expected bonus': { label: 'expected bonus', class: 'app-tint app-tint--muted' },
  'expected double pay': { label: 'expected double pay', class: 'app-tint app-tint--muted' },
}

const openLabel = event =>
  event.estimate
    ? "Last year's payment it is expected from"
    : event.link.recurring
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

  // The row's own day as well as the description, which is a substring match and is not
  // always unique -- every settlement for one card reads "Card payment [NAME]", so without
  // the day this lands on that card's whole payment history rather than the event clicked.
  // link.date is the row's own date, not the day the panel lists it under, because a
  // pending row from an earlier day is listed under today and filtering on that finds
  // nothing. Without it this degrades to the description alone, as it always did.
  return router.visit('/transactions', {
    data: {
      filter: {
        account_id: event.account_id,
        description: event.description,
        date_from: event.link.date,
        date_to: event.link.date,
      },
    },
  })
}
</script>
