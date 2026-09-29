<template>
  <div class="column no-wrap q-gutter-lg">
    <!-- The net worth page's cards for today, each a way into it. -->
    <div>
      <div class="app-home-headline">
        <q-card
          v-for="figure in headlineFigures"
          :key="figure.label"
          flat
          bordered
          class="app-home-link app-home-headline__card column no-wrap"
          @click="go('/net-worth')"
        >
          <q-card-section class="q-pb-none">
            <div class="text-caption text-grey-7">{{ figure.label }}</div>
            <div class="text-h5 text-weight-bold money" :class="figure.class">
              {{ base }} {{ money(figure.value) }}
            </div>
            <div class="text-caption q-mt-xs" :class="figure.noteClass">
              {{ figure.note ?? trendCaption }}
            </div>
          </q-card-section>
          <!--
            Deferred, so the line lands after the figures it sits under. The fallback is
            the height of a drawn line: anything shorter and the cards jump as it arrives.
          -->
          <Deferred data="trend" :rescue="trendRescue">
            <template #fallback>
              <div class="app-home-spark-placeholder" />
            </template>

            <HomeSpark
              v-if="trend.length > 1"
              :values="trend.map(point => point[figure.key])"
              :colour="figure.colour"
              :label="`${figure.label}, ${trendCaption}`"
            />
          </Deferred>
        </q-card>
      </div>
      <div v-if="headline.unconverted.length" class="text-caption text-grey-7 q-mt-sm">
        {{ headline.unconverted.join(', ') }} left out: no rate to {{ base }} yet.
      </div>
    </div>

    <!-- A plain div: a q-col-gutter row directly inside a q-gutter column misaligns. -->
    <div>
      <div class="row q-col-gutter-md">
        <!-- Spaced by a margin: q-gutter on a col shifts it, and the col beside it with it. -->
        <div class="col-12 col-md-7">
          <q-card flat bordered class="q-mb-md">
            <q-card-section class="row items-center q-pb-sm">
              <q-icon name="notifications_active" size="sm" color="grey-6" class="q-mr-sm" />
              <div class="text-subtitle1 text-weight-medium">Needs attention</div>
              <q-badge
                v-if="attention.length"
                class="app-tint app-tint--negative q-ml-sm"
                :label="attention.length"
              />
            </q-card-section>
            <q-list v-if="attention.length" separator>
              <q-item
                v-for="item in attention"
                :key="item.message"
                clickable
                dense
                class="q-py-sm"
                @click="go(item.link.path, item.link.data)"
              >
                <q-item-section avatar class="app-home-attention__icon">
                  <q-icon
                    :name="item.icon"
                    size="xs"
                    :color="item.level === 'negative' ? 'negative' : 'warning'"
                  />
                </q-item-section>
                <q-item-section class="text-body2 text-grey-9">{{ item.message }}</q-item-section>
                <q-item-section side>
                  <q-icon name="chevron_right" size="xs" color="grey-5" />
                </q-item-section>
              </q-item>
            </q-list>
            <q-card-section v-else class="row items-center text-positive q-pt-none">
              <q-icon name="check_circle_outline" size="xs" class="q-mr-sm" />
              All clear: nothing overdue, nothing below zero.
            </q-card-section>
          </q-card>

          <q-card flat bordered>
            <q-card-section class="row items-center q-pb-sm">
              <q-icon name="upcoming" size="sm" color="grey-6" class="q-mr-sm" />
              <div class="text-subtitle1 text-weight-medium">Coming up</div>
              <div class="text-caption text-grey-7 q-ml-sm">next {{ upcomingDays }} days</div>
            </q-card-section>
            <q-markup-table v-if="upcoming.length" flat dense>
              <tbody>
                <tr
                  v-for="(event, i) in upcoming"
                  :key="i"
                  class="cursor-pointer"
                  @click="openEvent(event)"
                >
                  <td class="text-grey-7" style="width: 96px">{{ formatDate(event.date) }}</td>
                  <!-- Wrapping: Quasar's cells do not, and a phone would push the amount off. -->
                  <td style="white-space: normal">
                    <div>{{ event.description }}</div>
                    <div class="text-caption text-grey-6">{{ event.account }}</div>
                  </td>
                  <td class="text-right money text-weight-medium" :class="signClass(event.amount)">
                    {{ signed(event.amount) }}
                    <span class="text-caption text-grey-7">{{ event.ccy }}</span>
                  </td>
                </tr>
              </tbody>
            </q-markup-table>
            <q-card-section v-else class="text-grey-6 q-pt-none">
              Nothing known in the next {{ upcomingDays }} days.
            </q-card-section>
            <q-card-section v-if="upcomingMore" class="q-pt-sm">
              <q-btn
                flat
                dense
                no-caps
                color="primary"
                class="text-caption"
                icon-right="chevron_right"
                :label="`${upcomingMore} more in the forecast`"
                @click="go('/forecast')"
              />
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-md-5">
          <q-card
            v-if="month"
            flat
            bordered
            class="full-height app-home-link"
            @click="go('/cash-flow')"
          >
            <q-card-section class="row items-center q-pb-sm">
              <q-icon name="insights" size="sm" color="grey-6" class="q-mr-sm" />
              <div class="text-subtitle1 text-weight-medium">
                {{ monthName(month.month) }} so far
              </div>
              <q-space />
              <div class="text-caption text-grey-7">
                {{ month.days_left }} day{{ month.days_left === 1 ? '' : 's' }} left
              </div>
            </q-card-section>

            <q-card-section class="q-pt-none">
              <div class="row q-col-gutter-md">
                <div class="col-6">
                  <div class="text-caption text-grey-7">Income</div>
                  <div class="text-h6 text-weight-bold money text-positive">
                    {{ money(month.so_far.income) }}
                  </div>
                </div>
                <div class="col-6">
                  <div class="text-caption text-grey-7">Spending</div>
                  <div class="text-h6 text-weight-bold money text-negative">
                    {{ money(month.so_far.spending) }}
                  </div>
                </div>
              </div>
            </q-card-section>

            <q-card-section v-for="bar in monthBars" :key="bar.label" class="q-pt-none">
              <div class="row text-caption text-grey-7 q-mb-xs">
                {{ bar.label }}
                <q-space />
                <span class="money">{{ money(bar.done) }} of {{ money(bar.expected) }}</span>
              </div>
              <q-linear-progress
                :value="bar.share"
                :color="bar.colour"
                track-color="grey-3"
                rounded
                size="8px"
              />
            </q-card-section>

            <q-separator inset />

            <q-card-section>
              <div class="row items-center">
                <div class="text-body2 text-grey-8">Likely month end</div>
                <q-space />
                <div
                  class="text-subtitle1 text-weight-bold money"
                  :class="signClass(month.likely_net)"
                >
                  {{ signed(month.likely_net) }}
                </div>
              </div>
              <div class="row items-center text-caption text-grey-7">
                Monthly average, last 12 months
                <q-space />
                <span class="money">{{ signed(month.average_net) }}</span>
              </div>
              <div class="text-caption text-grey-6 q-mt-sm">
                In {{ base }} at today's rate. Expected is so far, plus what is known still to come,
                plus typical spending for the days left.
              </div>
            </q-card-section>
          </q-card>
        </div>
      </div>
    </div>

    <HomeSection
      title="Cash accounts"
      :total="headline.cash"
      :base="base"
      :items="cashItems"
      empty="No cash account yet."
    />

    <HomeSection
      v-if="brokerages.length"
      title="Brokerages"
      :total="headline.value"
      :base="base"
      :items="brokerItems"
    />

    <HomeSection
      title="Card statements owing"
      :total="headline.owed"
      :base="base"
      :items="statementItems"
      negative
      empty="Nothing owed on any card."
    />
  </div>
</template>

<script setup>
import { Deferred, router } from '@inertiajs/vue3'

const props = defineProps({
  cash: { type: Array, default: Array },
  brokerages: { type: Array, default: Array },
  statements: { type: Array, default: Array },
  base: { type: String, default: 'HKD' },
  headline: { type: Object, default: () => ({ unconverted: [] }) },
  trend: { type: Array, default: Array },
  attention: { type: Array, default: Array },
  month: { type: Object, default: null },
  upcoming: { type: Array, default: Array },
  upcomingMore: { type: Number, default: 0 },
  upcomingDays: { type: Number, default: 14 },
})

const money = useMoney()
const dueBadge = useDueBadge()
const formatDate = useCalendarDay()

// The trend is deferred, so it is empty until it arrives. The caption counts the points
// it has, which read "last -1 months" before any have; a fixed wording waits instead.
const TREND_MONTHS = 6
const trendCaption = computed(() =>
  props.trend.length > 1 ? `last ${props.trend.length - 1} months` : `last ${TREND_MONTHS} months`,
)

const trendRescue = () =>
  h('div', { class: 'text-caption text-grey-7 q-mt-xs' }, [
    'Trend unavailable.',
    h(
      'a',
      {
        href: '#',
        class: 'q-ml-xs',
        onClick: e => {
          e.preventDefault()
          router.reload({ only: ['trend'] })
        },
      },
      'Retry',
    ),
  ])

const count = (n, noun) => `${n} ${noun}${n === 1 ? '' : 's'}`

const negative = value => String(value).startsWith('-')
const isZero = value => !/[1-9]/.test(String(value))

const signed = value => (negative(value) ? money(value) : `+${money(value)}`)

const signClass = value =>
  negative(value) ? 'text-negative' : isZero(value) ? '' : 'text-positive'

// A share for reading, not money: rounded to one place.
const percent = (part, whole) =>
  Number(whole) ? `${((Number(part) / Math.abs(Number(whole))) * 100).toFixed(1)}%` : ''

// The net worth chart's colours, so a line here is the same line there.
const colours = { net_worth: '#475569', cash: '#059669', cards: '#e11d48', value: '#2563eb' }

const headlineFigures = computed(() => {
  const h = props.headline

  // The month-on-month note every card carries, from the figure's own key. An empty
  // percentage -- last month's figure was zero, so there is no share to give -- leaves the
  // change on its own rather than printing a percentage of nothing.
  const onLastMonth = key => {
    const change = h.change[key]
    const pct = percent(change, h.last_month[key])

    return {
      note: `${signed(change)}${pct ? ` (${pct})` : ''} on last month`,
      noteClass: signClass(change),
    }
  }

  return [
    {
      label: 'Net worth',
      key: 'net_worth',
      colour: colours.net_worth,
      value: h.net_worth,
      class: 'text-grey-9',
      ...onLastMonth('net_worth'),
    },
    {
      label: 'Cash',
      key: 'cash',
      colour: colours.cash,
      value: h.cash,
      class: negative(h.cash) ? 'text-negative' : 'text-positive',
      ...onLastMonth('cash'),
    },
    {
      label: 'Cards owe',
      key: 'cards',
      colour: colours.cards,
      value: h.cards,
      class: isZero(h.cards) ? 'text-grey-9' : 'text-negative',
      ...onLastMonth('cards'),
    },
    {
      label: 'Stocks',
      key: 'value',
      colour: colours.value,
      value: h.value,
      class: 'text-primary',
      // The same note as the others. The unrealised gain wants a line of its own rather
      // than a share of this one, and the grid below already carries it per brokerage.
      ...onLastMonth('value'),
    },
  ]
})

const monthFormat = new Intl.DateTimeFormat('en', { month: 'long', timeZone: 'UTC' })

const monthName = month => {
  const [year, number] = month.split('-').map(Number)

  return monthFormat.format(new Date(Date.UTC(year, number - 1, 1)))
}

// Numbers only for the bar's length: the figures beside it are the server's strings.
const monthBars = computed(() => {
  const m = props.month

  if (!m) return []

  const bar = (label, done, expected, colour) => ({
    label,
    done,
    expected,
    colour,
    share: Number(expected) > 0 ? Math.min(Number(done) / Number(expected), 1) : 0,
  })

  const expectedIncome = (Number(m.so_far.income) + Number(m.to_come.income)).toFixed(4)
  const expectedSpending = (
    Number(m.so_far.spending) +
    Number(m.to_come.spending) +
    Number(m.typical_rest)
  ).toFixed(4)

  return [
    bar('Income received', m.so_far.income, expectedIncome, 'positive'),
    bar('Spent of expected', m.so_far.spending, expectedSpending, 'negative'),
  ]
})

// In the script: the template cannot see the auto-imported router, so a click calling it
// there throws in the handler and goes nowhere.
const go = (path, data) => router.visit(path, data ? { data } : {})

// As the forecast page opens the same events.
const openEvent = event => {
  if (event.link.recurring) return router.visit('/recurring')

  if (event.link.card) {
    return router.visit('/transactions', {
      data: { filter: { account_id: event.link.card, due_date: event.link.due_date } },
    })
  }

  return router.visit('/transactions', {
    data: { filter: { account_id: event.account_id, description: event.description } },
  })
}

// The Positions page remembers its brokerage per browser, so picking it is a stored id.
const openBroker = id => {
  try {
    localStorage.setItem('positions.broker', String(id))
  } catch {
    // Private mode: Positions opens on All instead.
  }

  router.visit('/positions')
}

const cashItems = computed(() =>
  props.cash.map(account => ({
    key: account.id,
    icon: 'account_balance',
    name: account.name,
    ccy: account.ccy,
    value: account.balance,
    valueClass: negative(account.balance) ? 'text-negative' : 'text-grey-9',
    empty: isZero(account.balance),
    lines: account.status !== 'active' ? [{ text: `${account.status}, still holding money` }] : [],
    open: () => router.visit('/transactions', { data: { filter: { account_id: account.id } } }),
  })),
)

const brokerItems = computed(() =>
  props.brokerages.map(broker => {
    // Against the cost of the holdings that are priced, not the open cost: those are two
    // different sets, and only the priced one is what the unrealised figure describes.
    const pct = percent(broker.unrealised, broker.priced_cost)

    return {
      key: broker.id,
      icon: 'show_chart',
      name: broker.name,
      ccy: broker.ccy,
      value: broker.market_value,
      valueClass: 'text-grey-9',
      empty: broker.open === 0 && isZero(broker.market_value),
      lines: [
        {
          text: `${signed(broker.unrealised)} unrealised${pct ? ` (${pct})` : ''}`,
          class: signClass(broker.unrealised),
        },
        {
          text: `${count(broker.open, 'holding')} · cost ${money(broker.open_cost)}${
            broker.unpriced ? ` · ${broker.unpriced} unpriced` : ''
          }`,
        },
      ],
      open: () => openBroker(broker.id),
    }
  }),
)

const statementItems = computed(() =>
  props.statements.map(statement => ({
    key: `${statement.card.id}-${statement.due_date}`,
    icon: 'credit_card',
    name: statement.card.name,
    ccy: statement.card.ccy,
    value: statement.owed,
    valueClass: 'text-negative',
    overdue: statement.days_until_due < 0,
    badge: { ...dueBadge(statement), prefix: `Due ${formatDate(statement.due_date)}` },
    lines: [
      {
        text: `${count(statement.charge_count, 'charge')}${
          statement.payment_count ? ` · ${money(statement.paid)} paid` : ''
        }${statement.pending_count ? ` · ${statement.pending_count} not yet posted` : ''}`,
      },
    ],
    open: () =>
      router.visit('/transactions', {
        data: { filter: { account_id: statement.card.id, due_date: statement.due_date } },
      }),
  })),
)
</script>
