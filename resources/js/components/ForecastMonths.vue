<template>
  <div class="app-month-table" role="table" :aria-label="label">
    <div class="app-month-table__head text-caption text-grey-7" role="row">
      <span role="columnheader">Month</span>
      <span role="columnheader" class="app-month-table__legend">
        <span class="row items-center no-wrap">
          <span class="cash-flow-chart__swatch" :style="{ background: colours.in }" />In
        </span>
        <span class="row items-center no-wrap">
          <span class="cash-flow-chart__swatch" :style="{ background: colours.out }" />Out
        </span>
        <span v-if="typical" class="row items-center no-wrap">
          <span class="cash-flow-chart__swatch app-month-table__typical-swatch" />Typical
        </span>
      </span>
      <span role="columnheader" class="text-right">In</span>
      <span role="columnheader" class="text-right">Out</span>
      <span role="columnheader" class="text-right">Net</span>
      <span role="columnheader" class="text-right">Ends at</span>
    </div>

    <div v-for="row in rows" :key="row.month" class="app-month-table__row" role="row">
      <div role="cell">
        <div class="text-weight-medium text-grey-9">{{ row.label }}</div>
        <div v-if="row.caption" class="text-caption text-grey-6">{{ row.caption }}</div>
      </div>

      <!-- In over out, known solid and typical pale beyond it, so a month that leans on
           estimates looks like one. -->
      <div role="cell" class="app-month-table__bars">
        <div v-for="side in row.bars" :key="side.key" class="app-month-table__track">
          <span
            class="app-month-table__bar"
            :style="{ width: `${side.known}%`, background: side.colour }"
          />
          <span
            v-if="side.typical"
            class="app-month-table__bar"
            :style="{ width: `${side.typical}%`, background: side.pale }"
          />
        </div>
      </div>

      <div role="cell" class="text-right money">
        <div class="text-positive">+{{ money(row.in) }}</div>
        <div v-if="row.typicalIn" class="text-caption app-text-estimate">
          ~{{ money(row.typicalIn) }} typical
        </div>
      </div>
      <div role="cell" class="text-right money">
        <div class="text-negative">−{{ money(row.out) }}</div>
        <div v-if="row.typicalOut" class="text-caption app-text-estimate">
          ~{{ money(row.typicalOut) }} typical
        </div>
      </div>
      <div role="cell" class="text-right money text-weight-bold" :class="signClass(row.net)">
        {{ signed(row.net) }}
      </div>
      <div
        role="cell"
        class="text-right money"
        :class="isNegative(row.end) ? 'text-negative' : 'text-grey-9'"
      >
        {{ money(row.end) }}
      </div>

      <q-tooltip
        anchor="top middle"
        self="bottom middle"
        :offset="[0, 4]"
        :delay="300"
        class="app-month-table__tip"
      >
        <div class="text-weight-bold q-mb-xs">
          {{ row.label }}{{ row.caption ? `, ${row.caption}` : '' }}
        </div>
        <div v-for="line in row.breakdown" :key="line.label" class="row no-wrap items-center">
          <span class="cash-flow-chart__swatch" :style="{ background: line.colour }" />
          <span class="q-mr-md">{{ line.label }}</span>
          <q-space />
          <span class="money text-weight-medium">{{ line.value }}</span>
        </div>
      </q-tooltip>
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
  typicalIn: '#6ee7b7',
}

const monthLabel = new Intl.DateTimeFormat('en', {
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
})

// The month today is in is only its days still to come, unless the page has given it whole.
const partial = month => month.month === props.current && !month.whole

// On the decimal string, not a float.
const isZero = value => /^-?0*(\.0*)?$/.test(String(value ?? '0'))
const isNegative = value => String(value).startsWith('-') && !isZero(value)
const signClass = value => (isNegative(value) ? 'text-negative' : 'text-positive')
const signed = value =>
  isNegative(value) ? `−${money(String(value).slice(1))}` : `+${money(value)}`

// Floats for the bars' lengths only: every figure shown is the server's string, or plus() of
// two of them.
const inOf = month => plus(month.in, props.typical ? month.typical_in : '0')
const outOf = month => plus(month.out, props.typical ? month.typical : '0')
// Each month on its own scale, the larger side the whole track: the bars say how the month
// balances, and the figures beside them say how big it is.
const share = (value, month) =>
  (Math.max(0, Number(value)) / Math.max(1, Number(inOf(month)), Number(outOf(month)))) * 100

const breakdown = month => [
  { label: 'Known in', value: money(month.in), colour: colours.in },
  ...(props.typical
    ? [
        { label: 'Typical income', value: money(month.typical_in), colour: colours.typicalIn },
        ...[
          ['double_pay', 'of it, the double pay'],
          ['bonuses', 'of it, the bonus'],
          ['dividends', 'of it, dividends'],
        ]
          .filter(([key]) => !isZero(month[key]))
          .map(([key, label]) => ({ label, value: money(month[key]), colour: 'transparent' })),
      ]
    : []),
  { label: 'Known out', value: money(month.out), colour: colours.out },
  ...(props.typical
    ? [{ label: 'Typical spending', value: money(month.typical), colour: colours.typical }]
    : []),
]

const rows = computed(() =>
  props.months.map(month => {
    const [year, number] = month.month.split('-').map(Number)
    const typicalIn = props.typical && !isZero(month.typical_in) ? month.typical_in : null
    const typicalOut = props.typical && !isZero(month.typical) ? month.typical : null

    return {
      month: month.month,
      label: monthLabel.format(new Date(Date.UTC(year, number - 1, 1))),
      caption: partial(month) ? 'from tomorrow' : '',
      in: inOf(month),
      out: outOf(month),
      typicalIn,
      typicalOut,
      net: props.typical ? month.net_typical : month.net_known,
      end: props.typical ? month.end_typical : month.end_known,
      bars: [
        {
          key: 'in',
          known: share(month.in, month),
          typical: typicalIn ? share(typicalIn, month) : 0,
          colour: colours.in,
          pale: colours.typicalIn,
        },
        {
          key: 'out',
          known: share(month.out, month),
          typical: typicalOut ? share(typicalOut, month) : 0,
          colour: colours.out,
          pale: colours.typical,
        },
      ],
      breakdown: breakdown(month),
    }
  }),
)

const label = computed(() => `Money in and out per month ahead, in ${props.ccy}`)
</script>
