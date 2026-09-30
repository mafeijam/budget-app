<template>
  <div class="column no-wrap q-gutter-lg">
    <div>
      <div class="row items-center">
        <div class="text-h6 text-weight-medium q-mr-md">Dividends</div>
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
        <div class="app-outlook">
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
          <div class="text-subtitle2 text-weight-medium q-mb-sm">Every year</div>
          <div class="app-dividend-years">
            <div
              v-for="entry in [...years].reverse()"
              :key="entry.year"
              class="app-dividend-year cursor-pointer"
              :class="{ 'app-dividend-year--on': entry.year === year }"
              @click="choose(entry.year)"
            >
              <div class="app-dividend-year__track">
                <div class="text-caption money text-grey-8 text-center">
                  {{ compact(entry.total) }}
                </div>
                <!-- This year's still expected on top, dashed, as the month chart has it. -->
                <div
                  v-if="entry.year === thisYear && Number(expectedThisYear) > 0"
                  class="app-dividend-year__expected"
                  :style="{ height: `${(Number(expectedThisYear) / yearPeak) * 100}%` }"
                />
                <div
                  class="app-dividend-year__fill"
                  :style="{ height: `${(Number(entry.total) / yearPeak) * 100}%` }"
                />
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
  unconverted: { type: Array, default: () => [] },
})

const money = useMoney()
const formatDay = useCalendarDay()

const choose = year =>
  router.get('/dividends', { year }, { preserveScroll: true, preserveState: true })

const yearOptions = computed(() =>
  props.years.map(y => ({ label: String(y.year), value: y.year, total: money(y.total) })),
)

// Newest first, so the one before in the list is the newer year.
const at = computed(() => props.years.findIndex(y => y.year === props.year))
const newer = computed(() => props.years[at.value - 1]?.year ?? null)
const older = computed(() => props.years[at.value + 1]?.year ?? null)

// The positions page's categorical palette in rank order; past it the neutral, so a colour
// always means one symbol, and the same symbol in the chart and the table.
const palette = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7']
const neutral = '#94a3b8'

const ranked = computed(() =>
  props.symbols.map((symbol, i) => ({
    ...symbol,
    colour: i < palette.length ? palette[i] : neutral,
    other: i >= palette.length,
  })),
)

const isCurrent = computed(() => props.today.startsWith(String(props.year)))

// A comparison for reading, not money, so a float percentage is fine.
const change = (now, before) => {
  const [a, b] = [Number(now), Number(before)]

  if (b <= 0) return null

  const percent = ((a - b) / b) * 100

  return {
    label: `${percent >= 0 ? '+' : ''}${percent.toFixed(0)}%`,
    class: percent >= 0 ? 'text-positive' : 'text-negative',
  }
}

const monthsSoFar = computed(() => (isCurrent.value ? Number(props.today.slice(5, 7)) : 12))

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

// Only on this year's page does the server work out what is still expected.
const expectedThisYear = computed(() => (isCurrent.value ? props.expected : '0'))

const yearPeak = computed(() =>
  Math.max(
    1,
    ...props.years.map(
      y => Number(y.total) + (y.year === thisYear.value ? Number(expectedThisYear.value) : 0),
    ),
  ),
)

const compact = value =>
  new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(
    Number(value),
  )

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
