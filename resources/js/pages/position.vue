<template>
  <div class="column q-gutter-lg">
    <div class="row items-center">
      <div class="text-h6 text-weight-medium">Positions</div>
      <q-space />
      <q-toggle v-model="showClosed" label="Show sold out" color="blue-9" dense />
    </div>

    <div v-if="!brokerages.length" class="text-grey-6">
      No brokerage account yet. Add one on Accounts, then record buys and sells on Transactions.
    </div>

    <!--
      Read-only on purpose: a position is worked out from the trades, so it changes by
      recording a trade on Transactions, never by editing a figure here.
    -->
    <q-card v-for="broker in brokerages" :key="broker.id" flat bordered>
      <q-card-section class="row items-center q-gutter-sm">
        <q-icon name="show_chart" size="sm" color="grey-6" />
        <div class="text-subtitle1 text-weight-medium">{{ broker.name }}</div>
        <q-badge outline color="grey-7" :label="broker.ccy" />
        <div v-if="broker.settles_into" class="text-caption text-grey-7">
          Settles into {{ broker.settles_into }}
        </div>
        <q-space />
        <div v-for="figure in figures(broker)" :key="figure.label" class="text-right q-ml-lg">
          <div class="text-caption text-grey-7">{{ figure.label }}</div>
          <div class="text-subtitle1 text-weight-bold" :class="figure.class">
            {{ figure.value }}
          </div>
        </div>
      </q-card-section>

      <q-separator />

      <q-markup-table v-if="shown(broker).length" flat dense>
        <thead>
          <tr class="text-grey-7">
            <th class="text-left">Symbol</th>
            <th class="text-right">Quantity</th>
            <th class="text-right">Average cost</th>
            <th class="text-right">Cost</th>
            <th class="text-right">Fees</th>
            <th class="text-right">Realised</th>
            <th class="text-right">Trades</th>
            <th class="text-right">Last trade</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="position in shown(broker)"
            :key="position.symbol"
            :class="{ 'text-grey-6': !position.open }"
          >
            <td class="text-weight-medium">
              {{ position.symbol }}
              <q-badge
                v-if="!position.open"
                color="grey-3"
                text-color="grey-8"
                class="q-ml-sm"
                label="sold out"
              />
            </td>
            <td class="text-right">{{ quantity(position.quantity) }}</td>
            <td class="text-right">
              {{ position.average_cost ? money(position.average_cost) : '' }}
            </td>
            <td class="text-right">{{ position.open ? money(position.cost) : '' }}</td>
            <td class="text-right text-grey-7">{{ money(position.fees) }}</td>
            <td class="text-right" :class="signClass(position.realised)">
              {{ money(position.realised) }}
            </td>
            <td class="text-right">{{ position.trades }}</td>
            <td class="text-right">{{ formatDate(position.last_trade_date) }}</td>
          </tr>
        </tbody>
      </q-markup-table>

      <q-card-section v-else class="text-grey-6">
        {{ broker.positions.length ? 'Everything here has been sold.' : 'No trades yet.' }}
      </q-card-section>
    </q-card>
  </div>
</template>

<script setup>
defineProps({
  brokerages: { type: Array, default: Array },
})

const money = useMoney()
const formatDate = useCalendarDay()

const showClosed = ref(false)

const shown = broker => broker.positions.filter(position => position.open || showClosed.value)

// Eight places is the scale a quantity is stored at, and a whole share should read as
// one. Trimmed on the string -- a quantity is a decimal, not a float, the same as money.
const quantity = value => {
  const trimmed = String(value).includes('.') ? String(value).replace(/\.?0+$/, '') : String(value)

  return trimmed === '' ? '0' : trimmed
}

// Profit green, loss red, nothing plain.
const signClass = value => {
  if (String(value).startsWith('-')) return 'text-negative'

  return /[1-9]/.test(String(value)) ? 'text-positive' : ''
}

// What the brokerage's header totals, each in its own currency: the cost of what is
// still held, what selling has realised, the fees paid dealing, and the dividends
// received. Fees stand apart from cost and realised, so each reads as what it is.
const figures = broker => [
  { label: 'Cost held', value: money(broker.open_cost), class: 'text-grey-9' },
  { label: 'Realised', value: money(broker.realised), class: signClass(broker.realised) },
  { label: 'Fees', value: money(broker.fees), class: 'text-grey-9' },
  { label: 'Dividends', value: money(broker.dividends), class: 'text-grey-9' },
]
</script>
