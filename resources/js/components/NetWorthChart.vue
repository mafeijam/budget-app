<template>
  <div>
    <div class="row items-center q-gutter-md text-caption text-grey-8 q-mb-sm">
      <div
        v-for="series in shownLegend"
        :key="series.key"
        class="app-chart-legend row items-center no-wrap"
        :class="{ 'app-chart-legend--off': !shows(series.key) }"
        role="button"
        tabindex="0"
        :aria-pressed="shows(series.key)"
        @click="toggle(series.key)"
        @keydown.enter.space.prevent="toggle(series.key)"
      >
        <span
          :class="series.area ? 'cash-flow-chart__swatch' : 'cash-flow-chart__line'"
          :style="{ background: series.colour }"
        />{{ series.label }}
      </div>
      <div
        v-for="band in bands"
        :key="band.key"
        class="app-chart-legend row items-center no-wrap"
        :class="{ 'app-chart-legend--off': !shows(band.key) }"
        role="button"
        tabindex="0"
        :aria-pressed="shows(band.key)"
        @click="toggle(band.key)"
        @keydown.enter.space.prevent="toggle(band.key)"
      >
        <span
          class="cash-flow-chart__swatch"
          :style="{ background: band.colour, opacity: 0.35 }"
        />{{ band.label }}
      </div>
    </div>

    <div class="relative-position">
      <svg :viewBox="`0 0 ${width} ${height}`" class="full-width" role="img" :aria-label="label">
        <g v-for="tick in ticks" :key="tick">
          <line
            :x1="left"
            :x2="width - right"
            :y1="y(tick)"
            :y2="y(tick)"
            :stroke="tick === 0 ? colours.baseline : colours.grid"
            stroke-width="1"
          />
          <text
            :x="left - 8"
            :y="y(tick)"
            text-anchor="end"
            dominant-baseline="middle"
            class="cash-flow-chart__tick"
          >
            {{ compact(tick) }}
          </text>
        </g>

        <line
          v-if="hovered !== null"
          :x1="x(hovered)"
          :x2="x(hovered)"
          :y1="top"
          :y2="bottom"
          :stroke="colours.baseline"
          stroke-dasharray="3 3"
        />

        <!-- Above and below zero, so cash can change colour where it crosses. -->
        <defs>
          <clipPath :id="`${uid}-above`">
            <rect x="0" y="0" :width="width" :height="y(0)" />
          </clipPath>
          <clipPath :id="`${uid}-below`">
            <rect x="0" :y="y(0)" :width="width" :height="height" />
          </clipPath>
        </defs>

        <!-- Fills first, so the lines and the marker sit on top of them. -->
        <path v-if="shows('value')" :d="area('value')" :fill="colours.value" fill-opacity="0.16" />
        <path
          v-if="shows('cash')"
          :d="area('cash')"
          :clip-path="`url(#${uid}-above)`"
          :fill="colours.cash"
          fill-opacity="0.18"
        />
        <!-- Lighter than the cash's fill below zero, which is drawn over it, so the two separate. -->
        <path
          v-if="owing && shows('loans')"
          :d="area('loans')"
          :fill="colours.loans"
          fill-opacity="0.12"
        />
        <path
          v-if="shows('cash')"
          :d="area('cash')"
          :clip-path="`url(#${uid}-below)`"
          :fill="colours.overdrawn"
          fill-opacity="0.25"
        />
        <path
          v-for="(piece, i) in shownGaps"
          :key="`gap-${i}`"
          :d="piece.d"
          :fill="piece.gain ? colours.value : colours.cost"
          :fill-opacity="piece.gain ? 0.3 : 0.25"
        />

        <polyline
          v-for="series in shownLines"
          :key="series.id ?? series.key"
          :points="line(series.key)"
          fill="none"
          :stroke="series.colour"
          :clip-path="series.clip ? `url(#${uid}-${series.clip})` : null"
          stroke-width="1.5"
          :stroke-dasharray="series.dashed ? '5 4' : null"
          stroke-linejoin="round"
          stroke-linecap="round"
        />

        <circle
          v-if="points[selectedIndex] && shows('net_worth')"
          :cx="x(selectedIndex)"
          :cy="y(points[selectedIndex].net_worth)"
          r="6"
          :fill="colours.net_worth"
          stroke="#ffffff"
          stroke-width="2"
        />

        <text
          v-for="tick in axis"
          :key="`axis-${tick.i}`"
          :x="x(tick.i)"
          :y="height - 8"
          text-anchor="middle"
          class="cash-flow-chart__tick"
        >
          {{ tick.label }}
        </text>

        <!-- Last, so a whole column is the hover target rather than the thin marks. -->
        <rect
          v-for="(point, i) in points"
          :key="`hit-${point.date}`"
          :x="x(i) - step / 2"
          :y="top"
          :width="step"
          :height="bottom - top"
          fill="transparent"
          class="cursor-pointer"
          @mouseenter="hovered = i"
          @mouseleave="hovered = null"
          @click="emit('select', point.date)"
        />
      </svg>

      <div v-if="hovered !== null" class="cash-flow-chart__tooltip" :style="tooltipStyle">
        <div class="text-weight-bold q-mb-xs">{{ points[hovered].long }}</div>
        <div v-for="row in tooltipRows" :key="row.label" class="row no-wrap items-center">
          <span class="cash-flow-chart__swatch" :style="{ background: row.colour }" />
          <span class="q-mr-md">{{ row.label }}</span>
          <q-space />
          <span class="money text-weight-medium">{{ row.value }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  history: { type: Array, default: () => [] },
  base: { type: String, default: '' },
  months: { type: Number, default: 1 },
  // The snapshot the cards show, marked on the chart.
  selected: { type: String, default: null },
})

const emit = defineEmits(['select'])

const money = useMoney()

// Cash in the app's positive, stocks in its primary, cost as a dashed line in the negative
// so the gap to the market value reads as the unrealised gain or loss. What the cards owe
// takes no colour here: at a few tens of thousands against a couple of million it is a
// hairline along zero, and paying a third of the plot's height for a line too small to read
// costs the four series that are legible more than the debt's absence does. It is the
// Cash card's total row instead.
//
// A loan does take one, below zero: it is subtracted from net worth, unlike the cards, and at
// a few hundred thousand it reads. Drawn downwards with a fill, so it reads as a debt against
// the cash and stocks above rather than as one more thing held.
const colours = {
  net_worth: '#475569',
  cash: '#059669',
  value: '#2563eb',
  cost: '#e11d48',
  loans: '#d97706',
  overdrawn: '#f43f5e',
  grid: '#f8e6b6',
  baseline: '#c9a96a',
}

const legend = [
  { key: 'net_worth', label: 'Net worth', colour: colours.net_worth },
  { key: 'cash', label: 'Cash', colour: colours.cash, area: true },
  { key: 'value', label: 'Stock value', colour: colours.value, area: true },
  { key: 'cost', label: 'Stock cost', colour: colours.cost },
  { key: 'loans', label: 'Loans owed', colour: colours.loans, area: true },
]

const bands = [
  { key: 'gain', label: 'Above cost', colour: colours.value },
  { key: 'loss', label: 'Below cost', colour: colours.cost },
]

// What the legend has switched off, by key, remembered across visits. A key the chart no
// longer draws is harmless and kept, as the loans entry comes and goes with the window.
const hidden = useStorage('netWorth.hidden', [])
const shows = key => !hidden.value.includes(key)
const toggle = key => {
  hidden.value = shows(key) ? [...hidden.value, key] : hidden.value.filter(k => k !== key)
}

// Painted in this order, so the net worth line is over every fill beneath it.
const lines = [
  { key: 'cost', colour: colours.cost, dashed: true },
  // Cash below zero is rose, line and fill: money missing rather than held. Rose rather than the
  // red proper, which is the stock cost, so it does not read as one more thing about the stocks.
  { key: 'cash', colour: colours.cash, clip: 'above' },
  { key: 'cash', id: 'cash-below', colour: colours.overdrawn, clip: 'below' },
  { key: 'value', colour: colours.value },
  { key: 'loans', colour: colours.loans },
  { key: 'net_worth', colour: colours.net_worth },
]

// The loan only where the window has one: a legend entry for a line flat on zero says nothing.
const owing = computed(() => points.value.some(point => point.numbers.loans !== 0))
const shownLegend = computed(() => legend.filter(series => series.key !== 'loans' || owing.value))
const shownLines = computed(() =>
  lines.filter(series => (series.key !== 'loans' || owing.value) && shows(series.key)),
)

// The clip paths' ids, which must be unique on the page.
const uid = useId()

const width = 960
const height = 300
const left = 64
const right = 16
const top = 12
const bottom = height - 32

const hovered = ref(null)

const selectedIndex = computed(() => {
  const i = props.history.findIndex(point => point.date === props.selected)

  return i === -1 ? null : i
})

const shortDate = new Intl.DateTimeFormat('en', {
  month: 'short',
  year: '2-digit',
  timeZone: 'UTC',
})
const yearDate = new Intl.DateTimeFormat('en', { year: 'numeric', timeZone: 'UTC' })
const longDate = new Intl.DateTimeFormat('en', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
})

const asDate = day => {
  const [year, month, date] = day.split('-').map(Number)

  return new Date(Date.UTC(year, month - 1, date))
}

// Numbers only for geometry: every figure shown is formatted from the server's string.
const points = computed(() =>
  props.history.map(point => ({
    ...point,
    numbers: {
      net_worth: Number(point.net_worth),
      cash: Number(point.cash),
      value: Number(point.value),
      cost: Number(point.cost),
      loans: -Number(point.loans ?? 0),
    },
    short: (props.months === 12 ? yearDate : shortDate).format(asDate(point.date)),
    long: longDate.format(asDate(point.date)),
  })),
)

const niceStep = raw => {
  const magnitude = 10 ** Math.floor(Math.log10(raw))
  const fraction = raw / magnitude

  return (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10) * magnitude
}

// Rescaled to what is drawn, so hiding the loans gives their strip below zero back to the
// rest. A band is drawn between the value and the cost, so either stays in the scale while
// a band shows, even with its own line off, or the band would run off the plot.
const scaled = computed(() => {
  const bandShown = bands.some(band => shows(band.key))

  return Object.keys(points.value[0]?.numbers ?? {}).filter(
    key => shows(key) || (bandShown && (key === 'value' || key === 'cost')),
  )
})

const scale = computed(() => {
  const values = points.value.flatMap(point => scaled.value.map(key => point.numbers[key]))
  const peak = Math.max(1, ...values)

  // A fifth of the peak, not a quarter: at a quarter, a 2.2M ledger asks for 550K, which the
  // ladder can only round up to 1M and so drew three gridlines where five were wanted. A fifth
  // asks 440K, which lands on 500K exactly -- the rung already there, rather than one above.
  // Five intervals is also the better density on a plot this height.
  const tick = niceStep(peak / 5)

  return {
    tick,
    high: Math.ceil(peak / tick) * tick,
    low: Math.ceil(Math.max(0, -Math.min(0, ...values)) / tick) * tick,
  }
})

const ticks = computed(() => {
  const { tick, high, low } = scale.value
  const list = []

  // `|| 0`, or a scale with nothing below zero starts at -0 and labels it so.
  for (let value = -low || 0; value <= high; value += tick) list.push(value)

  return list
})

const y = value => {
  const { high, low } = scale.value

  return top + ((high - Number(value)) / (high + low || 1)) * (bottom - top)
}

const step = computed(() => (width - left - right) / Math.max(points.value.length - 1, 1))

const x = i => (points.value.length === 1 ? (left + width - right) / 2 : left + i * step.value)

// Samples per gap between two snapshots, enough for the curve to read as smooth.
const curve = key =>
  monotoneCurve(
    points.value.map(point => point.numbers[key]),
    x(0),
    step.value,
  )

const curves = computed(() =>
  Object.fromEntries(['net_worth', 'cash', 'value', 'cost', 'loans'].map(key => [key, curve(key)])),
)

const line = key => curves.value[key].map(([px, value]) => `${px},${y(value)}`).join(' ')

const area = key => {
  const sampled = curves.value[key]

  if (!sampled.length) return ''

  return `M${sampled[0][0]},${y(0)} L${line(key).replaceAll(' ', ' L')} L${sampled.at(-1)[0]},${y(0)} Z`
}

// The gap between the market value and the cost: blue while the stocks are worth their
// cost or more, red while below. Built on the sampled curves and split where they cross,
// so each colour stops exactly at the crossing.
const gaps = computed(() => {
  const value = curves.value.value
  const cost = curves.value.cost
  const pieces = []
  let run = []

  const close = gain => {
    if (run.length > 1) {
      const top = run.map(([px, v]) => `${px},${y(v)}`)
      const bottom = [...run].reverse().map(([px, , c]) => `${px},${y(c)}`)

      pieces.push({ gain, d: `M${[...top, ...bottom].join(' L')} Z` })
    }

    run = []
  }

  for (let i = 0; i < value.length; i++) {
    const [px, v] = value[i]
    const c = cost[i][1]

    if (i > 0) {
      const [qx, pv] = value[i - 1]
      const pc = cost[i - 1][1]

      // A crossing between two samples: the run ends at the meeting point, and the next
      // one starts there.
      if ((pv - pc) * (v - c) < 0) {
        const t = (pv - pc) / (pv - pc - (v - c))
        const meet = [qx + (px - qx) * t, pv + (v - pv) * t, pv + (v - pv) * t]

        run.push(meet)
        close(pv >= pc)
        run.push(meet)
      }
    }

    run.push([px, v, c])
  }

  close(value.length ? value.at(-1)[1] >= cost.at(-1)[1] : true)

  return pieces
})

const shownGaps = computed(() => gaps.value.filter(piece => shows(piece.gain ? 'gain' : 'loss')))

// About twelve labels at most, so a monthly history of years stays legible. Today's point
// is always labelled.
const axis = computed(() => {
  const all = points.value
  const every = Math.max(1, Math.ceil(all.length / 12))
  // The window's end, which is labelled whatever day it falls on: it is the one the reader
  // is looking back from, and thinning the labels would drop it in favour of an older one.
  const last = all.length - 1

  return all
    .map((point, i) => ({ i, label: point.short }))
    .filter(({ i }) => i === last || (i % every === 0 && last - i >= every / 2))
})

const compact = value =>
  new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(value)

const label = computed(() => `Net worth, cash, stock value and stock cost, in ${props.base}`)

const tooltipRows = computed(() => {
  const point = points.value[hovered.value]

  return shownLegend.value
    .filter(series => shows(series.key))
    .map(series => ({
      label: series.label,
      // Owed is below zero, as the cards page shows it.
      value: money(series.key === 'loans' ? minus('0', point.loans ?? '0') : point[series.key]),
      colour: series.colour,
    }))
})

// Flipped to the left of the point past the middle, so it never runs off the card.
const tooltipStyle = computed(() => {
  const at = (x(hovered.value) / width) * 100
  const flip = at > 60

  return {
    left: flip ? 'auto' : `calc(${at}% + 16px)`,
    right: flip ? `calc(${100 - at}% + 16px)` : 'auto',
    top: '8px',
  }
})
</script>
