<template>
  <div class="column no-wrap q-gutter-lg">
    <div class="row items-center">
      <div class="text-h6 text-weight-medium q-mr-md">Positions</div>

      <q-select
        v-if="brokerages.length > 1"
        v-model="brokerId"
        :options="brokerOptions"
        class="app-broker-select"
        dense
        outlined
        emit-value
        map-options
        options-dense
      >
        <template #prepend>
          <q-icon name="show_chart" size="xs" color="grey-7" />
        </template>

        <template #option="scope">
          <q-item v-bind="scope.itemProps">
            <q-item-section>
              {{ scope.opt.label }}
              <q-item-label caption>{{ scope.opt.caption }}</q-item-label>
            </q-item-section>
          </q-item>
        </template>
      </q-select>

      <q-space />

      <div class="app-toolbar row items-center no-wrap">
        <!-- A calendar day, so the day formatter; the server owns today, not the browser. -->
        <q-btn
          flat
          dense
          no-caps
          icon="event"
          :label="at ? `As at ${formatDate(at)}` : 'Today'"
          :color="at ? 'primary' : 'grey-8'"
          class="q-px-sm text-weight-bold"
        >
          <q-menu ref="dayMenu" :offset="[0, 8]">
            <q-date
              :model-value="at ?? today"
              mask="YYYY-MM-DD"
              minimal
              color="primary"
              :options="day => day <= today.replaceAll('-', '/')"
              @update:model-value="pickDay"
            />
          </q-menu>
          <q-tooltip :delay="500" :offset="[0, 6]">
            Look back: what was held that day, at its last close
          </q-tooltip>
        </q-btn>
        <q-btn
          v-if="at"
          flat
          dense
          round
          size="sm"
          icon="close"
          color="grey-7"
          @click="visit(null)"
        >
          <q-tooltip :delay="500" :offset="[0, 6]">Back to today</q-tooltip>
        </q-btn>

        <q-separator vertical inset class="q-mx-sm" />

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

    <q-card v-if="view" :key="view.key" flat bordered>
      <q-card-section class="row items-center q-gutter-sm q-pb-sm">
        <q-icon :name="view.all ? 'stacked_line_chart' : 'show_chart'" size="sm" color="grey-6" />
        <div class="text-subtitle1 text-weight-medium">{{ view.title }}</div>
        <q-badge v-for="ccy in view.currencies" :key="ccy" outline color="grey-7" :label="ccy" />
        <div v-if="view.caption" class="text-caption text-grey-7">{{ view.caption }}</div>
      </q-card-section>

      <!-- One row per currency, and with several, their sum in the base currency first. -->
      <div
        v-for="row in view.figureRows"
        :key="row.ccy"
        class="app-figures"
        :class="{ 'app-figures--combined': row.combined }"
      >
        <div v-for="figure in figures(row.totals, row.prefix)" :key="figure.label">
          <div class="text-caption text-grey-7 ellipsis">{{ figure.label }}</div>
          <div class="text-h6 text-weight-bold money" :class="figure.class">
            {{ figure.value }}
          </div>
        </div>
      </div>

      <q-separator />

      <q-markup-table v-if="view.rows.length" flat dense>
        <thead>
          <tr class="text-grey-7">
            <th class="text-left">Symbol</th>
            <th v-if="view.all" class="text-left">Brokerage</th>
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
            v-for="{ owner, position } in view.rows"
            :key="`${owner.id}-${position.symbol}`"
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
            <td v-if="view.all" class="text-grey-8">
              {{ owner.name }}
              <!-- The row's figures are in this, and two currencies share the table. -->
              <q-badge
                v-if="view.currencies.length > 1"
                outline
                color="grey-6"
                class="q-ml-xs"
                :label="owner.ccy"
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
              <span v-else-if="position.open && !at" class="text-grey-5">set price</span>
              <span v-else-if="position.open" class="text-grey-5">no price</span>
              <!-- A hand-set price is filed for today, so not while looking back. -->
              <q-popup-edit
                v-if="position.open && !at"
                v-slot="scope"
                :model-value="position.price"
                buttons
                label-set="Save"
                @save="value => savePrice(owner, position, value)"
              >
                <q-input
                  v-model="scope.value"
                  type="number"
                  step="0.0001"
                  dense
                  autofocus
                  :label="`${position.symbol} today, ${owner.ccy}`"
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
        {{ view.traded ? 'Everything here has been sold.' : 'No trades yet.' }}
      </q-card-section>
    </q-card>
  </div>
</template>

<script setup>
const props = defineProps({
  brokerages: { type: Array, default: Array },
  totals: { type: Array, default: Array },
  combined: { type: Object, default: null },
  base: { type: String, default: 'HKD' },
  pricesUpdatedAt: { type: String, default: null },
  at: { type: String, default: null },
  today: { type: String, default: null },
})

const dayMenu = ref(null)

const visit = day =>
  router.get('/positions', day ? { at: day } : {}, { preserveScroll: true, replace: true })

const pickDay = day => {
  dayMenu.value?.hide()
  visit(day && day !== props.today ? day : null)
}

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

// 0 is All. Remembered per browser, so a Fetch prices reload keeps the choice.
const brokerId = useStorage('positions.broker', 0)

// With one brokerage there is no dropdown and nothing to total, so it is shown itself.
const broker = computed(() =>
  props.brokerages.length === 1
    ? props.brokerages[0]
    : (props.brokerages.find(b => b.id === brokerId.value) ?? null),
)

// A stored id that no longer names a brokerage falls back to All rather than to nothing.
watchEffect(() => {
  if (props.brokerages.length > 1 && brokerId.value !== 0 && !broker.value) brokerId.value = 0
})

const totalsCaption = computed(() =>
  [
    ...props.totals.map(total => `${money(total.market_value)} ${total.ccy}`),
    ...(props.combined ? [`${money(props.combined.market_value)} ${props.base} in all`] : []),
  ].join(' · '),
)

const brokerOptions = computed(() => [
  { label: 'All brokerages', value: 0, caption: totalsCaption.value },
  ...props.brokerages.map(b => ({
    label: b.name,
    value: b.id,
    caption: `${money(b.market_value)} ${b.ccy}`,
  })),
])

const rowsOf = b => shown(b).map(position => ({ owner: b, position }))

// Same symbol at two brokerages stays two rows: each has its own cost basis.
const view = computed(() => {
  if (broker.value) {
    return {
      key: broker.value.id,
      all: false,
      title: broker.value.name,
      currencies: [broker.value.ccy],
      caption: broker.value.settles_into ? `Settles into ${broker.value.settles_into}` : '',
      figureRows: [{ ccy: broker.value.ccy, totals: broker.value, prefix: '' }],
      rows: rowsOf(broker.value),
      traded: broker.value.positions.length > 0,
    }
  }

  if (!props.brokerages.length) return null

  const several = props.totals.length > 1

  return {
    key: 'all',
    all: true,
    title: 'All brokerages',
    currencies: props.totals.map(total => total.ccy),
    caption: [
      `${props.brokerages.length} brokerages`,
      ...(props.combined
        ? [`all in ${props.base} at ${props.at ? "that day's" : "today's"} rate`]
        : []),
      ...(props.combined?.unconverted.length
        ? [`${props.combined.unconverted.join(', ')} left out of it, no rate yet`]
        : []),
    ].join(' · '),
    figureRows: [
      ...(props.combined
        ? [
            {
              ccy: 'all',
              totals: props.combined,
              prefix: `All in ${props.base} · `,
              combined: true,
            },
          ]
        : []),
      ...props.totals.map(total => ({
        ccy: total.ccy,
        totals: total,
        prefix: several ? `${total.ccy} ` : '',
      })),
    ],
    rows: props.brokerages
      .flatMap(rowsOf)
      .sort(
        (a, b) =>
          a.position.symbol.localeCompare(b.position.symbol) ||
          a.owner.name.localeCompare(b.owner.name),
      ),
    traded: props.brokerages.some(b => b.positions.length > 0),
  }
})

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

const figures = (broker, prefix = '') => [
  {
    label: `${prefix}${broker.unpriced ? `Market value (${broker.unpriced} unpriced)` : 'Market value'}`,
    value: money(broker.market_value),
    class: 'text-grey-9',
  },
  {
    label: `${prefix}Unrealised`,
    value: money(broker.unrealised),
    class: signClass(broker.unrealised),
  },
  { label: `${prefix}Cost held`, value: money(broker.open_cost), class: 'text-grey-9' },
  { label: `${prefix}Realised`, value: money(broker.realised), class: signClass(broker.realised) },
  { label: `${prefix}Fees`, value: money(broker.fees), class: 'text-grey-9' },
  { label: `${prefix}Dividends`, value: money(broker.dividends), class: 'text-grey-9' },
]
</script>
