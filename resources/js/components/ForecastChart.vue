<template>
  <div>
    <div class="row items-center q-gutter-md text-caption text-grey-8 q-mb-sm">
      <div class="row items-center no-wrap">
        <span class="cash-flow-chart__line" :style="{ background: colours.known }" />Known
      </div>
      <div v-if="typical" class="row items-center no-wrap">
        <span class="cash-flow-chart__line" :style="{ background: colours.typical }" />With typical
        spending
      </div>
      <div class="row items-center no-wrap">
        <span class="cash-flow-chart__swatch" :style="{ background: colours.low }" />Lowest
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
            :stroke="tick === 0 ? colours.zero : colours.grid"
            :stroke-width="tick === 0 ? 1.5 : 1"
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

        <text
          v-for="month in monthTicks"
          :key="month.i"
          :x="x(month.i)"
          :y="height - 8"
          text-anchor="middle"
          class="cash-flow-chart__tick"
        >
          {{ month.label }}
        </text>

        <line
          v-if="hovered !== null"
          :x1="x(hovered)"
          :x2="x(hovered)"
          :y1="top"
          :y2="bottom"
          :stroke="colours.grid"
          stroke-width="1"
        />

        <path :d="area" :fill="colours.known" fill-opacity="0.1" />

        <polyline
          v-if="typical"
          :points="line('typical')"
          fill="none"
          :stroke="colours.typical"
          stroke-width="2"
          stroke-dasharray="5 4"
          stroke-linejoin="round"
        />
        <polyline
          :points="line('known')"
          fill="none"
          :stroke="colours.known"
          stroke-width="2"
          stroke-linejoin="round"
        />

        <circle
          v-if="lowIndex !== null"
          :cx="x(lowIndex)"
          :cy="y(numbers[lowIndex].known)"
          r="5"
          :fill="colours.low"
          stroke="#ffffff"
          stroke-width="2"
        />

        <rect
          :x="left"
          :y="top"
          :width="width - left - right"
          :height="bottom - top"
          fill="transparent"
          @mousemove="hover"
          @mouseleave="hovered = null"
        />
      </svg>

      <div v-if="hovered !== null" class="cash-flow-chart__tooltip" :style="tooltipStyle">
        <div class="text-weight-bold q-mb-xs">{{ longDate(points[hovered].date) }}</div>
        <div class="row no-wrap items-center">
          <span class="cash-flow-chart__swatch" :style="{ background: colours.known }" />
          <span class="q-mr-md">Known</span>
          <q-space />
          <span class="money text-weight-medium">{{ money(points[hovered].known) }}</span>
        </div>
        <div v-if="typical" class="row no-wrap items-center">
          <span class="cash-flow-chart__swatch" :style="{ background: colours.typical }" />
          <span class="q-mr-md">With typical spending</span>
          <q-space />
          <span class="money text-weight-medium">{{ money(points[hovered].typical) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  points: { type: Array, default: () => [] },
  ccy: { type: String, default: '' },
  lowest: { type: Object, default: null },
  typical: { type: Boolean, default: true },
})

const money = useMoney()

// The known balance in the primary, the estimate in amber as the cash flow chart's net,
// and the lowest point in the negative, since it is the day the page is warning about.
const colours = {
  known: '#2563eb',
  typical: '#f59e0b',
  low: '#dc2626',
  grid: '#e2e8f0',
  zero: '#94a3b8',
}

const width = 960
const height = 260
const left = 64
const right = 16
const top = 12
const bottom = height - 28

const hovered = ref(null)

// Numbers only for geometry: every figure shown is formatted from the server's string.
const numbers = computed(() =>
  props.points.map(point => ({ known: Number(point.known), typical: Number(point.typical) })),
)

const niceStep = raw => {
  const magnitude = 10 ** Math.floor(Math.log10(raw))
  const fraction = raw / magnitude

  return (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10) * magnitude
}

const scale = computed(() => {
  const values = numbers.value.flatMap(n => (props.typical ? [n.known, n.typical] : [n.known]))
  const high = Math.max(0, ...values)
  const low = Math.min(0, ...values)
  const tick = niceStep(Math.max(1, high - low) / 4)

  return { tick, high: Math.ceil(high / tick) * tick || tick, low: Math.floor(low / tick) * tick }
})

const ticks = computed(() => {
  const { tick, high, low } = scale.value
  const list = []

  // `|| 0`, or a scale with nothing below zero starts at -0 and labels it so.
  for (let value = low || 0; value <= high; value += tick) list.push(value)

  return list
})

const y = value => {
  const { high, low } = scale.value

  return top + ((high - value) / (high - low || 1)) * (bottom - top)
}

const step = computed(() => (width - left - right) / Math.max(props.points.length - 1, 1))

const x = i => left + i * step.value

const line = key => numbers.value.map((n, i) => `${x(i)},${y(n[key])}`).join(' ')

// Down to the zero line, so a stretch below it fills below zero.
const area = computed(() => {
  if (!numbers.value.length) return ''

  return `M${x(0)},${y(0)} L${line('known').replaceAll(' ', ' L')} L${x(numbers.value.length - 1)},${y(0)} Z`
})

const lowIndex = computed(() => {
  const i = props.points.findIndex(point => point.date === props.lowest?.date)

  return i === -1 ? null : i
})

const asDate = day => {
  const [year, month, date] = day.split('-').map(Number)

  return new Date(Date.UTC(year, month - 1, date))
}

const monthName = new Intl.DateTimeFormat('en', { month: 'short', timeZone: 'UTC' })
const longFormat = new Intl.DateTimeFormat('en', {
  weekday: 'short',
  day: 'numeric',
  month: 'short',
  year: 'numeric',
  timeZone: 'UTC',
})

const longDate = day => longFormat.format(asDate(day))

// A label on the first of each month.
const monthTicks = computed(() =>
  props.points
    .map((point, i) => ({ i, day: point.date }))
    .filter(({ day }) => day.endsWith('-01'))
    .map(({ i, day }) => ({ i, label: monthName.format(asDate(day)) })),
)

const hover = event => {
  const box = event.currentTarget.ownerSVGElement.getBoundingClientRect()
  const at = ((event.clientX - box.left) / box.width) * width
  const i = Math.round((at - left) / step.value)

  hovered.value = Math.min(Math.max(i, 0), props.points.length - 1)
}

const compact = value =>
  new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(value)

const label = computed(() => `Projected cash balance, in ${props.ccy}`)

// Flipped to the left of the day past the middle, so it never runs off the card.
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
