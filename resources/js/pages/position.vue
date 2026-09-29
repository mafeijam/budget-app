<template>
  <div class="column no-wrap q-gutter-lg">
    <div class="row items-center">
      <div class="text-h6 text-weight-medium">Positions</div>
      <q-space />

      <div class="app-toolbar row items-center no-wrap">
        <q-toggle
          v-model="showClosed"
          label="Show sold out"
          color="primary"
          dense
          class="q-px-sm"
        />

        <q-separator vertical inset class="q-mx-sm" />

        <div class="row items-center no-wrap q-px-sm">
          <q-icon name="schedule" size="xs" color="grey-6" class="q-mr-sm" />
          <div class="column">
            <span class="text-caption text-grey-6 app-toolbar__label">Prices updated</span>
            <span class="text-body2 text-grey-9">
              {{ pricesUpdatedAt ? whenUpdated(pricesUpdatedAt) : 'Never' }}
            </span>
          </div>
          <!-- A timestamp column, so the time formatter, not the calendar-day one. -->
          <q-tooltip v-if="pricesUpdatedAt" :delay="500" :offset="[0, 6]">
            {{ formatTime(pricesUpdatedAt) }}
          </q-tooltip>
        </div>

        <q-btn
          unelevated
          no-caps
          class="text-weight-bold app-btn q-ml-sm"
          icon="refresh"
          label="Fetch prices"
          :loading="fetching"
          @click="fetchPrices"
        />
      </div>
    </div>

    <div v-if="!brokerages.length" class="text-grey-6">
      No brokerage account yet. Add one on Accounts, then record buys and sells on Transactions.
    </div>

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
          <div class="text-subtitle1 text-weight-bold money" :class="figure.class">
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
            <th class="text-right">Price</th>
            <th class="text-right">Market value</th>
            <th class="text-right">Unrealised</th>
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
            <td class="text-right money">
              {{ position.average_cost ? money(position.average_cost) : '' }}
            </td>
            <td class="text-right money">{{ position.open ? money(position.cost) : '' }}</td>
            <td class="text-right money cursor-pointer">
              <template v-if="position.price">
                {{ money(position.price) }}
                <div class="text-caption text-grey-6">
                  {{ formatDate(position.price_date)
                  }}{{ position.price_source === 'manual' ? ' · manual' : '' }}
                </div>
              </template>
              <span v-else-if="position.open" class="text-grey-5">set price</span>
              <q-popup-edit
                v-if="position.open"
                v-slot="scope"
                :model-value="position.price"
                buttons
                label-set="Save"
                @save="value => savePrice(broker, position, value)"
              >
                <q-input
                  v-model="scope.value"
                  type="number"
                  step="0.0001"
                  dense
                  autofocus
                  :label="`${position.symbol} today, ${broker.ccy}`"
                  @keyup.enter="scope.set"
                />
              </q-popup-edit>
            </td>
            <td class="text-right money">
              {{ position.market_value ? money(position.market_value) : '' }}
            </td>
            <td class="text-right money" :class="signClass(position.unrealised)">
              {{ position.unrealised ? money(position.unrealised) : '' }}
            </td>
            <td class="text-right text-grey-7 money">{{ money(position.fees) }}</td>
            <td class="text-right money" :class="signClass(position.realised)">
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
  pricesUpdatedAt: { type: String, default: null },
})

const money = useMoney()
const formatDate = useCalendarDay()
const formatTime = useHongKongTime()

// "Today, 12:26" in Hong Kong's day; the tooltip carries the full timestamp.
const tz = usePage().props.tz
const dayOf = new Intl.DateTimeFormat('en-CA', { timeZone: tz })
const clock = new Intl.DateTimeFormat('en-GB', { timeZone: tz, hour: '2-digit', minute: '2-digit' })
const shortDay = new Intl.DateTimeFormat('en-GB', { timeZone: tz, day: 'numeric', month: 'short' })

const whenUpdated = value => {
  const at = new Date(value)
  const day = dayOf.format(at)
  const today = dayOf.format(new Date())
  const yesterday = dayOf.format(new Date(Date.now() - 86400000))

  const name = day === today ? 'Today' : day === yesterday ? 'Yesterday' : shortDay.format(at)

  return `${name}, ${clock.format(at)}`
}

const showClosed = ref(false)

const shown = broker => broker.positions.filter(position => position.open || showClosed.value)

// Trimmed on the string: a quantity is a decimal, never a float.
const quantity = value => {
  const trimmed = String(value).includes('.') ? String(value).replace(/\.?0+$/, '') : String(value)

  return trimmed === '' ? '0' : trimmed
}

const $q = useQuasar()

const fetching = ref(false)

const fetchPrices = () => {
  router.post(
    '/prices/fetch',
    {},
    {
      preserveScroll: true,
      // The button's own spinner -- see plugins/quasar.js.
      showProgress: false,
      onStart: () => (fetching.value = true),
      onSuccess: () => notifySuccess(),
      onFinish: () => (fetching.value = false),
    },
  )
}

const savePrice = (broker, position, close) => {
  router.post(
    '/prices',
    { account_id: broker.id, symbol: position.symbol, close },
    {
      preserveScroll: true,
      onSuccess: () => notifySuccess(),
      onError: errors => $q.notify({ type: 'negative', message: Object.values(errors)[0] }),
    },
  )
}

const signClass = value => {
  if (String(value).startsWith('-')) return 'text-negative'

  return /[1-9]/.test(String(value)) ? 'text-positive' : ''
}

const figures = broker => [
  {
    label: broker.unpriced ? `Market value (${broker.unpriced} unpriced)` : 'Market value',
    value: money(broker.market_value),
    class: 'text-grey-9',
  },
  { label: 'Unrealised', value: money(broker.unrealised), class: signClass(broker.unrealised) },
  { label: 'Cost held', value: money(broker.open_cost), class: 'text-grey-9' },
  { label: 'Realised', value: money(broker.realised), class: signClass(broker.realised) },
  { label: 'Fees', value: money(broker.fees), class: 'text-grey-9' },
  { label: 'Dividends', value: money(broker.dividends), class: 'text-grey-9' },
]
</script>
