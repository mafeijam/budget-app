<template>
  <div class="column no-wrap q-gutter-md">
    <q-card flat bordered>
      <q-card-section>
        <div class="text-caption text-grey-7">Net worth</div>
        <div class="text-h5 text-weight-bold text-grey-9 money">
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
    </q-card>

    <q-card v-if="attention.length" flat bordered>
      <q-card-section class="q-pb-xs text-subtitle2 text-weight-bold text-grey-9">
        Needs attention
      </q-card-section>
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

    <HomeMonths :month="month" :next-month="nextMonth" :base="base" />

    <q-card flat bordered>
      <q-card-section class="q-pb-xs text-subtitle2 text-weight-bold text-grey-9">
        Cash
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

    <q-card flat bordered>
      <q-card-section class="q-pb-xs text-subtitle2 text-weight-bold text-grey-9">
        Cards owe
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

    <q-card v-if="brokerages.length" flat bordered>
      <q-card-section class="q-pb-xs text-subtitle2 text-weight-bold text-grey-9">
        Stocks
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

    <div class="text-center q-pb-md">
      <q-btn flat no-caps color="grey-7" label="Full site" icon="desktop_windows" @click="full" />
    </div>
  </div>
</template>

<script setup>
import simple from '../layout-simple.vue'

defineOptions({ layout: simple })

const props = defineProps({
  cash: { type: Array, default: Array },
  statements: { type: Array, default: Array },
  brokerages: { type: Array, default: Array },
  base: { type: String, default: 'HKD' },
  headline: { type: Object, default: () => ({ unconverted: [] }) },
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

// Largest first on what each is worth in the base currency, as the home page orders them; an
// empty account is counted rather than listed.
const held = computed(() =>
  props.cash
    .filter(account => !isZero(account.balance))
    .map(account => ({ ...account, total: account.base ?? account.balance }))
    .sort(byAmountDescending),
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

const full = () => showHomeView('full')
</script>
