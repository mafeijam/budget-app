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

    <!-- Wrapped: the column's gutter margin would undo the row's own negative one. -->
    <div>
      <div class="row q-col-gutter-md">
        <div class="col-12 col-sm-6 col-md">
          <q-card flat bordered class="full-height">
            <q-card-section>
              <div class="text-caption text-grey-7">Net worth</div>
              <div class="text-h5 text-weight-bold money">{{ figure(current.net_worth) }}</div>
              <q-badge
                v-if="monthChange"
                class="q-mt-sm"
                :class="
                  monthChange.up ? 'app-tint app-tint--positive' : 'app-tint app-tint--negative'
                "
                :label="`${monthChange.up ? '+' : ''}${money(monthChange.amount)} on last month${monthChange.pct}`"
              />
              <div v-if="sinceGrowth" class="text-caption text-grey-7 q-mt-sm">
                {{ sinceGrowth.up ? '+' : '' }}{{ money(sinceGrowth.amount)
                }}{{ sinceGrowth.pct }} since
                {{ monthLabel(since.date) }}
              </div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-sm-6 col-md">
          <q-card flat bordered class="full-height">
            <q-card-section>
              <div class="text-caption text-grey-7">Cash</div>
              <div class="text-h5 text-weight-bold text-positive money">
                {{ figure(current.cash) }}
              </div>
              <q-badge
                class="q-mt-sm app-tint app-tint--positive"
                :label="`${share(current.cash)} of net worth`"
              />
              <div class="q-mt-sm">
                <div
                  v-for="account in accountsOf('cash')"
                  :key="account.id"
                  class="row no-wrap text-caption"
                >
                  <span class="text-grey-7 ellipsis q-mr-sm">{{ account.name }}</span>
                  <q-space />
                  <span class="money">{{ money(account.base ?? account.balance) }}</span>
                </div>
                <div v-if="!isZero(current.cards)" class="row no-wrap text-caption q-mt-xs">
                  <span class="text-grey-7">Cards owe</span>
                  <q-space />
                  <span class="money text-negative">{{ money(current.cards) }}</span>
                </div>
              </div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-sm-6 col-md">
          <q-card flat bordered class="full-height">
            <q-card-section>
              <div class="text-caption text-grey-7">Stock market value</div>
              <div class="text-h5 text-weight-bold text-primary money">
                {{ figure(current.value) }}
              </div>
              <q-badge
                class="q-mt-sm app-tint app-tint--info"
                :label="`${share(current.value)} of net worth`"
              />
              <div class="q-mt-sm">
                <div
                  v-for="broker in current.brokerages"
                  :key="broker.id"
                  class="row no-wrap text-caption"
                >
                  <span class="text-grey-7 ellipsis q-mr-sm">{{ broker.name }}</span>
                  <q-space />
                  <span class="money">
                    {{ money(broker.value_base ?? broker.value) }}
                    <q-tooltip v-if="broker.ccy !== base" :delay="300" :offset="[0, 6]">
                      {{ money(broker.value) }} {{ broker.ccy }}
                    </q-tooltip>
                  </span>
                </div>
              </div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-sm-6 col-md">
          <q-card flat bordered class="full-height">
            <q-card-section>
              <div class="text-caption text-grey-7">Stock cost</div>
              <div class="text-h5 text-weight-bold money">{{ figure(current.cost) }}</div>
              <div class="q-mt-sm">
                <div
                  v-for="broker in current.brokerages"
                  :key="broker.id"
                  class="row no-wrap text-caption"
                >
                  <span class="text-grey-7 ellipsis q-mr-sm">{{ broker.name }}</span>
                  <q-space />
                  <span class="money">{{ money(broker.cost_base ?? broker.cost) }}</span>
                </div>
              </div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-sm-6 col-md">
          <q-card flat bordered class="full-height">
            <q-card-section>
              <div class="row items-center">
                <div class="text-caption text-grey-7">Unrealised P&amp;L</div>
                <q-space />
                <q-badge
                  v-if="unrealisedPct"
                  :class="
                    up(current.unrealised)
                      ? 'app-tint app-tint--positive'
                      : 'app-tint app-tint--negative'
                  "
                  :label="unrealisedPct"
                />
              </div>
              <div class="text-h5 text-weight-bold money" :class="signClass(current.unrealised)">
                {{ figure(current.unrealised, true) }}
              </div>
              <div class="q-mt-sm">
                <div
                  v-for="broker in current.brokerages"
                  :key="broker.id"
                  class="row no-wrap text-caption"
                >
                  <span class="text-grey-7 ellipsis q-mr-sm">{{ broker.name }}</span>
                  <q-space />
                  <span class="money" :class="signClass(gain(broker))">{{
                    money(gain(broker))
                  }}</span>
                </div>
              </div>
            </q-card-section>
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
            <template v-if="projecting">
              Ahead, dashed: the forecast's known cash, stocks at {{ growth }}% a year{{
                withTypical ? ', less typical spending' : ''
              }}.
            </template>
          </div>
        </div>
        <div class="col-auto row items-center q-gutter-md">
          <q-toggle
            :model-value="projecting"
            label="Project a year"
            color="primary"
            dense
            @update:model-value="on => visit({ project: on })"
          />
          <template v-if="projecting">
            <q-btn-toggle
              :model-value="growth"
              :options="growths.map(n => ({ label: `${n}%`, value: n }))"
              no-caps
              unelevated
              dense
              toggle-color="primary"
              color="grey-2"
              text-color="grey-8"
              padding="xs sm"
              @update:model-value="n => visit({ growth: n })"
            />
            <q-toggle v-model="withTypical" label="Typical spending" color="warning" dense />
          </template>
          <q-btn-toggle
            :model-value="months"
            :options="periods.map(n => ({ label: periodLabels[n] ?? `${n}M`, value: n }))"
            no-caps
            unelevated
            dense
            toggle-color="primary"
            color="grey-2"
            text-color="grey-8"
            padding="xs md"
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
          :projection="projection"
          :with-typical="withTypical"
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
  months: { type: Number, default: 1 },
  periods: { type: Array, default: () => [1, 3, 6, 12] },
  at: { type: String, default: null },
  projection: { type: Array, default: () => [] },
  growth: { type: Number, default: 0 },
  growths: { type: Array, default: () => [0, 5, 8] },
})

const projecting = computed(() => props.projection.length > 0)

// Shared with the forecast page, so the estimate is on or off in both.
const withTypical = useLocalStorage('forecast.typical', true)

// The spacing and the picked snapshot, each off the URL at its default.
const visit = ({
  months = props.months,
  at = props.at,
  project = projecting.value,
  growth = props.growth,
}) =>
  router.get(
    '/net-worth',
    {
      ...(months === 1 ? {} : { months }),
      ...(at ? { at } : {}),
      ...(project ? { project: 1 } : {}),
      ...(project && growth ? { growth } : {}),
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

// Monthly is the default, so it stays off the URL.
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
