<template>
  <div class="column no-wrap q-gutter-lg">
    <div>
      <div class="row items-center">
        <div class="text-h6 text-weight-medium q-mr-md">Dividends</div>

        <!-- The Positions page's dropdown, and its place. Unlike there it narrows the whole page
             and not a chart on it, so it is a request like the year is: the figures here are
             sums over rows the page does not carry, by symbol and month. -->
        <q-select
          v-if="brokers.length > 1"
          :model-value="broker"
          :options="brokerOptions"
          class="app-broker-select"
          dense
          outlined
          emit-value
          map-options
          options-dense
          @update:model-value="chooseBroker"
        >
          <template #prepend>
            <q-icon name="account_balance" size="xs" color="grey-7" />
          </template>
          <template #option="scope">
            <q-item v-bind="scope.itemProps">
              <q-item-section>{{ scope.opt.label }}</q-item-section>
              <q-item-section v-if="scope.opt.total" side class="money text-caption">
                {{ scope.opt.total }}
              </q-item-section>
            </q-item>
          </template>
        </q-select>

        <q-space />
        <!-- The Positions page's toolbar, so the pages' controls read alike. -->
        <div class="app-toolbar row items-center no-wrap">
          <!-- Every year there is a dividend in, each with its total; the arrows step one. -->
          <q-btn
            flat
            dense
            round
            size="sm"
            icon="chevron_left"
            color="grey-8"
            :disable="!older"
            @click="choose(older)"
          >
            <q-tooltip :delay="500" :offset="[0, 6]">{{ older }}</q-tooltip>
          </q-btn>
          <q-select
            :model-value="year"
            :options="yearOptions"
            class="app-year-select"
            dense
            borderless
            emit-value
            map-options
            options-dense
            @update:model-value="choose"
          >
            <template #prepend>
              <q-icon name="date_range" size="xs" color="grey-6" />
            </template>
            <template #option="scope">
              <q-item v-bind="scope.itemProps">
                <q-item-section>{{ scope.opt.label }}</q-item-section>
                <q-item-section side class="money text-caption">
                  {{ scope.opt.total }}
                </q-item-section>
              </q-item>
            </template>
          </q-select>
          <q-btn
            flat
            dense
            round
            size="sm"
            icon="chevron_right"
            color="grey-8"
            :disable="!newer"
            @click="choose(newer)"
          >
            <q-tooltip :delay="500" :offset="[0, 6]">{{ newer }}</q-tooltip>
          </q-btn>
        </div>
      </div>
      <div class="text-caption text-grey-7 q-mt-xs">
        Every dividend paid into a cash account, in {{ base }} at the rate of the day it was paid.
        Click a symbol for its payments.
      </div>
    </div>

    <div v-for="code in unconverted" :key="code" class="app-note app-note--warning row no-wrap">
      <q-icon name="warning_amber" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
      <div>No {{ code }} rate for some days, so part of the {{ code }} dividends is left out.</div>
    </div>

    <q-card flat bordered>
      <q-card-section>
        <div class="app-outlook app-outlook--fit">
          <div
            v-for="tile in tiles"
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
      </q-card-section>

      <q-separator />

      <q-card-section>
        <div class="text-subtitle2 text-weight-medium q-mb-xs">Month by month</div>
        <DividendMonths
          :year="year"
          :symbols="ranked"
          :expected-months="expectedMonths"
          :previous-months="previousMonths"
          :base="base"
        />
      </q-card-section>

      <template v-if="years.length > 1">
        <q-separator />

        <!-- Each year's total on one scale; a click shows that year. -->
        <q-card-section>
          <div class="row items-center q-mb-sm">
            <div class="text-subtitle2 text-weight-medium">Every year</div>
            <q-space />
            <!-- On the chart it changes, as the cash flow page keeps its: this filters the
                 bars below and nothing else, so it belongs beside them rather than in the
                 page header beside a year picker that filters the page. -->
            <q-select
              v-if="symbolOptions.length > 1"
              v-model="onlySymbol"
              :options="symbolOptions"
              class="app-symbol-select"
              dense
              outlined
              emit-value
              map-options
              options-dense
            >
              <template #prepend>
                <q-icon name="show_chart" size="xs" color="grey-7" />
              </template>
              <template #option="scope">
                <q-item v-bind="scope.itemProps">
                  <q-item-section>
                    {{ scope.opt.label }}
                    <q-item-label v-if="scope.opt.caption" caption>{{
                      scope.opt.caption
                    }}</q-item-label>
                  </q-item-section>
                  <q-item-section side class="money text-caption">{{
                    scope.opt.total
                  }}</q-item-section>
                </q-item>
              </template>
            </q-select>
          </div>
          <div class="app-dividend-years">
            <div
              v-for="entry in yearsWithChange"
              :key="entry.year"
              class="app-dividend-year cursor-pointer"
              :class="{ 'app-dividend-year--on': entry.year === year }"
              @click="choose(entry.year)"
            >
              <!-- The bar and the captions that ride on it, sharing one plot so the
                   percentages are of the same height in every column. -->
              <div class="app-dividend-year__track">
                <div
                  class="app-dividend-year__plot"
                  :style="{ '--bar': barHeight(entry.total), '--expect': expectHeight(entry) }"
                >
                  <div class="app-dividend-year__labels">
                    <div
                      v-if="entry.change"
                      class="text-caption money text-center"
                      :class="entry.change.class"
                    >
                      {{ entry.change.label }}
                    </div>
                    <div class="text-caption money text-grey-8 text-center">
                      {{ compact(entry.total) }}
                    </div>
                  </div>
                  <!-- This year's still expected on top, dashed, as the month chart has it. -->
                  <div
                    v-if="entry.year === thisYear && Number(expectedThisYear) > 0"
                    class="app-dividend-year__expected"
                  />
                  <div class="app-dividend-year__fill" />
                </div>
              </div>
              <div class="text-caption text-weight-medium">{{ entry.year }}</div>
            </div>
          </div>
        </q-card-section>
      </template>
    </q-card>

    <q-card flat bordered>
      <q-card-section class="row items-center no-wrap q-py-sm">
        <q-icon name="savings" size="sm" color="grey-7" class="q-mr-sm" />
        <div class="text-subtitle1 text-weight-medium">By symbol</div>
        <div class="text-caption text-grey-6 q-ml-sm">{{ ranked.length }}</div>
        <q-space />
        <div class="text-caption text-grey-6">Each month's payment, darker for more</div>
      </q-card-section>

      <q-separator />

      <div class="app-dividend-head text-caption text-grey-7">
        <span>Symbol</span>
        <div class="app-dividend-heat">
          <span v-for="m in monthInitials" :key="m.key" class="text-center">{{ m.label }}</span>
        </div>
        <span class="text-right">All time</span>
        <span class="text-right">{{ year }}</span>
        <span class="text-right">{{ year - 1 }}</span>
      </div>

      <div
        v-for="symbol in ranked"
        :key="symbol.symbol"
        class="app-dividend-row cursor-pointer"
        @click="openPayments(symbol)"
      >
        <div class="app-dividend-row__name">
          <div class="row items-center no-wrap">
            <span class="cash-flow-chart__swatch" :style="{ background: symbol.colour }" />
            <span class="text-weight-bold text-grey-9">{{ symbol.symbol }}</span>
            <span v-if="symbol.name" class="text-caption text-grey-7 ellipsis q-ml-sm">
              {{ symbol.name }}
            </span>
          </div>
          <div class="text-caption text-grey-6 ellipsis">{{ caption(symbol) }}</div>
        </div>

        <div class="app-dividend-heat">
          <div
            v-for="(amount, i) in symbol.months"
            :key="i"
            class="app-dividend-heat__cell"
            :class="{
              'app-dividend-heat__cell--expected': Number(symbol.expected_months[i]) > 0,
            }"
            :style="cellStyle(symbol, i)"
          >
            <q-tooltip v-if="Number(amount) > 0 || Number(symbol.expected_months[i]) > 0">
              {{ monthInitials[i].name }}:
              <template v-if="Number(amount) > 0">{{ money(amount) }} paid</template>
              <template v-if="Number(symbol.expected_months[i]) > 0">
                ~{{ money(symbol.expected_months[i]) }} expected
              </template>
            </q-tooltip>
          </div>
        </div>

        <!-- What it has paid in all, which is the figure the swatch is ranked on, so the
             two can be read as one thing. Ahead of the two years because it is the total
             they are shares of. The span is on every row rather than only the long ones,
             so the column reads the same down its length. -->
        <div class="text-right money">
          <div class="text-grey-8">{{ money(symbol.allTime) }}</div>
          <div v-if="symbol.years" class="text-caption text-grey-6">
            over {{ symbol.years }} year{{ symbol.years === 1 ? '' : 's' }}
          </div>
        </div>

        <div class="text-right money">
          <div class="text-weight-bold text-grey-9">{{ money(symbol.total) }}</div>
          <div v-if="Number(symbol.expected) > 0" class="text-caption app-text-estimate">
            ~{{ money(symbol.expected) }} more expected
          </div>
          <div v-else class="text-caption text-grey-6">{{ share(symbol.total) }} of the year</div>
        </div>

        <div class="text-right money">
          <div class="text-grey-8">{{ money(symbol.previous) }}</div>
          <div v-if="growth(symbol)" class="text-caption" :class="growth(symbol).class">
            {{ growth(symbol).label }}
          </div>
        </div>
      </div>

      <q-card-section v-if="!ranked.length" class="text-grey-6">
        No dividends in {{ year }}.
      </q-card-section>
    </q-card>
  </div>
</template>

<script setup>
const props = defineProps({
  year: { type: Number, required: true },
  // The brokerage the page is of, or 0 for all of them, and every one that has paid, each
  // with what it has paid in all.
  broker: { type: Number, default: 0 },
  brokers: { type: Array, default: () => [] },
  // What the holdings cost, in the base currency, on the day the yield is read against: the
  // year's last, or today's. Null where a currency had no rate, named in costUnconverted.
  cost: { type: String, default: null },
  costUnconverted: { type: Array, default: () => [] },
  // Every year with a dividend, newest first, each with its total.
  years: { type: Array, default: () => [] },
  base: { type: String, default: 'HKD' },
  today: { type: String, default: '' },
  total: { type: String, default: '0' },
  previous: { type: String, default: '0' },
  expected: { type: String, default: '0' },
  payments: { type: Number, default: 0 },
  // Twelve decimal strings each, January first.
  months: { type: Array, default: () => [] },
  expectedMonths: { type: Array, default: () => [] },
  previousMonths: { type: Array, default: () => [] },
  symbols: { type: Array, default: () => [] },
  // Every symbol that has ever paid, with its name and its all-time total, for the year's
  // bar filter. Not `symbols`, which is this year's only.
  allSymbols: { type: Array, default: () => [] },
  // This year's still-expected total for each symbol. Empty on any other year, as the
  // dashed top on a bar only ever appears on this year's.
  expectedBySymbol: { type: Object, default: () => ({}) },
  unconverted: { type: Array, default: () => [] },
})

const money = useMoney()
const formatDay = useCalendarDay()

// The symbol the year's bars are read one at a time for, or '' for every symbol. In the
// browser and not the URL, as the Positions page's brokerage: it narrows one chart of data
// already on the page, where the year above it is the page's own subject. Named for what
// it does rather than `symbol`, which the by-symbol table's rows already take.
const onlySymbol = useStorage('dividends.symbol', '')

const symbolOptions = computed(() => [
  { label: 'All symbols', value: '', total: money(yearsTotal.value), caption: null },
  // Biggest first, since the figure beside each is what it has paid in all, and that is
  // what the bars it filters are shares of.
  ...[...props.allSymbols]
    .sort((a, b) => Number(b.total) - Number(a.total))
    .map(s => ({
      label: s.symbol,
      value: s.symbol,
      total: money(s.total),
      // What it is, and how long it has been paying: a symbol on one of the years is a
      // different thing from one on all of them.
      caption: [s.name, s.years > 1 ? `${s.years} years` : null].filter(Boolean).join(' · '),
    })),
])

// A stored symbol that no longer pays anything would leave the select holding a value it
// has no option for, which renders blank. Back to all of them, as a stored brokerage id
// that no longer names one does on the Positions page.
watchEffect(() => {
  if (onlySymbol.value && !props.allSymbols.some(s => s.symbol === onlySymbol.value)) {
    onlySymbol.value = ''
  }
})

// The year and the brokerage together, each kept when the other changes: a year stepped to
// would otherwise hand the page back to every brokerage, and the dropdown would be lying.
// Off the URL when it is all of them, as the year is when it is this one's default.
const visit = ({ year = props.year, broker = props.broker }) =>
  router.get(
    '/dividends',
    { year, ...(broker ? { broker } : {}) },
    { preserveScroll: true, preserveState: true },
  )

const choose = year => visit({ year })
const chooseBroker = broker => visit({ broker })

const brokerOptions = computed(() => [
  { label: 'All brokerages', value: 0, total: null },
  ...props.brokers.map(b => ({ label: b.name, value: b.id, total: money(b.total) })),
])

const yearOptions = computed(() =>
  props.years.map(y => ({ label: String(y.year), value: y.year, total: money(y.total) })),
)

// Newest first, so the one before in the list is the newer year.
const at = computed(() => props.years.findIndex(y => y.year === props.year))
const newer = computed(() => props.years[at.value - 1]?.year ?? null)
const older = computed(() => props.years[at.value + 1]?.year ?? null)

// How many hold a colour, and below what share of the year one is too thin to name --
// PositionAllocation's rule, so the two charts agree on what is too small to point at.
const named = 8
const smallest = 0.03

// What each symbol has paid in all, which is the order its colour comes from, so a colour
// means one symbol on every year rather than a different one each time the year changes.
// The entry is kept whole rather than just its position, because the table's all-time
// column is the same figure and one lookup should not be able to disagree with the other.
const allTime = computed(() => new Map(props.allSymbols.map((s, i) => [s.symbol, { ...s, at: i }])))

const ranked = computed(() =>
  props.symbols.map(symbol => {
    // A symbol the all-time list does not name is past the palette, so it reads as Others
    // rather than taking a colour from one that is.
    const entry = allTime.value.get(symbol.symbol)
    const at = entry?.at ?? props.allSymbols.length
    const share = Number(props.total) > 0 ? Number(symbol.total) / Number(props.total) : 0
    const other = at >= named || share < smallest

    return {
      ...symbol,
      // The neutral when it is in Others, not merely flagged as such: the palette is ten
      // long and the cut is eight, so a symbol past the cut still has a colour of its own
      // to hand, and the table's swatch would claim it while the chart drew it grey.
      colour: other ? neutral : seriesColour(at),
      other,
      // A symbol the forecast expects but that has never paid is not in the list at all,
      // so this is zero rather than blank: nothing is what it has been paid.
      allTime: entry?.total ?? '0',
      years: entry?.years ?? 0,
    }
  }),
)

const isCurrent = computed(() => props.today.startsWith(String(props.year)))

// A comparison for reading, not money, so a float percentage is fine. One decimal, not
// none: a change that rounded away to nothing still showed its sign, and "-0%" says less
// than "-0.1%" does about a year that fell a little.
const change = (now, before) => {
  const [a, b] = [Number(now), Number(before)]

  if (b <= 0) return null

  const percent = ((a - b) / b) * 100

  return {
    label: `${percent >= 0 ? '+' : ''}${percent.toFixed(1)}%`,
    class: percent >= 0 ? 'text-positive' : 'text-negative',
  }
}

const monthsSoFar = computed(() => (isCurrent.value ? Number(props.today.slice(5, 7)) : 12))

// Each year against the one before it, oldest first as the bars run, so the earliest has
// none to compare and shows nothing rather than a change from nothing. The comparison is
// within the filtered series, so a symbol's "+61%" is that symbol against itself and not
// the page against itself. A year the symbol did not pay in is zero rather than dropped:
// the empty column is what says the year went by without it.
const yearsWithChange = computed(() => {
  const ordered = [...props.years].reverse().map(entry => ({ ...entry, total: yearTotal(entry) }))

  return ordered.map((entry, i) => ({
    ...entry,
    change: i === 0 ? null : change(entry.total, ordered[i - 1].total),
  }))
})

// A symbol paid nothing in a year: zero, not absent, or the bar would be missing rather
// than empty and the years would not line up against each other.
const yearTotal = entry =>
  onlySymbol.value ? (entry.bySymbol?.[onlySymbol.value] ?? '0') : entry.total

// A share for reading, not money: two places, since a yield is a few percent and a tenth of one
// is a tenth of its size.
const asYield = (part, whole) => `${((Number(part) / Number(whole)) * 100).toFixed(2)}%`

// What the year's payments are of what was held. Against the cost and not the market value:
// it is the yield on what was paid for it, which does not move with the price. The year in
// progress also says what it comes to with the rest of what is expected, since a yield on
// four months of a year reads low beside one on a whole one.
const yieldTile = computed(() => {
  const base = { label: 'Yield on cost', class: 'text-grey-9' }

  if (props.cost === null) {
    return {
      ...base,
      value: '—',
      class: 'text-grey-7',
      note: `No ${props.costUnconverted.join(', ')} rate, so what it cost is not known`,
    }
  }

  if (!(Number(props.cost) > 0)) {
    return { ...base, value: '—', class: 'text-grey-7', note: 'Nothing held' }
  }

  const held = `on a cost of ${money(props.cost)}${isCurrent.value ? '' : ` at the end of ${props.year}`}`
  const inAll =
    isCurrent.value && Number(props.expected) > 0
      ? ` · ~${asYield(Number(props.total) + Number(props.expected), props.cost)} with what is expected`
      : ''

  return { ...base, value: asYield(props.total, props.cost), note: `${held}${inAll}` }
})

const tiles = computed(() => {
  const vsLast = change(props.total, props.previous)
  const tiles = [
    {
      label: `Paid in ${props.year}`,
      value: money(props.total),
      class: 'text-positive',
      note: `${props.payments} payment${props.payments === 1 ? '' : 's'} from ${ranked.value.filter(s => Number(s.total) > 0).length} symbols`,
      total: true,
    },
    yieldTile.value,
    {
      label: `Against ${props.year - 1}`,
      value: vsLast?.label ?? '—',
      class: vsLast?.class ?? 'text-grey-7',
      note: `${props.year - 1} paid ${money(props.previous)}${isCurrent.value ? ' in all' : ''}`,
    },
    {
      label: 'A month on average',
      value: money((Number(props.total) / Math.max(monthsSoFar.value, 1)).toFixed(2)),
      class: 'text-grey-9',
      note: isCurrent.value ? `over the ${monthsSoFar.value} months so far` : 'over the year',
    },
  ]

  if (isCurrent.value) {
    tiles.push({
      label: 'Still expected this year',
      value: `~${money(props.expected)}`,
      class: 'app-text-estimate',
      note: `last year's payments a year on, for about ${money((Number(props.total) + Number(props.expected)).toFixed(2))} in all`,
    })
  } else {
    const best = props.months.reduce(
      (top, v, i) => (Number(v) > Number(props.months[top]) ? i : top),
      0,
    )

    tiles.push({
      label: 'Best month',
      value: monthInitials[best].name,
      class: 'text-grey-9',
      note: money(props.months[best]),
    })
  }

  return tiles
})

const monthName = new Intl.DateTimeFormat('en', { month: 'long', timeZone: 'UTC' })
const monthInitials = Array.from({ length: 12 }, (_, i) => {
  const name = monthName.format(new Date(Date.UTC(2000, i, 1)))

  return { key: i, label: String(i + 1), name }
})

const years = computed(() => props.years)
const thisYear = computed(() => Number(props.today.slice(0, 4)))

// Only on this year's page does the server work out what is still expected, and a
// filtered bar needs its own share of that: the whole year's figure on one symbol's bar
// would be every symbol's expectation stacked on it, which is worse than not filtering.
const expectedThisYear = computed(() => {
  if (!isCurrent.value) return '0'

  return onlySymbol.value ? (props.expectedBySymbol?.[onlySymbol.value] ?? '0') : props.expected
})

// The tallest bar, within the series being shown. Off the page's own years rather than
// the filtered ones, one symbol's bars would be a sliver against a total ten times their
// size and the years beside it would say nothing about it.
const yearsTotal = computed(() => props.years.reduce((total, y) => total + Number(y.total), 0))

const yearPeak = computed(() =>
  Math.max(
    1,
    ...yearsWithChange.value.map(
      y => Number(y.total) + (y.year === thisYear.value ? Number(expectedThisYear.value) : 0),
    ),
  ),
)

const compact = value =>
  new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(
    Number(value),
  )

// A bar's height as a share of the tallest one, handed to CSS as a custom property so the
// bar, the expected top above it and the captions riding on that top are all placed off
// the same figure.
const barHeight = value => `${(Number(value) / yearPeak.value) * 100}%`

// Only this year's bar carries the dashed top, so every other year's is nothing.
const expectHeight = entry =>
  entry.year === thisYear.value ? barHeight(expectedThisYear.value) : '0%'

const share = amount =>
  Number(props.total) > 0 ? `${Math.round((Number(amount) / Number(props.total)) * 100)}%` : ''

const growth = symbol => change(symbol.total, symbol.previous)

const caption = symbol => {
  const parts = [`${symbol.payments} payment${symbol.payments === 1 ? '' : 's'}`]

  if (symbol.last) parts.push(`last ${formatDay(symbol.last)}`)
  if (symbol.brokers.length) parts.push(symbol.brokers.join(', '))

  return parts.join(' · ')
}

// Its colour, stronger for a larger payment against the row's own largest, so a symbol's
// rhythm reads whatever its size. Widths and shades only, so floats.
const cellStyle = (symbol, i) => {
  const peak = Math.max(...symbol.months.map(Number), ...symbol.expected_months.map(Number))
  const paid = Number(symbol.months[i])

  if (paid <= 0 || peak <= 0) return {}

  const strength = Math.round(25 + (paid / peak) * 75)

  return { background: `color-mix(in srgb, ${symbol.colour} ${strength}%, white)` }
}

const openPayments = symbol =>
  router.visit('/transactions', {
    data: {
      filter: {
        type: 'dividend',
        ...(symbol.symbol !== '—' ? { symbol: symbol.symbol } : {}),
        date_from: `${props.year}-01-01`,
        date_to: `${props.year}-12-31`,
      },
    },
  })
</script>
