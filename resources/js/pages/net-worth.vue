<template>
  <div class="column no-wrap q-gutter-lg">
    <div>
      <div class="row items-end q-col-gutter-md">
        <div class="col">
          <div class="text-h6 text-weight-medium">
            Net worth
            <span v-if="at" class="text-subtitle1 text-grey-7">on {{ dayLabel(at) }}</span>
          </div>
          <div class="text-caption text-grey-7">
            Cash, plus the stocks at their last close, in {{ base }}. What the cards owe is not
            subtracted here. Pending rows are left out. Click a point on the chart to see that day.
          </div>
        </div>
        <div v-if="at" class="col-auto">
          <q-btn
            class="text-weight-bold app-btn"
            unelevated
            no-caps
            icon="today"
            label="Back to today"
            @click="visit({ at: null })"
          />
        </div>
      </div>
    </div>

    <div
      v-if="current.unpriced || current.unconverted.length"
      class="app-note app-note--warning row no-wrap"
    >
      <q-icon name="info_outline" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
      <div>
        <template v-if="current.unpriced">
          {{ current.unpriced }} holding{{ current.unpriced === 1 ? ' has' : 's have' }} no price
          yet and {{ current.unpriced === 1 ? 'is' : 'are' }} counted at cost.
        </template>
        <template v-if="current.unconverted.length">
          No {{ current.unconverted.join(', ') }} rate yet, so those accounts are left out.
        </template>
        Fetch prices on the Positions page, or run <code>prices:fetch --history</code>.
      </div>
    </div>

    <!-- The total and what it is made of, then the money and the stocks behind it. -->
    <q-card flat bordered>
      <q-card-section>
        <div class="row items-start q-col-gutter-lg">
          <div class="col-12 col-md-5">
            <div class="text-caption text-grey-7">Net worth</div>
            <div class="text-h4 text-weight-bold money">{{ figure(current.net_worth) }}</div>
            <div class="row items-center q-col-gutter-x-md q-gutter-y-xs q-mt-sm">
              <div v-for="item in changes" :key="item.key" class="row items-center no-wrap">
                <q-badge
                  class="app-change q-mr-sm"
                  :class="item.up ? 'app-tint app-tint--positive' : 'app-tint app-tint--negative'"
                >
                  <q-icon :name="item.up ? 'trending_up' : 'trending_down'" size="14px" />
                  <span class="money">{{ item.up ? '+' : '' }}{{ money(item.amount) }}</span>
                  <span v-if="item.pct" class="app-change__pct">{{ item.pct }}</span>
                </q-badge>
                <span class="text-caption text-grey-7">{{ item.label }}</span>
              </div>
            </div>
          </div>

          <!-- What holds it up and what pulls it down, each as its share of the total. -->
          <div class="col-12 col-md-7">
            <div class="app-worth-bar">
              <div
                v-for="part in parts.filter(p => p.gross > 0)"
                :key="part.key"
                class="app-worth-bar__part"
                :style="{ flexGrow: part.gross, background: part.colour }"
              >
                <q-tooltip :offset="[0, 6]">
                  {{ part.label }} {{ figure(part.value) }} · {{ share(part.value) }}
                </q-tooltip>
              </div>
            </div>
            <div class="app-worth-parts q-mt-sm">
              <div v-for="part in parts" :key="part.key">
                <div class="row items-center no-wrap text-caption text-grey-7">
                  <span class="app-worth-swatch q-mr-xs" :style="{ background: part.colour }" />
                  {{ part.label }}
                </div>
                <div class="text-subtitle1 text-weight-bold money" :class="part.class">
                  {{ figure(part.value) }}
                </div>
                <div class="text-caption text-grey-6">{{ share(part.value) }} of net worth</div>
              </div>
            </div>
          </div>
        </div>
      </q-card-section>
    </q-card>

    <q-card flat bordered>
      <q-card-section class="row items-center q-col-gutter-md">
        <div class="col">
          <div class="text-subtitle1 text-weight-medium">Over time</div>
          <div class="text-caption text-grey-7">
            A snapshot at the end of each {{ periodName }}, and today's.
          </div>
        </div>
        <!-- The Positions page's toolbar, so the pages' controls read alike. -->
        <div class="col-auto app-toolbar row items-center no-wrap">
          <q-icon name="date_range" size="xs" color="grey-6" class="q-mx-sm" />
          <q-btn-toggle
            :model-value="months"
            :options="periods.map(n => ({ label: periodLabels[n] ?? `${n}M`, value: n }))"
            no-caps
            unelevated
            dense
            toggle-color="blue-1"
            toggle-text-color="primary"
            text-color="grey-8"
            padding="xs md"
            class="app-toolbar__toggle text-weight-bold"
            @update:model-value="choose"
          />
        </div>
      </q-card-section>

      <q-separator />

      <q-card-section>
        <NetWorthChart
          v-if="history.length"
          :history="history"
          :base="base"
          :months="months"
          :selected="at ?? history.at(-1)?.date"
          @select="pick"
        />
        <div v-else class="text-grey-6">No transactions yet.</div>
      </q-card-section>
    </q-card>

    <!-- Wrapped: the column's gutter margin would undo the row's own negative one. -->
    <div>
      <div class="row q-col-gutter-md">
        <div class="col-12 col-md-5">
          <q-card flat bordered class="app-worth-card full-height">
            <q-card-section class="row items-center q-pb-sm">
              <q-icon name="account_balance" size="sm" color="grey-7" class="q-mr-sm" />
              <div class="text-subtitle1 text-weight-medium">Cash</div>
              <q-space />
              <div class="money text-weight-bold text-positive">{{ figure(current.cash) }}</div>
            </q-card-section>
            <q-separator />
            <template v-for="group in cashGroups.groups" :key="group.ccy">
              <div v-if="group.headed" class="app-worth-row app-worth-row--group">
                <span class="app-home-list__group-label">
                  <span class="app-home-list__group-ccy">{{ group.ccy }}</span>
                  <span class="app-home-list__group-count">
                    {{ counted(group.items.length, 'account') }}
                  </span>
                </span>
                <div class="app-home-list__group-figures text-right">
                  <!-- What these rows come to in the currency the heading names. A run of one
                       has no sum to state: its own money is on the row beneath it. -->
                  <div
                    v-if="group.ownShown"
                    class="money text-caption text-weight-medium text-grey-8"
                  >
                    {{ money(group.own) }}
                    <!-- The rate on a run held in another currency, where nothing else in the
                         card states it: a run of one has no row figure to put it on. -->
                    <q-tooltip v-if="rateFor(group)" :delay="500" :offset="[0, 6]">
                      1 {{ group.ccy }} = {{ rateFor(group) }} {{ base }}
                    </q-tooltip>
                  </div>
                </div>
              </div>

              <div v-for="account in group.items" :key="account.id" class="app-worth-row">
                <span class="text-grey-9 ellipsis">{{ account.name }}</span>
                <div class="text-right">
                  <!-- As on the home page's cards: what the account holds takes the figure, and
                       what it is worth in the card's currency goes under it in the same light
                       grey. A card holding one currency has no heading to name the own money,
                       so there it carries its code. -->
                  <span class="money text-weight-medium">
                    {{
                      account.converted
                        ? group.headed
                          ? money(account.own)
                          : `${account.ccy} ${money(account.own)}`
                        : money(account.total)
                    }}
                    <q-tooltip
                      v-if="account.converted && rateFor(account)"
                      :delay="500"
                      :offset="[0, 6]"
                    >
                      1 {{ account.ccy }} = {{ rateFor(account) }} {{ base }}
                    </q-tooltip>
                  </span>
                  <div v-if="account.converted" class="text-caption text-grey-6 money">
                    {{ base }} {{ money(account.total) }}
                  </div>
                  <!-- A card holding one currency throughout has no heading over its rows, so
                       a row nothing could convert is a figure in its own currency with nothing
                       saying that. -->
                  <div
                    v-if="!group.headed && !account.converted && account.ccy !== base"
                    class="text-caption text-grey-6"
                  >
                    {{ account.ccy }}
                  </div>
                </div>
              </div>
            </template>
            <div v-if="!isZero(current.cards)" class="app-worth-row app-worth-row--total">
              <span class="text-grey-9 text-weight-medium">Cards owe</span>
              <span class="money text-weight-bold text-negative">{{ money(current.cards) }}</span>
            </div>
          </q-card>
        </div>

        <div class="col-12 col-md-7">
          <q-card flat bordered class="app-worth-card full-height">
            <q-card-section class="row items-center q-pb-sm">
              <q-icon name="show_chart" size="sm" color="grey-7" class="q-mr-sm" />
              <div class="text-subtitle1 text-weight-medium">Stocks</div>
              <q-space />
              <!-- The same pill as the net worth card's changes, with the label that card's
                   carry after theirs: a bare percentage beside the figure did not say what it
                   was a percentage of. -->
              <div v-if="unrealisedPct" class="row items-center no-wrap q-mr-lg">
                <q-badge
                  class="app-change q-mr-sm"
                  :class="
                    up(current.unrealised)
                      ? 'app-tint app-tint--positive'
                      : 'app-tint app-tint--negative'
                  "
                >
                  <q-icon
                    :name="up(current.unrealised) ? 'trending_up' : 'trending_down'"
                    size="14px"
                  />
                  <span class="money">
                    {{ up(current.unrealised) ? '+' : '' }}{{ money(current.unrealised) }}
                  </span>
                  <span class="app-change__pct">{{ unrealisedPct }}</span>
                </q-badge>
                <span class="text-caption text-grey-7">unrealised</span>
              </div>
              <div class="money text-weight-bold text-primary">{{ figure(current.value) }}</div>
            </q-card-section>
            <q-separator />
            <!-- Value, cost and the gain between them, a row a brokerage and the sum under. -->
            <div class="app-worth-stocks app-worth-stocks--head text-caption text-grey-7">
              <span>Brokerage</span>
              <span class="text-right">Market value</span>
              <span class="text-right">Cost</span>
              <span class="text-right">Unrealised</span>
            </div>
            <!--
              Every run is named, as the home page's cards name theirs: the code and what the run
              is made of, over the rows it is made of. A run's own sum is a row like the All
              brokerages one under it, carrying the three figures it adds, because a heading
              would leave three of this four column table empty.

              A run of one row gets no sum row, because these three figures are the card's
              currency and are already on that one row -- the heading above it is what says which
              currency that row is in, which is what the sum row would have been for. A run
              nothing could convert gets neither, for the same reason: its figures would be its
              own money under the wrong column's name, and this column adds up.
            -->
            <template v-for="group in brokerageGroups" :key="group.ccy">
              <!-- The run's currency and what it is made of, as the home page names its runs.
                   The three columns to the right are the sum row's, and a label leaves them
                   empty -- which is why the sum stays a row and this is not one. -->
              <div v-if="group.headed" class="app-worth-stocks app-worth-stocks--group">
                <span class="app-home-list__group-label">
                  <span class="app-home-list__group-ccy">{{ group.ccy }}</span>
                  <span class="app-home-list__group-count">
                    {{ counted(group.items.length, 'brokerage') }}
                  </span>
                </span>
              </div>

              <div v-for="broker in group.items" :key="broker.id" class="app-worth-stocks">
                <span class="text-grey-9 ellipsis">{{ broker.name }}</span>
                <div class="text-right money">
                  <!-- The money as held takes the figure, and what it came to in the card's
                       currency goes under it: the Cash card beside this one reads the same way,
                       and a row that showed one order and the row next to it the other left the
                       reader working out which way round this table went. -->
                  <div class="text-weight-medium">
                    {{
                      broker.converted ? `${broker.ccy} ${money(broker.own)}` : money(broker.total)
                    }}
                    <q-tooltip
                      v-if="broker.converted && rateFor(broker)"
                      :delay="500"
                      :offset="[0, 6]"
                    >
                      1 {{ broker.ccy }} = {{ rateFor(broker) }} {{ base }}
                    </q-tooltip>
                  </div>
                  <div v-if="broker.converted" class="text-caption text-grey-6">
                    {{ base }} {{ money(broker.total) }}
                  </div>
                  <!-- Nothing converted it, so the figure above is its own money and says so. -->
                  <div v-else-if="broker.ccy !== base" class="text-caption text-grey-6">
                    {{ broker.ccy }}
                  </div>
                </div>
                <!-- Cost as held, with what it came to under it, as the value beside it now
                     reads. -->
                <div class="text-right money text-grey-8">
                  <div>
                    {{
                      broker.converted
                        ? `${broker.ccy} ${money(broker.cost)}`
                        : money(broker.cost_base ?? broker.cost)
                    }}
                  </div>
                  <div v-if="broker.converted" class="text-caption text-grey-6">
                    {{ base }} {{ money(broker.cost_base ?? broker.cost) }}
                  </div>
                </div>
                <!-- The gain as held, with the card's currency under it, as the two columns to
                     its left read. A gain in another currency is a sum in that currency, so its
                     share is taken against that currency's own cost: a percentage of one
                     currency computed over another's cost agrees with the columns beside it
                     only by accident. -->
                <div class="text-right money" :class="signClass(gain(broker))">
                  <div class="text-weight-medium">
                    {{
                      broker.converted
                        ? `${broker.ccy} ${money(broker.unrealised)}`
                        : money(gain(broker))
                    }}
                  </div>
                  <div v-if="broker.converted" class="text-caption text-grey-6">
                    {{ base }} {{ money(broker.unrealised_base) }}
                  </div>
                  <div class="text-caption">
                    {{
                      broker.converted
                        ? percent(broker.unrealised, broker.cost)
                        : percent(gain(broker), broker.cost_base ?? broker.cost)
                    }}
                  </div>
                </div>
              </div>

              <div
                v-if="group.headed && group.inBase && group.items.length > 1"
                class="app-worth-stocks app-worth-stocks--subtotal"
              >
                <!-- The run is named over its rows, so this row is the figures alone: the
                     label above already says which currency is being added up, and saying it
                     again here puts one label on screen twice a few rows apart. -->
                <span class="text-grey-7">All {{ group.ccy }} brokerages</span>
                <!-- The run's own money on top, as its rows carry it, and the sum in the
                     card's currency under it. The base run is the one exception: its own money
                     is the card's currency already, so the figure under it would be the same
                     figure twice. -->
                <span class="text-right money text-weight-medium text-grey-8">
                  <template v-if="group.base">{{ money(group.own) }}</template>
                  <template v-else>
                    <div>{{ group.ccy }} {{ money(group.own) }}</div>
                    <div class="text-caption text-grey-6 money">{{ money(group.total) }}</div>
                  </template>
                </span>
                <!-- The run's own cost and gain, summed as its rows are, with the card's currency
                     beneath. A gain in another currency is a sum in that currency: adding a
                     gain is adding money, and two currencies of it do not add. -->
                <span class="text-right money text-weight-medium text-grey-8">
                  <template v-if="group.base">{{ money(group.cost) }}</template>
                  <template v-else>
                    <div>{{ group.ccy }} {{ money(group.costOwn) }}</div>
                    <div class="text-caption text-grey-6 money">{{ money(group.cost) }}</div>
                  </template>
                </span>
                <span
                  class="text-right money text-weight-medium"
                  :class="signClass(group.unrealised)"
                >
                  <template v-if="group.base">{{ money(group.unrealised) }}</template>
                  <template v-else>
                    <div>{{ group.ccy }} {{ money(group.unrealisedOwn) }}</div>
                    <div class="text-caption text-grey-6 money">{{ money(group.unrealised) }}</div>
                  </template>
                  <!-- Against the run's cost in the same currency, as each row's share is. -->
                  <div class="text-caption text-weight-regular">
                    {{
                      group.base
                        ? percent(group.unrealised, group.cost)
                        : percent(group.unrealisedOwn, group.costOwn)
                    }}
                  </div>
                </span>
              </div>
            </template>
            <div class="app-worth-stocks app-worth-stocks--total">
              <span class="text-grey-9 text-weight-medium">All brokerages</span>
              <span class="text-right money text-weight-bold">{{ money(current.value) }}</span>
              <span class="text-right money text-weight-bold text-grey-8">
                {{ money(current.cost) }}
              </span>
              <span
                class="text-right money text-weight-bold"
                :class="signClass(current.unrealised)"
              >
                {{ money(current.unrealised) }}
              </span>
            </div>
          </q-card>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  base: { type: String, default: 'HKD' },
  // What each currency went at on the day shown, so a row held in another can say what
  // converted it. The day is the one the cards read, not today.
  rates: { type: Object, default: () => ({}) },
  current: { type: Object, default: () => ({ accounts: [], brokerages: [], unconverted: [] }) },
  lastMonth: { type: Object, default: null },
  since: { type: Object, default: null },
  history: { type: Array, default: () => [] },
  months: { type: Number, default: 12 },
  periods: { type: Array, default: () => [1, 3, 6, 12] },
  at: { type: String, default: null },
})

// The spacing and the picked snapshot, each off the URL at its default.
const visit = ({ months = props.months, at = props.at }) =>
  router.get(
    '/net-worth',
    {
      ...(months === 12 ? {} : { months }),
      ...(at ? { at } : {}),
    },
    { preserveScroll: true, replace: true },
  )

// The last point is today's, which is the page without a day picked.
const pick = day => visit({ at: day === props.history.at(-1)?.date ? null : day })

const money = useMoney()

// A rate to four places: a rate is not a sum of money, and its fourth place is the one that
// decides what a converted figure comes to.
const rate = useMoney(4)

const rateFor = item => (props.rates?.[item.ccy] ? rate(props.rates[item.ccy]) : null)

/* Zero plural, because the count and the word are put together here and nowhere else. */
const counted = (count, noun) => `${count} ${noun}${count === 1 ? '' : 's'}`

const periodLabels = { 1: 'Monthly', 3: 'Quarterly', 6: 'Half-yearly', 12: 'Yearly' }

const periodName = computed(
  () => ({ 1: 'month', 3: 'quarter', 6: 'half-year', 12: 'year' })[props.months],
)

// Yearly is the default, so it stays off the URL.
const choose = n => visit({ months: n })

// On the decimal string, not a float.
const isZero = value => /^-?0*(\.0*)?$/.test(String(value ?? '0'))
const up = value => !String(value).startsWith('-')

const signClass = value => (isZero(value) ? '' : up(value) ? 'text-positive' : 'text-negative')

const figure = (value, signed = false) =>
  `${signed && up(value) && !isZero(value) ? '+' : ''}${props.base} ${money(value)}`

const accountsOf = type => props.current.accounts.filter(account => account.type === type)

// A cash account's row, spread so the markup keeps reading the payload's own fields, with
// the two figures its group adds. The own money needs the currency test as well as the base
// one -- this payload gives every account a base figure, base currency included, where the
// home page's leaves it null, so testing `base` alone would count an HKD account's balance as
// something a rate converted.
const cashItems = computed(() =>
  accountsOf('cash')
    .map(account => ({
      ...account,
      total: account.base ?? account.balance,
      own: account.balance,
      converted: account.ccy !== props.base && account.base !== null,
    }))
    .sort(byAmountDescending),
)

const cashGroups = computed(() => groupedByCurrency(cashItems.value, props.base))

// A brokerage carries three figures, so its group sums three. `total` is the market value in
// the card's currency, which is what the sum row carried; cost and the gain are summed
// alongside, in both currencies, so a run held in another can state the sums as that run
// holds them. A gain summed in the base and a gain summed in the brokerage's own currency
// are both legitimate, and mixing them across a run would be neither.
const brokerageItems = computed(() =>
  props.current.brokerages.map(broker => ({
    ...broker,
    total: broker.value_base ?? broker.value,
    own: broker.value,
    converted: broker.ccy !== props.base && broker.value_base !== null,
  })),
)

const brokerageGroups = computed(() =>
  groupedByCurrency(brokerageItems.value, props.base).groups.map(group => ({
    ...group,
    cost: group.items.reduce((sum, item) => plus(sum, item.cost_base ?? item.cost), '0'),
    costOwn: group.items.reduce((sum, item) => plus(sum, item.cost), '0'),
    unrealised: group.items.reduce((sum, item) => plus(sum, gain(item)), '0'),
    unrealisedOwn: group.items.reduce((sum, item) => plus(sum, item.unrealised), '0'),
  })),
)

// Shares and percentages are for reading, not money, so floats are fine here.
// `|| 0` on the rounded figure: a small loss rounds to -0.0, which printed as a loss of nothing.
const percent = (part, whole) =>
  Number(whole) === 0
    ? null
    : `${(Number(((Number(part) / Math.abs(Number(whole))) * 100).toFixed(1)) || 0).toFixed(1)}%`

const share = part => percent(part, props.current.net_worth) ?? '—'

// The server's difference; only the percentage is worked out here.
const change = (against, key, label) => {
  if (!against) return null

  return {
    key,
    label,
    amount: against.change,
    up: up(against.change),
    pct: percent(against.change, against.net_worth),
  }
}

const changes = computed(() =>
  [
    change(props.lastMonth, 'month', 'vs last month'),
    change(props.since, 'since', props.since ? `since ${monthLabel(props.since.date)}` : ''),
  ].filter(Boolean),
)

const unrealisedPct = computed(() => percent(props.current.unrealised, props.current.cost))

const gain = broker => broker.unrealised_base ?? broker.unrealised

// What net worth is made of, in the chart's colours. The bar is drawn from the parts that
// hold it up, and a debt is not one of them: it is not in the total above, so a segment of it
// in the bar would be a share of something it is not part of. What the cards owe is the foot
// of the cash card instead, where it is a total rather than a component.
const parts = computed(() =>
  [
    {
      key: 'cash',
      label: 'Cash',
      value: props.current.cash,
      colour: '#059669',
      class: 'text-positive',
    },
    {
      key: 'value',
      label: 'Stocks',
      value: props.current.value,
      colour: '#2563eb',
      class: 'text-primary',
    },
  ]
    .filter(part => !isZero(part.value))
    .map(part => ({ ...part, gross: Math.max(Number(part.value), 0) })),
)

const dayFormat = new Intl.DateTimeFormat('en', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
})

const dayLabel = day => {
  const [year, month, date] = day.split('-').map(Number)

  return dayFormat.format(new Date(Date.UTC(year, month - 1, date)))
}

const monthFormat = new Intl.DateTimeFormat('en', {
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
})

const monthLabel = day => {
  const [year, month] = day.split('-').map(Number)

  return monthFormat.format(new Date(Date.UTC(year, month - 1, 1)))
}
</script>
