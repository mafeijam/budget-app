<template>
  <div class="cash-flow-chart">
    <div class="row items-center q-gutter-md text-caption text-grey-8 q-mb-sm">
      <div class="row items-center no-wrap">
        <span class="cash-flow-chart__swatch" :style="{ background: colours.income }" />Income
      </div>
      <div class="row items-center no-wrap">
        <span class="cash-flow-chart__swatch" :style="{ background: colours.spending }" />Spending
      </div>
      <div class="row items-center no-wrap">
        <span class="cash-flow-chart__line" :style="{ background: colours.net }" />Net
      </div>
    </div>

    <div class="relative-position">
      <svg :viewBox="`0 0 ${width} ${height}`" class="full-width" role="img" :aria-label="label">
        <rect
          v-if="hovered !== null"
          :x="band(hovered)"
          :y="top"
          :width="step"
          :height="bottom - top"
          :fill="colours.hover"
        />

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

        <g v-for="(month, i) in points" :key="month.month">
          <path v-if="month.income > 0" :d="column(i, month.income, 1)" :fill="colours.income" />
          <path
            v-if="month.spending > 0"
            :d="column(i, month.spending, -1)"
            :fill="colours.spending"
          />
          <text :x="centre(i)" :y="height - 18" text-anchor="middle" class="cash-flow-chart__tick">
            {{ month.short }}
          </text>
          <text
            v-if="month.year"
            :x="centre(i)"
            :y="height - 4"
            text-anchor="middle"
            class="cash-flow-chart__tick"
          >
            {{ month.year }}
          </text>
        </g>

        <!-- Over the bars, faint enough that they read through it. -->
        <path :d="netArea" :fill="colours.net" fill-opacity="0.15" />

        <polyline
          :points="netLine"
          fill="none"
          :stroke="colours.net"
          stroke-width="2"
          stroke-linejoin="round"
          stroke-linecap="round"
        />

        <!-- Last, so the whole band is the hover target rather than the thin marks. -->
        <rect
          v-for="(month, i) in points"
          :key="`hit-${month.month}`"
          :x="band(i)"
          :y="top"
          :width="step"
          :height="bottom - top"
          fill="transparent"
          @mouseenter="hovered = i"
          @mouseleave="hovered = null"
        />
      </svg>

      <div v-if="hovered !== null" class="cash-flow-chart__tooltip" :style="tooltipStyle">
        <div class="text-weight-bold q-mb-xs">{{ months[hovered].label }}</div>
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
  months: { type: Array, default: () => [] },
  ccy: { type: String, default: '' },
})

const money = useMoney()

// The app's positive and negative, as the tables use for money in and out; the net in amber,
// a hue apart from both so its line reads over either bar.
const colours = {
  income: '#059669',
  spending: '#dc2626',
  net: '#f59e0b',
  grid: '#e2e8f0',
  baseline: '#94a3b8',
  hover: '#f1f5f9',
}

const width = 960
const height = 280
const left = 64
const right = 12
const top = 12
const bottom = height - 40

const hovered = ref(null)

const monthName = new Intl.DateTimeFormat('en', { month: 'short', timeZone: 'UTC' })
const monthLabel = new Intl.DateTimeFormat('en', {
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
})

// Numbers only for geometry: every figure shown is formatted from the server's string.
const points = computed(() =>
  props.months.map((month, i) => {
    const [year, number] = month.month.split('-').map(Number)
    const day = new Date(Date.UTC(year, number - 1, 1))

    return {
      month: month.month,
      income: Number(month.income),
      spending: Number(month.spending),
      net: Number(month.net),
      short: monthName.format(day),
      year: i === 0 || number === 1 ? String(year) : '',
    }
  }),
)

const niceStep = raw => {
  const magnitude = 10 ** Math.floor(Math.log10(raw))
  const fraction = raw / magnitude

  return (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10) * magnitude
}

const scale = computed(() => {
  const peak = Math.max(1, ...points.value.flatMap(m => [m.income, m.spending, Math.abs(m.net)]))
  const tick = niceStep(peak / 2)
  const high =
    Math.ceil(Math.max(...points.value.map(m => Math.max(m.income, m.net)), 0) / tick) * tick
  const low =
    Math.ceil(Math.max(...points.value.map(m => Math.max(m.spending, -m.net)), 0) / tick) * tick

  return { tick, high: high || tick, low }
})

const ticks = computed(() => {
  const { tick, high, low } = scale.value
  const list = []

  for (let value = -low; value <= high; value += tick) list.push(value)

  return list
})

const y = value => {
  const { high, low } = scale.value

  return top + ((high - value) / (high + low)) * (bottom - top)
}

const step = computed(() => (width - left - right) / Math.max(points.value.length, 1))
const band = i => left + i * step.value
const centre = i => band(i) + step.value / 2

// Capped at 36px, square at the baseline and rounded 4px at the data end, with a 1px
// surface gap either side of the zero line.
const column = (i, value, direction) => {
  const w = Math.min(36, step.value * 0.75)
  const x = centre(i) - w / 2
  const base = y(0) - direction
  const end = y(direction * value)
  const r = Math.min(4, Math.abs(end - base))

  if (Math.abs(end - base) < 0.5) return ''

  return direction > 0
    ? `M${x},${base} V${end + r} Q${x},${end} ${x + r},${end} H${x + w - r} Q${x + w},${end} ${x + w},${end + r} V${base} Z`
    : `M${x},${base} V${end - r} Q${x},${end} ${x + r},${end} H${x + w - r} Q${x + w},${end} ${x + w},${end - r} V${base} Z`
}

const netCurve = computed(() =>
  monotoneCurve(
    points.value.map(month => month.net),
    centre(0),
    step.value,
  ),
)

const netLine = computed(() => netCurve.value.map(([px, value]) => `${px},${y(value)}`).join(' '))

// Down to the zero line, not the chart's floor, so a month in deficit fills below zero.
const netArea = computed(() => {
  const sampled = netCurve.value

  if (!sampled.length) return ''

  return `M${sampled[0][0]},${y(0)} L${netLine.value.replaceAll(' ', ' L')} L${sampled.at(-1)[0]},${y(0)} Z`
})

const compact = value =>
  new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(value)

const label = computed(() => `Income, spending and net per month, in ${props.ccy}`)

const tooltipRows = computed(() => {
  const month = props.months[hovered.value]

  const rows = [
    { label: 'Income', value: money(month.income), colour: colours.income },
    { label: 'Spending', value: money(month.spending), colour: colours.spending },
    { label: 'Net', value: money(month.net), colour: colours.net },
  ]

  if (Number(month.invested) !== 0) {
    rows.push({ label: 'Invested', value: money(month.invested), colour: 'transparent' })
  }

  return rows
})

// Flipped to the left of the band past the middle, so it never runs off the card.
const tooltipStyle = computed(() => {
  const x = (centre(hovered.value) / width) * 100
  const flip = x > 60

  return {
    left: flip ? 'auto' : `calc(${x}% + 16px)`,
    right: flip ? `calc(${100 - x}% + 16px)` : 'auto',
    top: '8px',
  }
})

const months = computed(() =>
  props.months.map(month => {
    const [year, number] = month.month.split('-').map(Number)

    return { ...month, label: monthLabel.format(new Date(Date.UTC(year, number - 1, 1))) }
  }),
)
</script>
