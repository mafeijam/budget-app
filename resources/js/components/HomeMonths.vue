<template>
  <!-- The phone's: each month its likely net and what goes in and out, side by side, from the
       same figures as the full card below so the two cannot disagree. -->
  <q-card v-if="compact && monthCols.length" flat bordered>
    <q-card-section class="app-card-head"> This month and next </q-card-section>
    <div class="app-month-tiles">
      <div v-for="col in monthCols" :key="col.key" class="app-month-tiles__tile">
        <div class="row items-center no-wrap">
          <span class="text-weight-medium text-grey-9">{{ col.name }}</span>
          <q-badge v-if="col.forecast" class="app-tint app-tint--info q-ml-xs" label="forecast" />
        </div>
        <div
          class="text-h5 text-weight-bold money text-no-wrap q-py-sm"
          :class="signClass(col.net)"
        >
          {{ col.forecast ? '≈ ' : '' }}{{ signed(col.net) }}
        </div>
        <div
          v-for="bar in col.bars"
          :key="bar.label"
          class="row items-baseline justify-between text-caption money"
        >
          <span
            class="text-weight-medium"
            :class="bar.label === 'In' ? 'text-positive' : 'text-negative'"
          >
            {{ bar.label }}
          </span>
          <span class="text-grey-9 text-weight-medium text-no-wrap">{{ money(bar.total) }}</span>
        </div>
      </div>
    </div>
  </q-card>

  <!-- This month and the next as one card, each answering the same question -- where the
       month is likely to end -- with its in and out drawn on one scale for both. -->
  <q-card v-else-if="monthCols.length" flat bordered>
    <q-card-section class="row items-center no-wrap q-pb-none">
      <q-icon name="insights" size="sm" color="grey-6" class="q-mr-sm" />
      <div>
        <div class="text-subtitle1 text-weight-medium">This month and next</div>
        <div class="text-caption text-grey-7">In {{ base }} at today's rate</div>
      </div>
      <q-space />
      <div v-if="month" class="text-right">
        <div class="text-caption text-grey-7">12-month average</div>
        <div class="text-body2 text-weight-medium money" :class="signClass(month.average_net)">
          {{ signed(month.average_net) }}
        </div>
      </div>
    </q-card-section>

    <div class="app-months">
      <div
        v-for="col in monthCols"
        :key="col.key"
        class="app-months__col"
        :class="{ 'app-months__col--forecast': col.forecast }"
        @click="go(col.path)"
      >
        <div class="row items-center no-wrap app-months__head">
          <div class="text-subtitle2 text-weight-bold text-grey-9">{{ col.name }}</div>
          <q-badge v-if="col.forecast" class="app-tint app-tint--info q-ml-sm" label="forecast" />
          <q-space />
          <!-- The day over its bar, both right-aligned to the column's edge. -->
          <div v-if="col.days" class="column items-end">
            <div class="text-caption text-grey-7">
              Day {{ col.days.done }} of {{ col.days.total }}
            </div>
            <div class="app-home-month-days__track app-months__days">
              <div :style="{ width: `${(col.days.done / col.days.total) * 100}%` }" />
            </div>
          </div>
        </div>

        <div class="text-caption text-grey-7 q-mt-sm">Likely net</div>
        <!-- Wrapping, so on a phone the comparison drops under the figure rather than the
             figure breaking after its ≈. -->
        <div class="row items-baseline">
          <div
            class="app-home-figure text-weight-bold money text-no-wrap q-mr-md"
            :class="signClass(col.net)"
          >
            {{ col.forecast ? '≈ ' : '' }}{{ signed(col.net) }}
          </div>
          <div
            v-if="col.vsAverage"
            class="text-caption text-weight-medium money"
            :class="col.vsAverage.up ? 'text-positive' : 'text-negative'"
          >
            {{ col.vsAverage.up ? '▲' : '▼' }} {{ money(col.vsAverage.amount) }}
            {{ col.vsAverage.up ? 'above' : 'below' }} average
          </div>
        </div>

        <!-- One scale across both months, so the longer bar is the larger month. -->
        <div class="q-mt-md">
          <div v-for="bar in col.bars" :key="bar.label" class="app-months__bar">
            <div class="text-caption text-grey-7">{{ bar.label }}</div>
            <div class="app-outlook__track">
              <div
                v-for="part in bar.parts"
                :key="part.kind"
                :class="{ 'app-estimate': part.estimate }"
                :style="paint(part.colour, part.estimate, { width: `${part.width}%` })"
              >
                <q-tooltip :delay="200" :offset="[0, 6]">
                  {{ part.kind }}: {{ money(part.amount) }}
                </q-tooltip>
              </div>
            </div>
            <div class="text-caption text-weight-medium money text-right text-grey-9">
              {{ money(bar.total) }}
            </div>
          </div>
        </div>

        <div v-if="col.note" class="text-caption text-grey-6 q-mt-xs">{{ col.note }}</div>
      </div>
    </div>

    <q-card-section class="row items-center q-gutter-x-md text-caption text-grey-7 q-pt-sm">
      <div v-for="key in legend" :key="key.label" class="row items-center no-wrap">
        <span
          class="app-months__swatch"
          :class="{ 'app-estimate': key.kind === 'typical' }"
          :style="paint(key.colour, key.kind === 'typical')"
        />{{ key.label }}
      </div>
    </q-card-section>
  </q-card>
</template>

<script setup>
const props = defineProps({
  // The forecast's outlook for the month still running.
  month: { type: Object, default: null },
  // The forecast's month after this one: known and typical figures, decimal strings.
  nextMonth: { type: Object, default: null },
  base: { type: String, default: 'HKD' },
  // Two tiles for a phone instead of the bars, legend and comparisons.
  compact: { type: Boolean, default: false },
})

const money = useMoney()

const negative = value => String(value).startsWith('-')
const isZero = value => !/[1-9]/.test(String(value))

const signed = value => (negative(value) ? money(value) : `+${money(value)}`)

const signClass = value =>
  negative(value) ? 'text-negative' : isZero(value) ? '' : 'text-positive'

const monthFormat = new Intl.DateTimeFormat('en', { month: 'long', timeZone: 'UTC' })

const monthName = month => {
  const [year, number] = month.split('-').map(Number)

  return monthFormat.format(new Date(Date.UTC(year, number - 1, 1)))
}

// How far through its month the page is, for the header's bar.
const monthDays = computed(() => {
  const [year, number] = (props.month?.month ?? '2000-01').split('-').map(Number)
  const total = new Date(Date.UTC(year, number, 0)).getUTCDate()

  return { total, done: total - (props.month?.days_left ?? 0) }
})

// Solid for what is done, paler for what is known still to come, palest for the typical
// estimate: the same three shades in both months, so November's known reads as October's.
const shades = {
  in: { done: '#059669', known: '#6ee7b7', typical: '#bbf7d0' },
  out: { done: '#dc2626', known: '#f87171', typical: '#fecaca' },
}

// An estimate's colour is --estimate, not background: see .app-estimate.
const paint = (colour, estimate, style = {}) =>
  estimate ? { ...style, '--estimate': colour } : { ...style, background: colour }

// Each key half green and half red, as the shade means the same on either bar.
const legend = ['done', 'known', 'typical'].map(kind => ({
  kind,
  label: { done: 'Done', known: 'Known to come', typical: 'Typical, an estimate' }[kind],
  colour: `linear-gradient(90deg, ${shades.in[kind]} 50%, ${shades.out[kind]} 50%)`,
}))

// Each month's figures as decimal strings, totals added exactly by plus(); the widths alone
// are floats, on one scale shared by both months.
const monthCols = computed(() => {
  const average = props.month?.average_net ?? null
  const versus = net => {
    if (average === null) return null

    const gap = minus(net, average)

    return { up: !negative(gap), amount: negative(gap) ? gap.slice(1) : gap }
  }

  const cols = []

  if (props.month) {
    const m = props.month

    cols.push({
      key: 'current',
      name: monthName(m.month),
      path: '/cash-flow',
      forecast: false,
      days: monthDays.value,
      net: m.likely_net,
      vsAverage: versus(m.likely_net),
      flows: {
        in: { done: m.so_far.income, known: m.to_come.income, typical: m.typical_income_rest },
        out: { done: m.so_far.spending, known: m.to_come.spending, typical: m.typical_rest },
      },
    })
  }

  if (props.nextMonth) {
    const m = props.nextMonth

    cols.push({
      key: 'next',
      name: monthName(m.month),
      path: '/forecast',
      forecast: true,
      net: m.net_typical,
      vsAverage: versus(m.net_typical),
      note: isZero(m.dividends) ? '' : `In includes ~${money(m.dividends)} of dividends`,
      flows: {
        in: { done: '0', known: m.in, typical: m.typical_in },
        out: { done: '0', known: m.out, typical: m.typical },
      },
    })
  }

  const totalOf = flow => plus(plus(flow.done, flow.known), flow.typical)
  const scale = Math.max(
    1,
    ...cols.flatMap(col => [Number(totalOf(col.flows.in)), Number(totalOf(col.flows.out))]),
  )

  return cols.map(col => ({
    ...col,
    bars: ['in', 'out'].map(side => ({
      label: side === 'in' ? 'In' : 'Out',
      total: totalOf(col.flows[side]),
      parts: ['done', 'known', 'typical']
        .filter(kind => !isZero(col.flows[side][kind]))
        .map(kind => ({
          kind: legend.find(key => key.kind === kind)?.label ?? kind,
          estimate: kind === 'typical',
          amount: col.flows[side][kind],
          width: (Number(col.flows[side][kind]) / scale) * 100,
          colour: shades[side][kind],
        })),
    })),
  }))
})

// In the script: the template cannot see the auto-imported router.
const go = path => router.visit(path)
</script>
