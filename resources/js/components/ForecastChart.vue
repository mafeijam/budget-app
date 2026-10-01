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
      <div v-if="markers.length" class="row items-center no-wrap">
        <span class="app-forecast-dot" :style="{ background: colours.in }" /><span
          class="app-forecast-dot q-mr-xs"
          :style="{ background: colours.out }"
        />Money in / out
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

        <defs>
          <clipPath :id="clipId">
            <rect :x="0" :y="top" :width="width" :height="bottom - top" />
          </clipPath>
        </defs>

        <path :d="area.d" :fill="area.colour" fill-opacity="0.1" :clip-path="`url(#${clipId})`" />

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
          :stroke-width="typical ? 1.5 : 2"
          :stroke-opacity="typical ? 0.55 : 1"
          stroke-linejoin="round"
          :clip-path="`url(#${clipId})`"
        />

        <text
          v-if="knownOff"
          :x="width - right"
          :y="top + 12"
          text-anchor="end"
          class="cash-flow-chart__tick"
          :fill="colours.known"
        >
          Known reaches {{ compact(numbers.at(-1).known) }} ↑
        </text>

        <!-- Where the known line steps, and which way, so a jump names itself on hover. -->
        <circle
          v-for="marker in markers.filter(m => y(numbers[m.i].known) >= top)"
          :key="marker.date"
          :cx="x(marker.i)"
          :cy="y(numbers[marker.i].known)"
          :r="hovered === marker.i ? 5 : 3.5"
          :fill="marker.in ? colours.in : colours.out"
          stroke="#ffffff"
          stroke-width="1.5"
        />

        <circle
          v-if="lowIndex !== null"
          :cx="x(lowIndex)"
          :cy="y(numbers[lowIndex][typical ? 'typical' : 'known'])"
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
          <span class="money text-weight-medium">{{ shown(hovered, 'known') }}</span>
        </div>
        <div v-if="typical" class="row no-wrap items-center">
          <span class="cash-flow-chart__swatch" :style="{ background: colours.typical }" />
          <span class="q-mr-md">With typical spending</span>
          <q-space />
          <span class="money text-weight-medium">{{ shown(hovered, 'typical') }}</span>
        </div>
        <template v-if="eventsOn[points[hovered].date]">
          <q-separator class="q-my-xs" />
          <div
            v-for="(event, n) in eventsOn[points[hovered].date]"
            :key="n"
            class="row no-wrap items-center"
          >
            <span class="q-mr-md ellipsis app-forecast-tooltip__event">{{
              event.description
            }}</span>
            <q-space />
            <span
              class="money"
              :class="String(event.base).startsWith('-') ? 'text-negative' : 'text-positive'"
            >
              {{ money(event.base) }}
            </span>
          </div>
        </template>
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
  // Every known movement ahead, for the markers and the tooltip.
  events: { type: Array, default: () => [] },
  // The page's what-if: typical spending scaled, every income taken away, and the irregular
  // spending left out.
  whatIf: { type: Object, default: () => ({ factor: 1, noIncome: false, irregular: true }) },
})

const money = useMoney()

// The known balance in the primary, the estimate in amber as the cash flow chart's net,
// and the lowest point in the negative, since it is the day the page is warning about.
const colours = {
  known: '#2563eb',
  typical: '#f59e0b',
  low: '#dc2626',
  in: '#059669',
  out: '#e11d48',
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

// Per chart, so a second one on the page does not clip by the first's box.
const clipId = `forecast-${useId()}`

const adjusted = computed(
  () => props.whatIf.factor !== 1 || props.whatIf.noIncome || props.whatIf.irregular === false,
)

// Numbers for geometry, and for the what-if, which has no server figure to show: the
// known line less any recurring income taken away, the typical one less the scaled
// allowance and irregular spending and plus typical income unless that is taken away too. Unadjusted,
// every figure shown is the server's string.
const numbers = computed(() =>
  props.points.map(point => {
    const known = Number(point.known) - (props.whatIf.noIncome ? Number(point.recurring_in) : 0)

    const earned = props.whatIf.noIncome ? 0 : Number(point.earned)
    const spent =
      Number(point.allowance) +
      (props.whatIf.irregular === false ? 0 : Number(point.irregular ?? 0))

    return { known, typical: known - spent * props.whatIf.factor + earned }
  }),
)

// An estimate of an estimate, so whole units and marked as one, not a figure to the cent.
const shown = (i, key) =>
  adjusted.value
    ? `≈ ${money(String(Math.round(numbers.value[i][key])))}`
    : money(props.points[i][key])

const eventsOn = computed(() => {
  const byDate = {}

  for (const event of props.events) (byDate[event.date] ??= []).push(event)

  return byDate
})

const markers = computed(() =>
  props.points
    .map((point, i) => ({ i, date: point.date, events: eventsOn.value[point.date] }))
    .filter(point => point.events)
    .map(point => ({
      ...point,
      in: point.events.reduce((sum, event) => sum + Number(event.base), 0) >= 0,
    })),
)

const niceStep = raw => {
  const magnitude = 10 ** Math.floor(Math.log10(raw))
  const fraction = raw / magnitude

  return (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10) * magnitude
}

const scale = computed(() => {
  const values = numbers.value.flatMap(n => (props.typical ? [n.known, n.typical] : [n.known]))
  const min = Math.min(...values)
  let max = Math.max(...values)

  // With typical spending on, the top is the typical line's with some headroom, not the
  // known line's. Known counts salary in and almost nothing out once the known statements
  // run out, so over a year it climbs off on its own and squashed the realistic line into
  // the bottom of the chart; past the headroom it leaves through the top, and says where
  // it ends.
  if (props.typical) {
    const typical = numbers.value.map(n => n.typical)
    const top = Math.max(...typical)

    max = Math.min(max, top + Math.max((top - Math.min(...typical)) * 0.3, Math.abs(top) * 0.05))
  }

  // Fitted to the figures rather than from zero, so a balance moving within a narrow band
  // fills the chart instead of a flat line over a block of colour. Zero joins the range only
  // when a balance crosses it, and a flat line gets a band of its own size to sit in. A
  // small pad and a fine tick, because the axis rounds out to whole ticks: with a coarse
  // one the lines sat in half the chart and every step of the known line looked like none.
  const pad = (max - min || Math.abs(max) || 1) * 0.05
  const floor = min >= 0 ? Math.max(0, min - pad) : min - pad
  const tick = niceStep(Math.max(1, max + pad - floor) / 6)

  return {
    tick,
    high: Math.ceil((max + pad) / tick) * tick,
    low: Math.floor(floor / tick) * tick,
  }
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

const curves = computed(() => ({
  known: monotoneCurve(
    numbers.value.map(n => n.known),
    x(0),
    step.value,
  ),
  typical: monotoneCurve(
    numbers.value.map(n => n.typical),
    x(0),
    step.value,
  ),
}))

const line = key => curves.value[key].map(([px, value]) => `${px},${y(value)}`).join(' ')

// Down to zero when the axis reaches it, so a stretch below zero fills below it.
const area = computed(() => {
  if (!numbers.value.length) return { d: '', colour: colours.known }

  // To zero when it is on the chart, otherwise to the axis's own floor. Under the typical
  // line when it is on: under the known one, which the scale lets leave through the top,
  // the shading filled the chart to its edge from that day on and read as a fault.
  const base = y(Math.max(scale.value.low, 0))
  const under = props.typical ? 'typical' : 'known'
  const colour = props.typical ? colours.typical : colours.known

  return {
    colour,
    d: `M${x(0)},${base} L${line(under).replaceAll(' ', ' L')} L${x(numbers.value.length - 1)},${base} Z`,
  }
})

// The lowest day ahead on the line on show, which the what-if can move.
// The known line's end is above the chart's top, so it is named rather than drawn.
const knownOff = computed(() => numbers.value.length && y(numbers.value.at(-1).known) < top)

const lowIndex = computed(() => {
  const key = props.typical ? 'typical' : 'known'

  if (!adjusted.value) {
    const i = props.points.findIndex(point => point.date === props.lowest?.[key]?.date)

    return i === -1 ? null : i
  }

  let low = null

  numbers.value.forEach((n, i) => {
    if (i > 0 && (low === null || n[key] < numbers.value[low][key])) low = i
  })

  return low
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
