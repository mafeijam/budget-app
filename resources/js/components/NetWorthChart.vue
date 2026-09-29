<template>
  <div>
    <div class="row items-center q-gutter-md text-caption text-grey-8 q-mb-sm">
      <div v-for="series in legend" :key="series.key" class="row items-center no-wrap">
        <span
          :class="series.area ? 'cash-flow-chart__swatch' : 'cash-flow-chart__line'"
          :style="{ background: series.colour }"
        />{{ series.label }}
      </div>
      <div v-for="band in bands" :key="band.label" class="row items-center no-wrap">
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

        <!-- The stretch ahead of today, so a projection never reads as the record. -->
        <rect
          v-if="ahead.length"
          :x="x(points.length - 1)"
          :y="top"
          :width="width - right - x(points.length - 1)"
          :height="bottom - top"
          :fill="colours.ahead"
        />

        <line
          v-if="hovered !== null"
          :x1="x(hovered)"
          :x2="x(hovered)"
          :y1="top"
          :y2="bottom"
          :stroke="colours.baseline"
          stroke-dasharray="3 3"
        />

        <!-- Fills first, so the lines and dots sit on top of them. -->
        <path :d="area('value')" :fill="colours.value" fill-opacity="0.16" />
        <path :d="area('cash')" :fill="colours.cash" fill-opacity="0.18" />
        <path
          v-for="(piece, i) in gaps"
          :key="`gap-${i}`"
          :d="piece.d"
          :fill="piece.gain ? colours.value : colours.cost"
          :fill-opacity="piece.gain ? 0.3 : 0.25"
        />

        <polyline
          v-for="series in lines"
          :key="series.key"
          :points="line(series.key)"
          fill="none"
          :stroke="series.colour"
          stroke-width="2"
          :stroke-dasharray="series.dashed ? '5 4' : null"
          stroke-linejoin="round"
          stroke-linecap="round"
        />

        <template v-if="ahead.length">
          <polyline
            :points="projected('value')"
            fill="none"
            :stroke="colours.value"
            stroke-width="1.5"
            stroke-dasharray="3 4"
            stroke-opacity="0.6"
          />
          <polyline
            :points="projected('figure')"
            fill="none"
            :stroke="colours.net_worth"
            stroke-width="2"
            stroke-dasharray="6 4"
            stroke-linecap="round"
          />
          <circle
            v-for="(point, j) in ahead"
            :key="`ahead-${point.date}`"
            :cx="x(points.length + j)"
            :cy="y(point.numbers.figure)"
            r="3"
            fill="#ffffff"
            :stroke="colours.net_worth"
            stroke-width="1.5"
          />
        </template>

        <template v-for="(point, i) in points" :key="point.date">
          <circle
            :cx="x(i)"
            :cy="y(point.net_worth)"
            :r="i === selectedIndex ? 6 : 3"
            :fill="colours.net_worth"
            stroke="#ffffff"
            :stroke-width="i === selectedIndex ? 2 : 1"
          />
        </template>

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
        <rect
          v-for="(point, j) in ahead"
          :key="`hit-ahead-${point.date}`"
          :x="x(points.length + j) - step / 2"
          :y="top"
          :width="step"
          :height="bottom - top"
          fill="transparent"
          @mouseenter="hovered = points.length + j"
          @mouseleave="hovered = null"
        />
      </svg>

      <div v-if="hovered !== null" class="cash-flow-chart__tooltip" :style="tooltipStyle">
        <div class="text-weight-bold q-mb-xs">
          {{ hoveredPoint.long }}
          <span v-if="hovered >= points.length" class="text-grey-7 text-weight-regular">
            · projected
          </span>
        </div>
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
  // Points ahead of today, drawn dashed after the history.
  projection: { type: Array, default: () => [] },
  withTypical: { type: Boolean, default: false },
})

const emit = defineEmits(['select'])

const money = useMoney()

// Cash in the app's positive, stocks in its primary, cost as a dashed line in the negative
// so the gap to the market value reads as the unrealised gain or loss.
const colours = {
  net_worth: '#475569',
  cash: '#059669',
  value: '#2563eb',
  cost: '#e11d48',
  grid: '#e2e8f0',
  baseline: '#94a3b8',
  ahead: '#f8fafc',
}

const legend = [
  { key: 'net_worth', label: 'Net worth', colour: colours.net_worth },
  { key: 'cash', label: 'Cash', colour: colours.cash, area: true },
  { key: 'value', label: 'Stock value', colour: colours.value, area: true },
  { key: 'cost', label: 'Stock cost', colour: colours.cost },
]

const bands = [
  { label: 'Above cost', colour: colours.value },
  { label: 'Below cost', colour: colours.cost },
]

const lines = [
  { key: 'cost', colour: colours.cost, dashed: true },
  { key: 'cash', colour: colours.cash },
  { key: 'value', colour: colours.value },
  { key: 'net_worth', colour: colours.net_worth },
]

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
    },
    short: (props.months === 12 ? yearDate : shortDate).format(asDate(point.date)),
    long: longDate.format(asDate(point.date)),
  })),
)

const ahead = computed(() =>
  props.projection.map(point => {
    const figure = props.withTypical ? point.with_typical : point.net_worth

    return {
      ...point,
      figure,
      numbers: { figure: Number(figure), value: Number(point.value) },
      short: (props.months === 12 ? yearDate : shortDate).format(asDate(point.date)),
      long: longDate.format(asDate(point.date)),
    }
  }),
)

const total = computed(() => points.value.length + ahead.value.length)

const hoveredPoint = computed(() =>
  hovered.value < points.value.length
    ? points.value[hovered.value]
    : ahead.value[hovered.value - points.value.length],
)

const niceStep = raw => {
  const magnitude = 10 ** Math.floor(Math.log10(raw))
  const fraction = raw / magnitude

  return (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10) * magnitude
}

const scale = computed(() => {
  const values = [...points.value, ...ahead.value].flatMap(point => Object.values(point.numbers))
  const peak = Math.max(1, ...values)
  const tick = niceStep(peak / 4)

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

const step = computed(() => (width - left - right) / Math.max(total.value - 1, 1))

const x = i => (total.value === 1 ? (left + width - right) / 2 : left + i * step.value)

// From today's point on, so the dashed line carries on from the solid one.
const projected = key => {
  const last = points.value.at(-1)

  if (!last) return ''

  const start = key === 'figure' ? last.numbers.net_worth : last.numbers.value

  return monotoneCurve(
    [start, ...ahead.value.map(point => point.numbers[key])],
    x(points.value.length - 1),
    step.value,
  )
    .map(([px, value]) => `${px},${y(value)}`)
    .join(' ')
}

// Samples per gap between two snapshots, enough for the curve to read as smooth.
const curve = key =>
  monotoneCurve(
    points.value.map(point => point.numbers[key]),
    x(0),
    step.value,
  )

const curves = computed(() =>
  Object.fromEntries(['net_worth', 'cash', 'value', 'cost'].map(key => [key, curve(key)])),
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

// About twelve labels at most over history and projection together, so a monthly
// history of years stays legible. Today's point is always labelled.
const axis = computed(() => {
  const all = [...points.value, ...ahead.value]
  const every = Math.max(1, Math.ceil(all.length / 12))
  const today = points.value.length - 1

  return all
    .map((point, i) => ({ i, label: point.short }))
    .filter(
      ({ i }) =>
        i === today ||
        i === all.length - 1 ||
        (i % every === 0 && Math.abs(i - today) >= every / 2 && all.length - 1 - i >= every / 2),
    )
})

const compact = value =>
  new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(value)

const label = computed(() => `Net worth, cash, stock value and stock cost, in ${props.base}`)

const tooltipRows = computed(() => {
  if (hovered.value >= points.value.length) {
    const point = hoveredPoint.value

    return [
      { label: 'Net worth', value: money(point.figure), colour: colours.net_worth },
      { label: 'Stock value', value: money(point.value), colour: colours.value },
    ]
  }

  const point = points.value[hovered.value]

  return legend.map(series => ({
    label: series.label,
    value: money(point[series.key]),
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
