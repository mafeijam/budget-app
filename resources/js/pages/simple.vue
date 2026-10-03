<template>
  <div class="column no-wrap q-gutter-md">
    <q-card flat bordered class="overflow-hidden">
      <!-- The heading, and since last month's end as the home page's card has it. -->
      <q-card-section class="app-card-head">
        Net worth
        <q-badge
          class="app-change"
          :class="change.up ? 'app-tint app-tint--positive' : 'app-tint app-tint--negative'"
        >
          <q-icon :name="change.up ? 'trending_up' : 'trending_down'" size="14px" />
          <span class="money">{{ signed(change.amount) }}</span>
          <span v-if="change.pct" class="app-change__pct">{{ change.pct }}</span>
        </q-badge>
      </q-card-section>
      <q-card-section class="q-pt-sm q-pb-sm">
        <div class="app-simple__worth text-weight-bold text-grey-9 money">
          {{ base }} {{ money(headline.net_worth) }}
        </div>
        <!-- Each as wide as its figure, spread to both edges, the last flush right: in equal
             thirds a long figure ran into the next, and the last stopped short of the edge. -->
        <div class="row no-wrap justify-between q-mt-sm">
          <div
            v-for="(part, i) in parts"
            :key="part.label"
            :class="{ 'text-right': i === parts.length - 1 }"
          >
            <div class="text-caption text-grey-7">{{ part.label }}</div>
            <div class="app-simple__part text-weight-medium money text-no-wrap" :class="part.class">
              {{ money(part.value) }}
            </div>
          </div>
        </div>
        <div v-if="headline.unconverted?.length" class="text-caption text-grey-6 q-mt-sm">
          {{ headline.unconverted.join(', ') }} left out: no rate to {{ base }} yet.
        </div>
      </q-card-section>
      <!-- Deferred, so it lands under figures already on screen; the fallback holds its
           height so the cards below do not move when it does. -->
      <Deferred data="trend">
        <template #fallback>
          <div class="app-home-spark-placeholder" />
        </template>
        <HomeSpark
          v-if="trend.length > 1"
          :values="trend.map(point => point.net_worth)"
          colour="#475569"
          :label="`Net worth, last ${trend.length - 1} months`"
        />
      </Deferred>
    </q-card>

    <q-card v-if="attention.length" flat bordered>
      <q-card-section class="app-card-head"> Needs attention </q-card-section>
      <q-list>
        <q-item v-for="item in attention" :key="item.message" dense class="q-py-sm">
          <q-item-section avatar top style="min-width: 32px">
            <q-icon :name="item.icon" color="warning" size="xs" />
          </q-item-section>
          <q-item-section top>
            <q-item-label class="text-weight-medium text-grey-9">{{ item.title }}</q-item-label>
            <q-item-label caption>{{ item.detail }}</q-item-label>
          </q-item-section>
          <q-item-section side top class="text-right">
            <q-item-label class="text-weight-bold money" :class="signClass(item.amount)">
              {{ item.ccy === base ? '' : `${item.ccy} ` }}{{ signed(item.amount) }}
            </q-item-label>
            <q-item-label caption>{{ item.when }}</q-item-label>
          </q-item-section>
        </q-item>
      </q-list>
    </q-card>

    <HomeMonths compact :month="month" :next-month="nextMonth" :base="base" />

    <q-card flat bordered>
      <q-card-section class="app-card-head">
        Cash
        <q-badge
          class="app-change"
          :class="cashChange.up ? 'app-tint app-tint--positive' : 'app-tint app-tint--negative'"
        >
          <q-icon :name="cashChange.up ? 'trending_up' : 'trending_down'" size="14px" />
          <span class="money">{{ signed(cashChange.amount) }}</span>
          <span v-if="cashChange.pct" class="app-change__pct">{{ cashChange.pct }}</span>
        </q-badge>
      </q-card-section>
      <q-list>
        <q-item v-for="account in held" :key="account.id" dense class="q-py-sm">
          <q-item-section top>
            <q-item-label class="text-weight-medium text-grey-9">{{ account.name }}</q-item-label>
            <q-item-label v-if="account.status !== 'active'" caption>
              {{ account.status }}, still holding money
            </q-item-label>
          </q-item-section>
          <q-item-section side top class="text-right">
            <q-item-label
              class="text-weight-bold money"
              :class="negative(account.balance) ? 'text-negative' : 'text-grey-9'"
            >
              {{ account.ccy === base ? '' : `${account.ccy} ` }}{{ money(account.balance) }}
            </q-item-label>
            <q-item-label v-if="account.base" caption class="money">
              {{ base }} {{ money(account.base) }}
            </q-item-label>
          </q-item-section>
        </q-item>
      </q-list>
      <q-card-section v-if="!held.length" class="q-pt-none text-grey-6">
        No cash account yet.
      </q-card-section>
      <q-card-section v-if="emptyCount" class="q-pt-none text-caption text-grey-6">
        +{{ emptyCount }} empty
      </q-card-section>
    </q-card>

    <q-card v-if="brokerages.length" flat bordered>
      <q-card-section class="app-card-head">
        Stocks
        <q-badge
          class="app-change"
          :class="stocks.up ? 'app-tint app-tint--positive' : 'app-tint app-tint--negative'"
        >
          <q-icon :name="stocks.up ? 'trending_up' : 'trending_down'" size="14px" />
          <span class="money">{{ signed(stocks.amount) }}</span>
          <span v-if="stocks.pct" class="app-change__pct">{{ stocks.pct }}</span>
        </q-badge>
      </q-card-section>
      <q-list>
        <q-item v-for="broker in brokerages" :key="broker.id" dense class="q-py-sm">
          <q-item-section top>
            <q-item-label class="text-weight-medium text-grey-9">{{ broker.name }}</q-item-label>
            <q-item-label caption :class="signClass(broker.unrealised)" class="money">
              {{ signed(broker.unrealised) }}
            </q-item-label>
          </q-item-section>
          <q-item-section side top class="text-right">
            <q-item-label class="text-weight-bold text-grey-9 money">
              {{ broker.ccy === base ? '' : `${broker.ccy} ` }}{{ money(broker.market_value) }}
            </q-item-label>
            <q-item-label v-if="broker.market_value_base" caption class="money">
              {{ base }} {{ money(broker.market_value_base) }}
            </q-item-label>
          </q-item-section>
        </q-item>
      </q-list>
    </q-card>

    <q-card flat bordered>
      <q-card-section class="app-card-head">
        Cards owe
        <q-badge
          class="app-change"
          :class="cardsChange.up ? 'app-tint app-tint--positive' : 'app-tint app-tint--negative'"
        >
          <q-icon :name="cardsChange.up ? 'trending_up' : 'trending_down'" size="14px" />
          <span class="money">{{ signed(cardsChange.amount) }}</span>
          <span v-if="cardsChange.pct" class="app-change__pct">{{ cardsChange.pct }}</span>
        </q-badge>
      </q-card-section>
      <q-list>
        <q-item v-for="card in owing" :key="card.id" dense class="q-py-sm">
          <q-item-section top>
            <q-item-label class="text-weight-medium text-grey-9">{{ card.name }}</q-item-label>
            <q-item-label caption :class="{ 'text-negative': card.daysUntilDue < 0 }">
              {{ dueText(card) }}
            </q-item-label>
          </q-item-section>
          <q-item-section side top class="text-right">
            <q-item-label class="text-weight-bold text-negative money">
              {{ card.ccy === base ? '' : `${card.ccy} ` }}{{ money(card.owed) }}
            </q-item-label>
            <q-item-label v-if="card.pending" caption>
              {{ card.pending }} not yet posted
            </q-item-label>
          </q-item-section>
        </q-item>
      </q-list>
      <q-card-section v-if="!owing.length" class="q-pt-none text-grey-6">
        Nothing owed on any card.
      </q-card-section>
    </q-card>
  </div>
</template>

<script setup>
import { Deferred } from '@inertiajs/vue3'
import simple from '../layout-simple.vue'

defineOptions({ layout: simple })

const props = defineProps({
  cash: { type: Array, default: Array },
  statements: { type: Array, default: Array },
  brokerages: { type: Array, default: Array },
  base: { type: String, default: 'HKD' },
  headline: { type: Object, default: () => ({ unconverted: [] }) },
  // Month ends and today, deferred, for the line under net worth.
  trend: { type: Array, default: Array },
  // The yearly recurring bills due soon, as the home page's Needs attention words them.
  attention: { type: Array, default: Array },
  month: { type: Object, default: null },
  nextMonth: { type: Object, default: null },
})

const money = useMoney()

const negative = value => String(value).startsWith('-')
const isZero = value => !/[1-9]/.test(String(value))
const signed = value => (negative(value) ? money(value) : `+${money(value)}`)
const signClass = value =>
  negative(value) ? 'text-negative' : isZero(value) ? 'text-grey-6' : 'text-positive'

// A share for reading, not money: one place, and `|| 0` so a small loss reads 0.0% not -0.0%.
const percent = (part, whole) =>
  Number(whole)
    ? `${(Number(((Number(part) / Math.abs(Number(whole))) * 100).toFixed(1)) || 0).toFixed(1)}%`
    : ''

const changeFor = key => {
  const amount = props.headline.change?.[key] ?? '0'

  return {
    amount,
    up: !negative(amount),
    pct: percent(amount, props.headline.last_month?.[key]),
  }
}

const change = computed(() => changeFor('net_worth'))

// Each box's month-on-month note, as the home page's cards carry it.
const cashChange = computed(() => changeFor('cash'))
const cardsChange = computed(() => changeFor('cards'))
const stocks = computed(() => changeFor('value'))

const parts = computed(() => [
  {
    label: 'Cash',
    value: props.headline.cash,
    class: negative(props.headline.cash) ? 'text-negative' : 'text-positive',
  },
  { label: 'Stocks', value: props.headline.value, class: 'text-primary' },
  {
    label: 'Cards owe',
    value: props.headline.cards,
    class: isZero(props.headline.cards) ? 'text-grey-9' : 'text-negative',
  },
])

// Held in HKD first, then USD, then the rest by code, and largest first within each, on what
// it is worth in the base currency. An empty account is counted rather than listed.
const leading = ['HKD', 'USD']
const rank = ccy => (leading.includes(ccy) ? leading.indexOf(ccy) : leading.length)

const held = computed(() =>
  props.cash
    .filter(account => !isZero(account.balance))
    .map(account => ({ ...account, total: account.base ?? account.balance }))
    .sort(
      (a, b) => rank(a.ccy) - rank(b.ccy) || a.ccy.localeCompare(b.ccy) || byAmountDescending(a, b),
    ),
)

const emptyCount = computed(() => props.cash.length - held.value.length)

// One row a card, whatever number of its statements are open, as on the home page: the
// statements arrive soonest due first, so a card's first is the one due next.
const owing = computed(() => {
  const cards = new Map()

  for (const statement of props.statements) {
    const card = cards.get(statement.card.id)

    if (card) {
      card.owed = plus(card.owed, statement.owed)
      card.pending += statement.pending_count ?? 0
      continue
    }

    cards.set(statement.card.id, {
      ...statement.card,
      owed: statement.owed,
      pending: statement.pending_count ?? 0,
      dueDate: statement.due_date,
      daysUntilDue: statement.days_until_due,
    })
  }

  return [...cards.values()]
})

const formatDay = useCalendarDay()

const dueText = card => {
  const days = card.daysUntilDue

  if (days < 0) return `Overdue since ${formatDay(card.dueDate)}`
  if (days === 0) return 'Due today'

  return `Due ${formatDay(card.dueDate)}, in ${days} ${days === 1 ? 'day' : 'days'}`
}
</script>
