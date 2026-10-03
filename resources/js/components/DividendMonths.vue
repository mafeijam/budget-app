<template>
  <div class="cash-flow-chart">
    <div class="row items-center q-gutter-md text-caption text-grey-8 q-mb-sm">
      <div v-for="key in legend" :key="key.label" class="row items-center no-wrap">
        <span class="cash-flow-chart__swatch" :style="{ background: key.colour }" />{{ key.label }}
      </div>
      <div v-if="hasPending" class="row items-center no-wrap">
        <span class="cash-flow-chart__swatch app-dividend-pending-swatch" />Pending
      </div>
      <div v-if="hasExpected" class="row items-center no-wrap">
        <span class="cash-flow-chart__swatch app-dividend-expected-swatch" />Expected
      </div>
      <div class="row items-center no-wrap">
        <span
          class="cash-flow-chart__swatch"
          :style="{ background: colours.previousFill, outline: `1px solid ${colours.previous}` }"
        />Previous
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

        <!-- Last year's same month first, behind: an outline a little wider than the bar,
             so the two years read side by side without a line nobody could place. -->
        <rect
          v-for="(month, i) in stacks"
          v-show="month.previous > 0"
          :key="`previous-${i}`"
          :x="centre(i) - barWidth / 2 - 5"
          :y="y(month.previous)"
          :width="barWidth + 10"
          :height="Math.max(y(0) - y(month.previous), 0)"
          :fill="colours.previousFill"
          :stroke="colours.previous"
          stroke-width="1"
          rx="3"
        />

        <g v-for="(month, i) in stacks" :key="i">
          <rect
            v-for="segment in month.segments"
            :key="segment.key"
            :x="centre(i) - barWidth / 2"
            :y="y(segment.to)"
            :width="barWidth"
            :height="Math.max(y(segment.from) - y(segment.to) - 1, 0)"
            :fill="segment.colour"
          />
          <!-- Entered and not yet paid: amber and dashed, on top of what has been. Known, so
               not drawn as the estimate beyond it is. -->
          <rect
            v-if="month.pending > 0"
            :x="centre(i) - barWidth / 2"
            :y="y(month.paid + month.pending)"
            :width="barWidth"
            :height="Math.max(y(month.paid) - y(month.paid + month.pending) - 1, 0)"
            :fill="colours.pending"
            :stroke="colours.pendingEdge"
            stroke-dasharray="3 2"
          />
          <!-- Paler and outlined: an estimate, and drawn to look like one. -->
          <rect
            v-if="month.expected > 0"
            :x="centre(i) - barWidth / 2"
            :y="y(month.paid + month.pending + month.expected)"
            :width="barWidth"
            :height="
              Math.max(
                y(month.paid + month.pending) - y(month.paid + month.pending + month.expected) - 1,
                0,
              )
            "
            :fill="colours.expected"
            :stroke="colours.expectedEdge"
            stroke-dasharray="3 2"
          />
          <text :x="centre(i)" :y="height - 8" text-anchor="middle" class="cash-flow-chart__tick">
            {{ month.short }}
          </text>
        </g>

        <rect
          v-for="(month, i) in stacks"
          :key="`hit-${i}`"
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
        <div class="text-weight-bold q-mb-xs">{{ stacks[hovered].long }} {{ year }}</div>
        <div v-for="row in tooltipRows" :key="row.label" class="row no-wrap items-center">
          <span class="cash-flow-chart__swatch" :style="{ background: row.colour }" />
          <span class="q-mr-md">{{ row.label }}</span>
          <q-space />
          <span class="money text-weight-medium">{{ row.value }}</span>
        </div>
        <div v-if="!tooltipRows.length" class="text-grey-6">Nothing paid</div>
        <div class="row no-wrap items-center q-mt-xs text-grey-7">
          <span class="q-mr-md">Total</span>
          <q-space />
          <span class="money">{{ monthTotal.expected > 0 ? '~' : '' }}{{ monthTotal.value }}</span>
        </div>
        <div class="row no-wrap items-center text-grey-7">
          <span class="q-mr-md">Previous</span>
          <q-space />
          <span class="money">{{ money(previousMonths[hovered]) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  year: { type: Number, required: true },
  // Twelve decimal strings each, January first.
  expectedMonths: { type: Array, default: () => [] },
  previousMonths: { type: Array, default: () => [] },
  // The page's symbols, ranked, each with its months and the colour the page gave it.
  symbols: { type: Array, default: () => [] },
  base: { type: String, default: 'HKD' },
})

const money = useMoney()

const colours = {
  pending: '#fef3c7',
  pendingEdge: '#f59e0b',
  expected: '#e0f2fe',
  expectedEdge: '#7dd3fc',
  previous: '#cbd5e1',
  previousFill: '#f8fafc',
  grid: '#f8e6b6',
  baseline: '#c9a96a',
  hover: '#fcf5e2',
}

const width = 960
const height = 220
const left = 56
const right = 12
const top = 12
const bottom = height - 28

const hovered = ref(null)

const short = new Intl.DateTimeFormat('en', { month: 'short', timeZone: 'UTC' })
const long = new Intl.DateTimeFormat('en', { month: 'long', timeZone: 'UTC' })

// The key names only the coloured symbols; the rest share the neutral, as in the table.
const othersColour = computed(() => props.symbols.find(s => s.other)?.colour)
const othersCount = computed(() => props.symbols.filter(s => s.other).length)

const legend = computed(() => [
  ...props.symbols.filter(s => !s.other).map(s => ({ label: s.symbol, colour: s.colour })),
  ...(othersCount.value
    ? [{ label: `Others (${othersCount.value})`, colour: othersColour.value }]
    : []),
])

const hasPending = computed(() =>
  props.symbols.some(s => (s.pending_months ?? []).some(v => Number(v) > 0)),
)

const hasExpected = computed(() => props.expectedMonths.some(v => Number(v) > 0))

// Numbers only for geometry; every figure shown is formatted from the server's string.
const stacks = computed(() =>
  Array.from({ length: 12 }, (_, i) => {
    const day = new Date(Date.UTC(props.year, i, 1))
    let running = 0
    let rest = 0
    const segments = []

    for (const symbol of props.symbols) {
      const amount = Number(symbol.months[i])

      if (amount <= 0) continue

      // The tail is one block, not one block per symbol: they share the neutral already,
      // and each as its own segment put a 1px gap between them that read as a border, so
      // eleven of them looked like a stack of separate things rather than a single share
      // of the month's money. Added together, and drawn once, on top of the named.
      if (symbol.other) {
        rest += amount

        continue
      }

      segments.push({
        key: symbol.symbol,
        from: running,
        to: running + amount,
        colour: symbol.colour,
      })
      running += amount
    }

    if (rest > 0) {
      segments.push({
        key: 'others',
        from: running,
        to: running + rest,
        colour: othersColour.value,
      })
      running += rest
    }

    return {
      short: short.format(day),
      long: long.format(day),
      segments,
      paid: running,
      pending: props.symbols.reduce((total, s) => total + Number(s.pending_months?.[i] ?? 0), 0),
      expected: Number(props.expectedMonths[i] ?? 0),
      previous: Number(props.previousMonths[i] ?? 0),
    }
  }),
)

const niceStep = raw => {
  const magnitude = 10 ** Math.floor(Math.log10(raw))
  const fraction = raw / magnitude

  return (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10) * magnitude
}

const scale = computed(() => {
  const peak = Math.max(
    1,
    ...stacks.value.flatMap(m => [m.paid + m.pending + m.expected, m.previous]),
  )
  const tick = niceStep(peak / 4)

  return { tick, high: Math.ceil(peak / tick) * tick }
})

const ticks = computed(() => {
  const list = []

  for (let value = 0; value <= scale.value.high; value += scale.value.tick) list.push(value)

  return list
})

const y = value => top + ((scale.value.high - value) / scale.value.high) * (bottom - top)

const step = (width - left - right) / 12
const band = i => left + i * step
const centre = i => band(i) + step / 2
const barWidth = Math.min(40, step * 0.55)

const compact = value =>
  new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(value)

const label = computed(() => `Dividends paid each month of ${props.year}, in ${props.base}`)

const tooltipRows = computed(() => {
  const i = hovered.value
  const named = []
  const rest = []

  for (const symbol of props.symbols) {
    if (Number(symbol.months[i]) <= 0) continue

    // The tail as the one row the bar draws, with the count beside it, so the tooltip and
    // the stack are saying the same thing. Still one figure: a month can carry a dozen of
    // these and the list is not the point of the hover.
    if (symbol.other) {
      rest.push(symbol)

      continue
    }

    named.push({ label: symbol.symbol, value: money(symbol.months[i]), colour: symbol.colour })
  }

  if (rest.length) {
    named.push({
      label: `Others (${rest.length})`,
      value: money(rest.reduce((total, s) => total + Number(s.months[i]), 0).toFixed(2)),
      colour: othersColour.value,
    })
  }

  return [
    ...named,
    ...props.symbols
      .filter(s => Number(s.pending_months?.[i]) > 0)
      .map(s => ({
        label: `${s.symbol}, pending`,
        value: money(s.pending_months[i]),
        colour: colours.pendingEdge,
      })),
    ...props.symbols
      .filter(s => Number(s.expected_months[i]) > 0)
      .map(s => ({
        label: `${s.symbol}, expected`,
        value: `~${money(s.expected_months[i])}`,
        colour: colours.expectedEdge,
      })),
  ]
})

// The hovered month's own total, so it sits above the same month a year earlier and the
// two read against each other. It is the rows above added up, expected among them; a
// paid-only total would read short of the figures directly above it.
const monthTotal = computed(() => {
  const month = stacks.value[hovered.value]

  return {
    expected: month.expected,
    value: money((month.paid + month.pending + month.expected).toFixed(2)),
  }
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
