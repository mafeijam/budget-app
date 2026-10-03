<template>
  <div class="cash-flow-chart">
    <div class="row items-center q-gutter-md text-caption text-grey-8 q-mb-sm">
      <div v-for="part in parts" :key="part.key" class="row items-center no-wrap">
        <span class="cash-flow-chart__swatch" :style="{ background: part.colour }" />{{
          part.label
        }}
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

        <defs>
          <clipPath :id="`${uid}-above`">
            <rect :x="0" :y="0" :width="width" :height="y(0)" />
          </clipPath>
          <clipPath :id="`${uid}-below`">
            <rect :x="0" :y="y(0)" :width="width" :height="height - y(0)" />
          </clipPath>
        </defs>

        <g v-for="(month, i) in points" :key="month.month">
          <path
            v-for="segment in month.segments"
            :key="segment.key"
            :d="column(barX(i, segment.side), segment.from, segment.to, segment.outer)"
            :fill="segment.colour"
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
        <path
          :d="netArea"
          :fill="colours.net"
          fill-opacity="0.15"
          :clip-path="`url(#${uid}-above)`"
        />
        <!-- Below zero the month lost money, and red says so before any figure is read. -->
        <path
          :d="netArea"
          :fill="colours.deficit"
          fill-opacity="0.2"
          :clip-path="`url(#${uid}-below)`"
        />

        <polyline
          :points="netLine"
          fill="none"
          :stroke="colours.net"
          stroke-width="1.5"
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
// Each kind's lighter shade is its broken-out share, stacked outermost.
const colours = {
  income: '#059669',
  dividend: '#34d399',
  spending: '#dc2626',
  card: '#f87171',
  net: '#f59e0b',
  deficit: '#dc2626',
  grid: '#f8e6b6',
  baseline: '#c9a96a',
  hover: '#fcf5e2',
}

const width = 960
const height = 280
const left = 64
const right = 12
const top = 12
const bottom = height - 40

const hovered = ref(null)

// Two charts on one page must not share clip path ids, or one clips by the other's zero line.
const uid = `cash-flow-${useId()}`

// Nearest the zero line first: the part a stack starts from. The two stacks stand side by
// side rather than one above the other, so a month reads as its income against its
// spending and the net line down the gap between them is what says which won.
const parts = [
  { key: 'dividend', label: 'Dividend', colour: colours.dividend, side: 'in' },
  { key: 'other_income', label: 'Income', colour: colours.income, side: 'in' },
  { key: 'cash_spending', label: 'Cash spending', colour: colours.spending, side: 'out' },
  { key: 'card_spending', label: 'Card spending', colour: colours.card, side: 'out' },
]

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

    const reached = { in: 0, out: 0 }
    const segments = parts
      .map(part => {
        const from = reached[part.side]
        const to = from + Math.max(0, Number(month[part.key]))
        reached[part.side] = to

        return { key: part.key, colour: part.colour, side: part.side, from, to }
      })
      .filter(segment => segment.to > segment.from)

    // Only the end of a stack is rounded; a part under another meets it square.
    for (const side of ['in', 'out']) {
      const outer = segments.filter(segment => segment.side === side).at(-1)
      if (outer) outer.outer = true
    }

    return {
      month: month.month,
      income: Number(month.income),
      spending: Number(month.spending),
      net: Number(month.net),
      segments,
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
  // A quarter of the tallest bar: at a half, a peak just past a round figure got steps of
  // twice it, and a whole empty band below the last bar.
  const tick = niceStep(peak / 4)
  // Spending is a bar like any other now and stands up from the zero line, so the top of
  // the scale has to clear it. It did not used to: it hung off the bottom, where `low`
  // found it by the accident of being negative, and leaving it out runs the tallest
  // spending column off the top of the chart with nothing to fail. Below zero there is
  // only the net line, so that side is sized by how far short a month went.
  const high =
    Math.ceil(Math.max(...points.value.map(m => Math.max(m.income, m.spending, m.net)), 0) / tick) *
    tick
  const low = Math.ceil(Math.max(...points.value.map(m => -m.net), 0) / tick) * tick

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

const step = computed(() => (width - left - right) / Math.max(points.value.length, 1))
const band = i => left + i * step.value
const centre = i => band(i) + step.value / 2

// Two bars to a month with a gap between them, the pair centred on the band, so the net
// line threading that gap is over the difference rather than over one of the two.
const gutter = 3
const barWidth = computed(() => Math.min(24, step.value * 0.3))
const barX = (i, side) =>
  centre(i) - barWidth.value - gutter / 2 + (side === 'in' ? 0 : barWidth.value + gutter)

// Capped at 24px, square at the baseline and rounded 4px at the end of the stack, with a
// half-unit seam of surface between stacked parts.
const seam = 0.5
const column = (x, from, to, outer) => {
  const w = barWidth.value
  const base = y(from) - seam
  const end = y(to)
  const r = outer ? Math.min(4, Math.abs(end - base)) : 0

  if (Math.abs(end - base) < 0.5) return ''

  return `M${x},${base} V${end + r} Q${x},${end} ${x + r},${end} H${x + w - r} Q${x + w},${end} ${x + w},${end + r} V${base} Z`
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
    ...parts.map(part => ({
      label: part.label,
      value: money(month[part.key]),
      colour: part.colour,
    })),
    {
      label: 'Net',
      value: money(month.net),
      colour: String(month.net).startsWith('-') ? colours.deficit : colours.net,
    },
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
