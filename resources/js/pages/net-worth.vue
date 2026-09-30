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
            Cash, less what the cards owe, plus the stocks at their last close, in {{ base }}.
            Pending rows are left out. Click a point on the chart to see that day.
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
            <div class="row items-center q-gutter-sm q-mt-xs">
              <q-badge
                v-if="monthChange"
                :class="
                  monthChange.up ? 'app-tint app-tint--positive' : 'app-tint app-tint--negative'
                "
                :label="`${monthChange.up ? '+' : ''}${money(monthChange.amount)} on last month${monthChange.pct}`"
              />
              <span v-if="sinceGrowth" class="text-caption text-grey-7">
                {{ sinceGrowth.up ? '+' : '' }}{{ money(sinceGrowth.amount)
                }}{{ sinceGrowth.pct }} since {{ monthLabel(since.date) }}
              </span>
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

    <!-- Wrapped: the column's gutter margin would undo the row's own negative one. -->
    <div>
      <div class="row q-col-gutter-md">
        <div class="col-12 col-md-5">
          <q-card flat bordered class="full-height">
            <q-card-section class="row items-center q-pb-sm">
              <q-icon name="account_balance" size="sm" color="grey-7" class="q-mr-sm" />
              <div class="text-subtitle1 text-weight-medium">Cash</div>
              <q-space />
              <div class="money text-weight-bold text-positive">{{ figure(current.cash) }}</div>
            </q-card-section>
            <q-separator />
            <div v-for="account in accountsOf('cash')" :key="account.id" class="app-worth-row">
              <span class="text-grey-9 ellipsis">{{ account.name }}</span>
              <div class="text-right">
                <div class="money text-weight-medium">
                  {{ money(account.base ?? account.balance) }}
                </div>
                <!-- In the base currency first, as every figure here is; its own money under it. -->
                <div v-if="account.ccy !== base" class="text-caption text-grey-6 money">
                  {{ account.ccy }} {{ money(account.balance) }}
                </div>
              </div>
            </div>
            <div v-if="!isZero(current.cards)" class="app-worth-row app-worth-row--total">
              <span class="text-grey-8">Cards owe</span>
              <span class="money text-weight-medium text-negative">{{ money(current.cards) }}</span>
            </div>
          </q-card>
        </div>

        <div class="col-12 col-md-7">
          <q-card flat bordered class="full-height">
            <q-card-section class="row items-center q-pb-sm">
              <q-icon name="show_chart" size="sm" color="grey-7" class="q-mr-sm" />
              <div class="text-subtitle1 text-weight-medium">Stocks</div>
              <q-space />
              <q-badge
                v-if="unrealisedPct"
                class="q-mr-sm"
                :class="
                  up(current.unrealised)
                    ? 'app-tint app-tint--positive'
                    : 'app-tint app-tint--negative'
                "
                :label="unrealisedPct"
              />
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
            <div v-for="broker in current.brokerages" :key="broker.id" class="app-worth-stocks">
              <span class="text-grey-9 ellipsis">{{ broker.name }}</span>
              <div class="text-right money">
                <div class="text-weight-medium">{{ money(broker.value_base ?? broker.value) }}</div>
                <div v-if="broker.ccy !== base" class="text-caption text-grey-6">
                  {{ broker.ccy }} {{ money(broker.value) }}
                </div>
              </div>
              <div class="text-right money text-grey-8">
                <div>{{ money(broker.cost_base ?? broker.cost) }}</div>
                <div v-if="broker.ccy !== base" class="text-caption text-grey-6">
                  {{ broker.ccy }} {{ money(broker.cost) }}
                </div>
              </div>
              <div class="text-right money" :class="signClass(gain(broker))">
                <div class="text-weight-medium">{{ money(gain(broker)) }}</div>
                <div class="text-caption">
                  {{ percent(gain(broker), broker.cost_base ?? broker.cost) }}
                </div>
              </div>
            </div>
            <div class="app-worth-stocks app-worth-stocks--total">
              <span class="text-grey-8">All brokerages</span>
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
  </div>
</template>

<script setup>
const props = defineProps({
  base: { type: String, default: 'HKD' },
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

// Shares and percentages are for reading, not money, so floats are fine here.
const percent = (part, whole) =>
  Number(whole) === 0 ? null : `${((Number(part) / Math.abs(Number(whole))) * 100).toFixed(1)}%`

const share = part => percent(part, props.current.net_worth) ?? '—'

// The server's difference; only the percentage is worked out here.
const change = against => {
  if (!against) return null

  const pct = percent(against.change, against.net_worth)

  return { amount: against.change, up: up(against.change), pct: pct ? ` (${pct})` : '' }
}

const monthChange = computed(() => change(props.lastMonth))
const sinceGrowth = computed(() => change(props.since))

const unrealisedPct = computed(() => percent(props.current.unrealised, props.current.cost))

const gain = broker => broker.unrealised_base ?? broker.unrealised

// The parts of net worth with the chart's colours; the bar is drawn from the parts that
// hold it up, since a debt has no width to give, and every part is named with its share.
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
    {
      key: 'cards',
      label: 'Cards owe',
      value: props.current.cards,
      colour: '#e11d48',
      class: 'text-negative',
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
