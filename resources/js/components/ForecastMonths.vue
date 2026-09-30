<template>
  <div class="cash-flow-chart">
    <div class="row items-center q-gutter-md text-caption text-grey-8 q-mb-sm">
      <div class="row items-center no-wrap">
        <span class="cash-flow-chart__swatch" :style="{ background: colours.in }" />Known in
      </div>
      <div class="row items-center no-wrap">
        <span class="cash-flow-chart__swatch" :style="{ background: colours.out }" />Known out
      </div>
      <div v-if="typical" class="row items-center no-wrap">
        <span class="cash-flow-chart__swatch" :style="{ background: colours.typicalIn }" />Typical
        income
      </div>
      <div v-if="typical" class="row items-center no-wrap">
        <span class="cash-flow-chart__swatch" :style="{ background: colours.typical }" />Typical
        spending
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

        <g v-for="(month, i) in bars" :key="month.month">
          <path
            v-if="month.in > 0"
            :d="column(i, 0, month.in, 1, !typical || month.typicalIn <= 0)"
            :fill="colours.in"
          />
          <path
            v-if="typical && month.typicalIn > 0"
            :d="column(i, month.in, month.in + month.typicalIn, 1, true)"
            :fill="colours.typicalIn"
          />
          <path
            v-if="month.out > 0"
            :d="column(i, 0, month.out, -1, !typical || month.typical <= 0)"
            :fill="colours.out"
          />
          <!-- Lighter, beyond the known figure: an estimate, and drawn to look like one. -->
          <path
            v-if="typical && month.typical > 0"
            :d="column(i, month.out, month.out + month.typical, -1, true)"
            :fill="colours.typical"
          />
          <text :x="centre(i)" :y="height - 18" text-anchor="middle" class="cash-flow-chart__tick">
            {{ month.short }}
          </text>
          <text
            v-if="month.caption"
            :x="centre(i)"
            :y="height - 4"
            text-anchor="middle"
            class="cash-flow-chart__tick"
          >
            {{ month.caption }}
          </text>
        </g>

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
          v-for="(month, i) in bars"
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
        <div class="text-weight-bold q-mb-xs">{{ bars[hovered].label }}</div>
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
  // Projection months, oldest first: decimal strings from the server.
  months: { type: Array, default: () => [] },
  ccy: { type: String, default: '' },
  typical: { type: Boolean, default: true },
  // The month today falls in, as YYYY-MM.
  current: { type: String, default: '' },
})

const money = useMoney()

// The cash flow chart's colours, so a month ahead reads as a month behind does; the typical
// figures in pale shades of the same, since they are money but not yet anybody's fact.
const colours = {
  in: '#059669',
  out: '#dc2626',
  typical: '#fca5a5',
  typicalIn: '#86efac',
  net: '#f59e0b',
  grid: '#e2e8f0',
  baseline: '#94a3b8',
  hover: '#f1f5f9',
}

const width = 960
const height = 240
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

// The month today is in is only its days still to come; the page may have left it off.
const partial = month => month.month === props.current

// Numbers only for geometry: every figure shown is formatted from the server's string.
const bars = computed(() =>
  props.months.map(month => {
    const [year, number] = month.month.split('-').map(Number)
    const day = new Date(Date.UTC(year, number - 1, 1))

    return {
      month: month.month,
      in: Number(month.in),
      out: Number(month.out),
      typical: Number(month.typical),
      typicalIn: Number(month.typical_in),
      net: Number(props.typical ? month.net_typical : month.net_known),
      short: monthName.format(day),
      caption: partial(month) ? 'rest of' : number === 1 ? String(year) : '',
      label: `${monthLabel.format(day)}${partial(month) ? ', from tomorrow' : ''}`,
    }
  }),
)

const niceStep = raw => {
  const magnitude = 10 ** Math.floor(Math.log10(raw))
  const fraction = raw / magnitude

  return (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10) * magnitude
}

const outOf = m => m.out + (props.typical ? m.typical : 0)
const inOf = m => m.in + (props.typical ? m.typicalIn : 0)

const scale = computed(() => {
  const peak = Math.max(1, ...bars.value.flatMap(m => [inOf(m), outOf(m), Math.abs(m.net)]))
  // A quarter of the tallest bar: at a half, a peak just past a round figure got steps of
  // twice it, and a whole empty band below the last bar.
  const tick = niceStep(peak / 4)
  const high =
    Math.ceil(Math.max(...bars.value.map(m => Math.max(inOf(m), m.net)), 0) / tick) * tick
  const low =
    Math.ceil(Math.max(...bars.value.map(m => Math.max(outOf(m), -m.net)), 0) / tick) * tick

  return { tick, high: high || tick, low }
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

  return top + ((high - value) / (high + low)) * (bottom - top)
}

const step = computed(() => (width - left - right) / Math.max(bars.value.length, 1))
const band = i => left + i * step.value
const centre = i => band(i) + step.value / 2

// The cash flow chart's column: square at its base, rounded at the end of its stack.
const column = (i, from, to, direction, outer) => {
  const w = Math.min(36, step.value * 0.6)
  const x = centre(i) - w / 2
  const base = y(direction * from) - direction
  const end = y(direction * to)
  const r = outer ? Math.min(4, Math.abs(end - base)) : 0

  if (Math.abs(end - base) < 0.5) return ''

  return direction > 0
    ? `M${x},${base} V${end + r} Q${x},${end} ${x + r},${end} H${x + w - r} Q${x + w},${end} ${x + w},${end + r} V${base} Z`
    : `M${x},${base} V${end - r} Q${x},${end} ${x + r},${end} H${x + w - r} Q${x + w},${end} ${x + w},${end - r} V${base} Z`
}

const netLine = computed(() =>
  monotoneCurve(
    bars.value.map(month => month.net),
    centre(0),
    step.value,
  )
    .map(([px, value]) => `${px},${y(value)}`)
    .join(' '),
)

const compact = value =>
  new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(value)

const label = computed(() => `Known money in and out per month ahead, in ${props.ccy}`)

const tooltipRows = computed(() => {
  const month = props.months[hovered.value]

  return [
    { label: 'Known in', value: money(month.in), colour: colours.in },
    { label: 'Known out', value: money(month.out), colour: colours.out },
    ...(props.typical
      ? [
          { label: 'Typical income', value: money(month.typical_in), colour: colours.typicalIn },
          { label: 'Typical spending', value: money(month.typical), colour: colours.typical },
        ]
      : []),
    {
      label: 'Net',
      value: money(props.typical ? month.net_typical : month.net_known),
      colour: colours.net,
    },
    {
      label: 'Balance at month end',
      value: money(props.typical ? month.end_typical : month.end_known),
      colour: 'transparent',
    },
  ]
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
</script>
