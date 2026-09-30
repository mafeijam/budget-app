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
          class="app-home-headline__card column no-wrap"
        >
          <!-- The figure and its line open the net worth page; a row below opens its own. -->
          <q-card-section class="q-pb-none app-home-link" @click="go('/net-worth')">
            <div class="text-subtitle1 text-weight-medium text-grey-8">{{ figure.label }}</div>
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

          <!-- What the figure is made of: the accounts, statements or brokerages behind it. -->
          <div v-if="figure.items.length" class="app-home-list">
            <div
              v-for="item in figure.items"
              :key="item.key"
              class="app-home-list__row"
              :class="{ 'app-home-list__row--overdue': item.overdue, 'cursor-pointer': item.open }"
              @click="item.open?.()"
            >
              <div class="app-home-list__name">
                <div class="row items-center no-wrap">
                  <span class="text-body2 text-weight-medium text-grey-9 ellipsis">
                    {{ item.name }}
                  </span>
                  <q-badge v-if="item.badge" v-bind="item.badge" class="q-ml-xs" />
                </div>
                <div
                  v-for="line in item.lines"
                  :key="line.text"
                  class="text-caption ellipsis"
                  :class="line.class ?? 'text-grey-6'"
                >
                  {{ line.text }}
                </div>
              </div>
              <div class="app-home-list__figures text-right">
                <!-- Held in another currency: that money beside the base figure the card sums,
                     on its line, so a foreign row is no taller than the rest -- or under it
                     when the row has a line of its own, whose name would lose the width. -->
                <div class="row items-baseline no-wrap justify-end">
                  <span
                    v-if="item.native && !item.lines?.length"
                    class="text-caption text-grey-6 money q-mr-sm"
                  >
                    {{ item.ccy }} {{ item.native }}
                  </span>
                  <span class="text-body2 text-weight-bold money" :class="item.valueClass">
                    {{ item.value }}
                  </span>
                </div>
                <div
                  v-if="item.native && item.lines?.length"
                  class="text-caption text-grey-6 money"
                >
                  {{ item.ccy }} {{ item.native }}
                </div>
                <!-- Only when it is not the base: then the figure is not the card's currency. -->
                <div
                  v-if="!item.native && item.ccy && item.ccy !== base"
                  class="text-caption text-grey-6"
                >
                  {{ item.ccy }}
                </div>
              </div>
            </div>
            <div v-if="figure.more" class="app-home-list__more text-caption text-grey-6">
              {{ figure.more }}
            </div>
          </div>
          <div
            v-else-if="figure.empty"
            class="app-home-list app-home-list__more text-caption text-grey-6"
          >
            {{ figure.empty }}
          </div>
        </q-card>
      </div>
      <div v-if="headline.unconverted.length" class="text-caption text-grey-7 q-mt-sm">
        {{ headline.unconverted.join(', ') }} left out: no rate to {{ base }} yet.
      </div>
    </div>

    <!-- This month and the next side by side, so one reads as the other's continuation. -->
    <div v-if="month || nextMonth">
      <div class="row q-col-gutter-md">
        <!-- The month the page opens in: what it has done so far, what is still to come,
             and where it is likely to end against an ordinary month. -->
        <div v-if="month" class="col-12 col-md-6">
          <q-card flat bordered class="app-home-link full-height" @click="go('/cash-flow')">
            <q-card-section class="row items-center no-wrap q-pb-sm">
              <q-icon name="insights" size="sm" color="grey-6" class="q-mr-sm" />
              <div>
                <div class="text-subtitle1 text-weight-medium">Current month</div>
                <div class="text-caption text-grey-7">{{ monthName(month.month) }} so far</div>
              </div>
              <q-space />
              <div class="app-home-month-days column items-end">
                <div class="text-caption text-grey-7">
                  Day {{ monthDays.done }} of {{ monthDays.total }}
                  <template v-if="month.days_left">
                    · {{ month.days_left }} day{{ month.days_left === 1 ? '' : 's' }} left
                  </template>
                </div>
                <div class="app-home-month-days__track">
                  <div :style="{ width: `${(monthDays.done / monthDays.total) * 100}%` }" />
                </div>
              </div>
            </q-card-section>

            <q-card-section class="q-pt-sm">
              <div class="app-outlook app-outlook--pairs">
                <div
                  v-for="tile in monthTiles"
                  :key="tile.label"
                  class="app-outlook__tile"
                  :class="{ 'app-outlook__tile--total': tile.total }"
                >
                  <div class="text-caption text-grey-7">{{ tile.label }}</div>
                  <div class="text-h6 text-weight-bold money" :class="tile.class">
                    {{ tile.value }}
                  </div>
                  <div class="text-caption money" :class="tile.noteClass ?? 'text-grey-6'">
                    {{ tile.note }}
                  </div>
                </div>
              </div>

              <!-- In and out on one scale: done so far solid, known still to come paler, and the
             typical spending of the days left palest, as an estimate. -->
              <div class="q-mt-md">
                <div
                  v-for="bar in monthFlow"
                  :key="bar.label"
                  class="app-outlook__bar row items-center no-wrap"
                >
                  <div class="app-outlook__bar-label text-caption text-grey-7">{{ bar.label }}</div>
                  <div class="app-outlook__track col">
                    <div
                      v-for="part in bar.parts"
                      :key="part.colour"
                      :style="{ width: `${part.width}%`, background: part.colour }"
                    />
                  </div>
                </div>
              </div>
              <div class="text-caption text-grey-6 q-mt-sm">
                In {{ base }} at today's rate. Solid is done, paler is known still to come, palest
                the typical spending of the days left.
              </div>
            </q-card-section>
          </q-card>
        </div>

        <!-- The month after, as the forecast has it: known in and out, the typical income
             and spending beside them, and where it is likely to end. -->
        <div v-if="nextMonth" class="col-12 col-md-6">
          <q-card flat bordered class="app-home-link full-height" @click="go('/forecast')">
            <q-card-section class="row items-center no-wrap q-pb-sm">
              <q-icon name="event_note" size="sm" color="grey-6" class="q-mr-sm" />
              <div>
                <div class="text-subtitle1 text-weight-medium">Next month</div>
                <div class="text-caption text-grey-7">{{ monthName(nextMonth.month) }} ahead</div>
              </div>
            </q-card-section>

            <q-card-section class="q-pt-sm">
              <div class="app-outlook app-outlook--pairs">
                <div
                  v-for="tile in nextTiles"
                  :key="tile.label"
                  class="app-outlook__tile"
                  :class="{ 'app-outlook__tile--total': tile.total }"
                >
                  <div class="text-caption text-grey-7">{{ tile.label }}</div>
                  <div class="text-h6 text-weight-bold money" :class="tile.class">
                    {{ tile.value }}
                  </div>
                  <div class="text-caption money" :class="tile.noteClass ?? 'text-grey-6'">
                    {{ tile.note }}
                  </div>
                </div>
              </div>

              <div class="q-mt-md">
                <div
                  v-for="bar in nextFlow"
                  :key="bar.label"
                  class="app-outlook__bar row items-center no-wrap"
                >
                  <div class="app-outlook__bar-label text-caption text-grey-7">{{ bar.label }}</div>
                  <div class="app-outlook__track col">
                    <div
                      v-for="part in bar.parts"
                      :key="part.colour"
                      :style="{ width: `${part.width}%`, background: part.colour }"
                    />
                  </div>
                </div>
              </div>
              <div class="text-caption text-grey-6 q-mt-sm">
                Solid is known, pale the typical income and spending, dividends among them.
              </div>
            </q-card-section>
          </q-card>
        </div>
      </div>
    </div>

    <!-- A plain div: a q-col-gutter row directly inside a q-gutter column misaligns. -->
    <div>
      <div class="row q-col-gutter-md">
        <div class="col-12 col-md-5">
          <q-card flat bordered class="full-height">
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
        </div>

        <div class="col-12 col-md-7">
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
      </div>
    </div>
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
  // The forecast's month after this one: known and typical figures, decimal strings.
  nextMonth: { type: Object, default: null },
  upcoming: { type: Array, default: Array },
  upcomingMore: { type: Number, default: 0 },
  upcomingDays: { type: Number, default: 14 },
})

const money = useMoney()
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

  const held = cashItems.value.filter(item => !item.empty)
  const emptyCash = cashItems.value.length - held.length

  return [
    {
      label: 'Net worth',
      key: 'net_worth',
      colour: colours.net_worth,
      value: h.net_worth,
      class: 'text-grey-9',
      ...onLastMonth('net_worth'),
      items: movement.value,
    },
    {
      label: 'Cash',
      key: 'cash',
      colour: colours.cash,
      value: h.cash,
      class: negative(h.cash) ? 'text-negative' : 'text-positive',
      ...onLastMonth('cash'),
      items: held,
      more: emptyCash ? `+${emptyCash} empty` : null,
      empty: 'No cash account yet.',
    },
    {
      label: 'Stocks',
      key: 'value',
      colour: colours.value,
      value: h.value,
      class: 'text-primary',
      // The same note as the others: the unrealised gain is on each brokerage's row.
      ...onLastMonth('value'),
      items: brokerItems.value.filter(item => !item.empty),
    },
    {
      label: 'Cards owe',
      key: 'cards',
      colour: colours.cards,
      value: h.cards,
      class: isZero(h.cards) ? 'text-grey-9' : 'text-negative',
      ...onLastMonth('cards'),
      items: statementItems.value,
      empty: 'Nothing owed on any card.',
    },
  ]
})

// Net worth's own story rather than its parts, which are the three cards beside it: how
// it has moved over the trend's months, and its best and worst month. From the deferred
// trend, so until it lands the rows hold their place, the card no taller once it does.
const movement = computed(() => {
  const points = props.trend
  const open = () => go('/net-worth')

  if (points.length < 2) {
    return ['3m', '6m', 'best'].map(key => ({
      key,
      name: '…',
      value: '—',
      valueClass: 'text-grey-5',
      lines: [{ text: '\u00a0' }],
    }))
  }

  const now = points.at(-1).net_worth
  const since = (point, name) => {
    const change = minus(now, point.net_worth)
    const pct = percent(change, point.net_worth)

    return {
      key: name,
      name,
      value: signed(change),
      valueClass: signClass(change),
      // Its share and the month it counts from; the starting figure was cut off here.
      lines: [{ text: `${pct ? `${pct} ` : ''}since ${monthName(point.date.slice(0, 7))}` }],
      open,
    }
  }

  // Each month's change, the month named by the snapshot it ends on.
  const months = points.slice(1).map((point, i) => ({
    month: point.date.slice(0, 7),
    change: minus(point.net_worth, points[i].net_worth),
  }))
  const byChange = [...months].sort((a, b) => Number(b.change) - Number(a.change))
  const [best, worst] = [byChange[0], byChange.at(-1)]

  return [
    ...(points.length > 4 ? [since(points.at(-4), '3 months')] : []),
    since(points[0], `${points.length - 1} months`),
    {
      key: 'best',
      name: `Best, ${monthName(best.month)}`,
      value: signed(best.change),
      valueClass: signClass(best.change),
      lines: [{ text: `worst ${monthName(worst.month)} ${signed(worst.change)}` }],
      open,
    },
  ]
})

// Decimal strings added and taken away exactly: BigInt at four places, so a change shown is
// the difference of the two figures and not a float's rounding of it.
const scaled = value => {
  const [, sign, whole, fraction = ''] = String(value ?? '0').match(/^(-?)(\d*)\.?(\d*)$/) ?? []
  const units = BigInt((whole || '0') + fraction.padEnd(4, '0').slice(0, 4))

  return sign ? -units : units
}

const fromUnits = units => {
  const digits = (units < 0n ? -units : units).toString().padStart(5, '0')

  return `${units < 0n ? '-' : ''}${digits.slice(0, -4)}.${digits.slice(-4)}`
}

const plus = (a, b) => fromUnits(scaled(a) + scaled(b))

const minus = (a, b) => fromUnits(scaled(a) - scaled(b))

const monthFormat = new Intl.DateTimeFormat('en', { month: 'long', timeZone: 'UTC' })

const monthName = month => {
  const [year, number] = month.split('-').map(Number)

  return monthFormat.format(new Date(Date.UTC(year, number - 1, 1)))
}

// How far through its month the page is, for the header's bar.
const monthDays = computed(() => {
  const [year, number] = (props.month?.month ?? '2000-01').split('-').map(Number)
  const total = new Date(Date.UTC(year, number, 0)).getUTCDate()

  return { total, done: total - (props.month?.days_left ?? 0) }
})

// A comparison for reading, not money, so a float percentage is fine.
const against = (value, average) => {
  const base = Number(average)

  if (base === 0) return null

  const change = ((Number(value) - base) / Math.abs(base)) * 100

  return {
    label: `${change >= 0 ? '+' : ''}${change.toFixed(0)}%`,
    class: change >= 0 ? 'text-positive' : 'text-negative',
  }
}

// Every figure is the server's string; nothing here adds money up.
const monthTiles = computed(() => {
  const m = props.month
  const vs = against(m.likely_net, m.average_net)

  return [
    {
      label: 'Income so far',
      value: money(m.so_far.income),
      class: 'text-positive',
      note: isZero(m.to_come.income)
        ? 'nothing more known to come'
        : `+${money(m.to_come.income)} still to come`,
    },
    {
      label: 'Spending so far',
      value: money(m.so_far.spending),
      class: 'text-negative',
      note:
        isZero(m.to_come.spending) && isZero(m.typical_rest)
          ? 'nothing more expected'
          : `−${money(m.to_come.spending)} known and ~${money(m.typical_rest)} typical to come`,
    },
    {
      label: 'Likely month end',
      value: signed(m.likely_net),
      class: signClass(m.likely_net),
      note: 'so far, what is known, and typical spending for the rest',
      total: true,
    },
    {
      label: 'Against an average month',
      value: vs?.label ?? '—',
      class: vs?.class ?? 'text-grey-7',
      note: `the last 12 months average ${signed(m.average_net)}`,
    },
  ]
})

const nextTiles = computed(() => {
  const m = props.nextMonth
  const vs = props.month ? against(m.net_typical, props.month.average_net) : null

  return [
    {
      label: 'Known in',
      value: `+${money(m.in)}`,
      class: 'text-positive',
      note: isZero(m.dividends)
        ? `and ~${money(m.typical_in)} typical income`
        : `and ~${money(m.typical_in)} typical, ~${money(m.dividends)} of it dividends`,
    },
    {
      label: 'Known out',
      value: `−${money(m.out)}`,
      class: 'text-negative',
      note: `and ~${money(m.typical)} typical spending`,
    },
    {
      label: 'Likely net',
      value: signed(m.net_typical),
      class: signClass(m.net_typical),
      note: `${signed(m.net_known)} from the known alone`,
      total: true,
    },
    {
      label: 'Against an average month',
      value: vs?.label ?? '—',
      class: vs?.class ?? 'text-grey-7',
      note: props.month ? `the last 12 months average ${signed(props.month.average_net)}` : '',
    },
  ]
})

// Widths only, on one scale for both, so floats: known solid, typical pale.
const nextFlow = computed(() => {
  const m = props.nextMonth
  const [inKnown, inTypical, outKnown, outTypical] = [m.in, m.typical_in, m.out, m.typical].map(
    Number,
  )
  const scale = Math.max(1, inKnown + inTypical, outKnown + outTypical)
  const width = value => (value / scale) * 100

  return [
    {
      label: 'In',
      parts: [
        { width: width(inKnown), colour: '#059669' },
        { width: width(inTypical), colour: '#86efac' },
      ],
    },
    {
      label: 'Out',
      parts: [
        { width: width(outKnown), colour: '#dc2626' },
        { width: width(outTypical), colour: '#fecaca' },
      ],
    },
  ]
})

// Widths only, on one scale for both bars, so floats are fine here and only here.
const monthFlow = computed(() => {
  const m = props.month
  const [inDone, inCome] = [Number(m.so_far.income), Number(m.to_come.income)]
  const [outDone, outCome, outTypical] = [
    Number(m.so_far.spending),
    Number(m.to_come.spending),
    Number(m.typical_rest),
  ]
  const scale = Math.max(1, inDone + inCome, outDone + outCome + outTypical)
  const width = value => (value / scale) * 100

  return [
    {
      label: 'In',
      parts: [
        { width: width(inDone), colour: '#059669' },
        { width: width(inCome), colour: '#6ee7b7' },
      ],
    },
    {
      label: 'Out',
      parts: [
        { width: width(outDone), colour: '#dc2626' },
        { width: width(outCome), colour: '#f87171' },
        { width: width(outTypical), colour: '#fecaca' },
      ],
    },
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
    name: account.name,
    ccy: account.ccy,
    value: money(account.base ?? account.balance),
    native: account.base ? money(account.balance) : null,
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
      name: broker.name,
      ccy: broker.ccy,
      value: money(broker.market_value_base ?? broker.market_value),
      native: broker.market_value_base ? money(broker.market_value) : null,
      valueClass: 'text-grey-9',
      empty: broker.open === 0 && isZero(broker.market_value),
      lines: [
        {
          // The gain and its share, without the word: in a card this narrow it was cut off.
          text: `${signed(broker.unrealised_base ?? broker.unrealised)}${pct ? ` (${pct})` : ''}`,
          class: signClass(broker.unrealised),
        },
        // Only when it matters: a holding with no price is missing from the value above.
        ...(broker.unpriced ? [{ text: `${broker.unpriced} unpriced, left out` }] : []),
      ],
      open: () => openBroker(broker.id),
    }
  }),
)

// One row a card, whatever number of its statements are open: what it owes in all, from
// the statement strings added exactly. Overdue if any of them is, and a line only for what
// changes the figure -- a part paid, or charges not final yet -- as a statement had.
const statementItems = computed(() => {
  const cards = new Map()

  for (const statement of props.statements) {
    const id = statement.card.id
    const card = cards.get(id) ?? {
      card: statement.card,
      owed: '0',
      paid: '0',
      pending: 0,
      overdue: false,
      count: 0,
    }

    cards.set(id, {
      ...card,
      owed: plus(card.owed, statement.owed),
      paid: statement.payment_count ? plus(card.paid, statement.paid) : card.paid,
      pending: card.pending + (statement.pending_count ?? 0),
      overdue: card.overdue || statement.days_until_due < 0,
      count: card.count + 1,
    })
  }

  return [...cards.values()].map(entry => ({
    key: entry.card.id,
    name: entry.card.name,
    ccy: entry.card.ccy,
    value: money(entry.owed),
    valueClass: 'text-negative',
    overdue: entry.overdue,
    lines: [
      ...(!isZero(entry.paid) ? [{ text: `${money(entry.paid)} paid` }] : []),
      ...(entry.pending ? [{ text: `${entry.pending} not yet posted` }] : []),
    ],
    // Every statement it has open, so the card's unpaid charges rather than one period's.
    open: () =>
      router.visit('/transactions', {
        data: { filter: { account_id: entry.card.id, unpaid: '1' } },
      }),
  }))
})
</script>
