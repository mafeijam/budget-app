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

    <!-- A chart too short for the width shares it with the months as figures, rather than
         leaving the rest of the card empty; a hover on either marks the month in both. -->
    <div class="app-months">
      <div
        class="relative-position app-months__chart"
        :style="{ flexBasis: `${(width / widest) * 100}%` }"
      >
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
              :d="column(barX(i, 'in'), 0, month.in, !typical || month.typicalIn <= 0)"
              :fill="colours.in"
            />
            <path
              v-if="typical && month.typicalIn > 0"
              :d="column(barX(i, 'in'), month.in, month.in + month.typicalIn, true)"
              :fill="colours.typicalIn"
            />
            <path
              v-if="month.out > 0"
              :d="column(barX(i, 'out'), 0, month.out, !typical || month.typical <= 0)"
              :fill="colours.out"
            />
            <!-- Lighter, beyond the known figure: an estimate, and drawn to look like one. -->
            <path
              v-if="typical && month.typical > 0"
              :d="column(barX(i, 'out'), month.out, month.out + month.typical, true)"
              :fill="colours.typical"
            />
            <text
              :x="centre(i)"
              :y="height - 18"
              text-anchor="middle"
              class="cash-flow-chart__tick"
            >
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

      <div v-if="width < widest" class="app-months__list">
        <div
          v-for="(month, i) in bars"
          :key="month.month"
          class="app-months__row"
          :class="{ 'app-months__row--on': hovered === i }"
          @mouseenter="hovered = i"
          @mouseleave="hovered = null"
        >
          <div class="row items-baseline no-wrap">
            <div class="text-weight-medium text-grey-9">{{ month.label }}</div>
            <q-space />
            <div class="money text-weight-bold" :class="signClass(netOf(months[i]))">
              {{ signed(netOf(months[i])) }}
            </div>
          </div>
          <div class="row items-baseline no-wrap text-caption text-grey-6 money">
            <span class="text-positive">+{{ money(months[i].in) }}</span>
            <span class="q-mx-xs">·</span>
            <span class="text-negative">−{{ money(months[i].out) }}</span>
            <span class="q-ml-xs">known</span>
            <q-space />
            <span>ends at {{ money(typical ? months[i].end_typical : months[i].end_known) }}</span>
          </div>
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
  typical: '#f87171',
  typicalIn: '#34d399',
  net: '#f59e0b',
  grid: '#e2e8f0',
  baseline: '#94a3b8',
  hover: '#f1f5f9',
}

const widest = 960

// A month's band at most this wide, and the chart only as wide as its months need: three
// months across the full width left each bar alone in a third of the card. Narrower rather
// than rescaled, so a bar and its labels are the same size at every horizon.
const widestStep = 160
const width = computed(() =>
  Math.min(widest, left + right + Math.max(bars.value.length, 1) * widestStep),
)
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
  // Spending is a bar like any other now and stands up from the zero line, so the top of
  // the scale has to clear it. It did not used to: it hung off the bottom, where `low`
  // found it by the accident of being negative, and leaving it out runs the tallest
  // spending column off the top of the chart with nothing to fail. Below zero there is
  // only the net line, so that side is sized by how far short a month went -- which for a
  // forecast is usually nothing, and then the bars get the whole height.
  const high =
    Math.ceil(Math.max(...bars.value.map(m => Math.max(inOf(m), outOf(m), m.net)), 0) / tick) * tick
  const low = Math.ceil(Math.max(...bars.value.map(m => -m.net), 0) / tick) * tick

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

const step = computed(() => (width.value - left - right) / Math.max(bars.value.length, 1))
const band = i => left + i * step.value
const centre = i => band(i) + step.value / 2

// Two bars to a month with a gap between them, the pair centred on the band, so the net
// line threading that gap is over the difference rather than over one of the two.
const gutter = 3
const barWidth = computed(() => Math.min(24, step.value * 0.3))
const barX = (i, side) =>
  centre(i) - barWidth.value - gutter / 2 + (side === 'in' ? 0 : barWidth.value + gutter)

// The cash flow chart's column: square at its base, rounded at the end of its stack.
const column = (x, from, to, outer) => {
  const w = barWidth.value
  const base = y(from) - 1
  const end = y(to)
  const r = outer ? Math.min(4, Math.abs(end - base)) : 0

  if (Math.abs(end - base) < 0.5) return ''

  return `M${x},${base} V${end + r} Q${x},${end} ${x + r},${end} H${x + w - r} Q${x + w},${end} ${x + w},${end + r} V${base} Z`
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

const netOf = month => (props.typical ? month.net_typical : month.net_known)

// On the decimal string, not a float.
const isNegative = value => String(value).startsWith('-') && !/^-0*(\.0*)?$/.test(String(value))
const signClass = value => (isNegative(value) ? 'text-negative' : 'text-positive')
const signed = value =>
  isNegative(value) ? `−${money(String(value).slice(1))}` : `+${money(value)}`

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
          ...(Number(month.double_pay) > 0
            ? [
                {
                  label: 'of it, the double pay',
                  value: money(month.double_pay),
                  colour: 'transparent',
                },
              ]
            : []),
          ...(Number(month.bonuses) > 0
            ? [{ label: 'of it, the bonus', value: money(month.bonuses), colour: 'transparent' }]
            : []),
          ...(Number(month.dividends) > 0
            ? [{ label: 'of it, dividends', value: money(month.dividends), colour: 'transparent' }]
            : []),
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
  const x = (centre(hovered.value) / width.value) * 100
  const flip = x > 60

  return {
    left: flip ? 'auto' : `calc(${x}% + 16px)`,
    right: flip ? `calc(${100 - x}% + 16px)` : 'auto',
    top: '8px',
  }
})
</script>
