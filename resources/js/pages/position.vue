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
        >
          <q-tooltip :delay="500" :offset="[0, 6]">
            {{
              at
                ? `The week up to ${formatDate(at)}, for what was held then`
                : 'The last week, for what is held'
            }}
          </q-tooltip>
        </q-btn>
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
        <q-space />
        <q-btn
          v-if="view.fold"
          flat
          dense
          no-caps
          color="grey-8"
          class="text-caption q-px-sm"
          :icon-right="byCurrency ? 'expand_less' : 'expand_more'"
          :label="byCurrency ? 'Hide each currency' : 'Show each currency'"
          @click="byCurrency = !byCurrency"
        />
      </q-card-section>

      <!-- The three figures that say how the holdings are doing, and the rest in a line. -->
      <q-card-section class="q-pt-none">
        <div class="app-position-headline">
          <div v-for="figure in headline(view.figureRows[0].totals)" :key="figure.label">
            <div class="text-caption text-grey-7">{{ figure.label }}</div>
            <div class="text-h5 text-weight-bold money" :class="figure.class">
              {{ figure.value }}
            </div>
            <div class="text-caption money" :class="figure.noteClass">{{ figure.note }}</div>
          </div>
        </div>
        <div class="text-caption text-grey-7 money q-mt-sm">
          {{ details(view.figureRows[0].totals) }}
        </div>

        <!-- Each currency in its own money, under the sum in the base one. -->
        <div
          v-for="row in view.figureRows.slice(1).filter(() => !view.fold || byCurrency)"
          :key="row.ccy"
          class="app-position-currency text-caption money"
        >
          <span class="text-weight-medium text-grey-9">{{ row.ccy }}</span>
          <span v-for="figure in headline(row.totals)" :key="figure.label">
            {{ figure.label }}
            <span class="text-weight-medium" :class="figure.class">{{ figure.value }}</span>
          </span>
          <span class="text-grey-7">{{ details(row.totals) }}</span>
        </div>
      </q-card-section>

      <q-card-section v-if="holdings.length > 1" class="q-pt-none">
        <div class="text-caption text-grey-7 q-mb-xs">Share of market value, in {{ base }}</div>
        <PositionAllocation :holdings="holdings" :base="base" />
      </q-card-section>

      <q-separator />

      <q-markup-table
        v-if="view.rows.length"
        flat
        dense
        class="app-positions"
        :style="{ '--app-sticky-top': `${barHeight}px` }"
      >
        <thead>
          <tr class="text-grey-7">
            <th
              v-for="column in columns"
              :key="column.key"
              :class="[
                column.align === 'left' ? 'text-left' : 'text-right',
                { 'cursor-pointer app-positions__sortable': column.sort },
              ]"
              @click="column.sort && sortBy(column.key)"
            >
              {{ column.label }}
              <q-icon
                v-if="column.sort && sort.key === column.key"
                :name="sort.desc ? 'arrow_downward' : 'arrow_upward'"
                size="xs"
              />
            </th>
          </tr>
        </thead>
        <tbody v-for="group in groups" :key="group.owner?.id ?? 'one'">
          <!-- A brokerage's own line, with its subtotal, above its holdings. -->
          <tr v-if="group.owner" class="app-positions__group">
            <td :colspan="columns.length">
              <div class="row items-center no-wrap">
                <span class="text-weight-bold text-grey-9">{{ group.owner.name }}</span>
                <q-badge outline color="grey-7" class="q-ml-sm" :label="group.owner.ccy" />
                <span v-if="group.owner.settles_into" class="text-caption text-grey-6 q-ml-sm">
                  into {{ group.owner.settles_into }}
                </span>
                <q-space />
                <span class="text-caption text-grey-7 q-mr-xs">Market value</span>
                <span class="money text-weight-medium q-mr-lg">
                  {{ money(group.owner.market_value) }}
                </span>
                <span class="text-caption text-grey-7 q-mr-xs">Total return</span>
                <span class="money text-weight-medium" :class="signClass(group.owner.pnl)">
                  {{ group.owner.pnl === null ? '—' : signed(group.owner.pnl) }}
                </span>
              </div>
            </td>
          </tr>

          <tr
            v-for="{ owner, position } in group.rows"
            :key="`${owner.id}-${position.symbol}`"
            class="cursor-pointer app-positions__row"
            :class="{ 'text-grey-6': !position.open }"
            @click="openTrades(owner, position)"
          >
            <td class="text-left">
              <div class="row items-center no-wrap">
                <span class="text-weight-bold text-grey-9">{{ position.symbol }}</span>
                <!-- Stopped, so naming a symbol does not also open its trades. -->
                <span
                  class="app-positions__name text-caption ellipsis cursor-pointer q-ml-sm"
                  @click.stop
                >
                  <span :class="names[position.symbol] ? 'text-grey-8' : 'text-grey-5'">
                    {{ names[position.symbol] ?? 'add name' }}
                  </span>
                  <q-popup-edit
                    v-slot="scope"
                    :model-value="names[position.symbol] ?? ''"
                    buttons
                    label-set="Save"
                    @save="value => saveName(position, value)"
                  >
                    <q-input
                      v-model="scope.value"
                      dense
                      autofocus
                      maxlength="120"
                      :label="`What ${position.symbol} is called`"
                      hint="Blank puts back the name the next fetch finds"
                      @keyup.enter="scope.set"
                    />
                  </q-popup-edit>
                  <q-tooltip v-if="names[position.symbol]" :delay="700" :offset="[0, 6]">
                    {{ names[position.symbol] }}
                  </q-tooltip>
                </span>
                <q-badge
                  v-if="!position.open"
                  color="grey-3"
                  text-color="grey-8"
                  class="q-ml-sm"
                  label="sold out"
                />
              </div>
              <div v-if="position.open" class="text-caption text-grey-7 money">
                {{ quantity(position.quantity) }} @ {{ money(position.average_cost) }}
              </div>
              <div class="text-caption text-grey-6">{{ activity(position) }}</div>
            </td>

            <!-- Stopped, so setting a price does not also open the trades. -->
            <td class="text-right money" @click.stop>
              <div class="cursor-pointer">
                <template v-if="position.price">
                  <!-- The line beside the price and its change, as tall as the two together. -->
                  <div class="row items-start no-wrap justify-end">
                    <HomeSpark
                      v-if="trendOf(owner, position).length > 1"
                      :values="trendOf(owner, position).map(close => close.close)"
                      :colour="trendChange(owner, position) < 0 ? '#dc2626' : '#059669'"
                      :label="`${position.symbol} over the last 30 days`"
                      class="app-price-spark q-mr-sm"
                    />
                    <div>
                      <div>{{ money(position.price) }}</div>
                      <div class="text-caption text-grey-6">
                        <span
                          v-if="trendOf(owner, position).length > 1"
                          :class="
                            trendChange(owner, position) < 0 ? 'text-negative' : 'text-positive'
                          "
                        >
                          {{ trendLabel(owner, position) }}
                        </span>
                        <div v-if="priceNote(position)">{{ priceNote(position) }}</div>
                      </div>
                    </div>
                  </div>
                  <q-tooltip
                    v-if="trendOf(owner, position).length > 1"
                    :delay="500"
                    :offset="[0, 6]"
                  >
                    {{ trendTip(owner, position) }}
                  </q-tooltip>
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
              </div>
            </td>

            <td class="text-right money text-grey-8">
              <template v-if="position.open">{{ money(position.cost) }}</template>
            </td>

            <td class="text-right money">
              <span v-if="position.market_value" class="text-weight-medium text-grey-9">
                {{ money(position.market_value) }}
              </span>
            </td>

            <td class="text-right money" :class="signClass(position.unrealised)">
              <template v-if="position.unrealised">
                {{ signed(position.unrealised) }}
                <div class="text-caption">{{ unrealisedPercent(position) }}</div>
              </template>
            </td>

            <!-- Received, on the symbol this row holds; its count opens those rows. -->
            <td
              v-if="view.dividends"
              class="text-right money"
              :class="{ 'cursor-pointer': position.dividend_count }"
              @click.stop="openDividends(position, owner)"
            >
              <template v-if="position.dividend_count">
                <span class="text-grey-9">{{ money(position.dividends) }}</span>
                <div class="text-caption text-grey-6">
                  {{ payments(position.dividend_count) }}
                  <q-icon name="open_in_new" size="xs">
                    <q-tooltip :delay="500" :offset="[0, 6]">
                      This symbol's dividend transactions
                    </q-tooltip>
                  </q-icon>
                </div>
              </template>
              <span v-else class="text-grey-5">—</span>
            </td>

            <!-- What the line has made, over the cost still held. Blank where a holding has
                 no price: its cost is in none of the three legs, so there is no figure of
                 the whole that would not be quietly wrong. -->
            <td class="text-right money" :class="signClass(position.pnl)">
              <template v-if="position.pnl !== null">
                <span class="text-weight-bold">{{ signed(position.pnl) }}</span>
                <div class="text-caption" :class="pnlNoteClass(position)">
                  {{ pnlNote(position) }}
                </div>
              </template>
            </td>

            <!-- The return as a bar from a zero line, capped at 100% either way. -->
            <td class="app-positions__bar-cell">
              <div v-if="position.pnl_percent !== null" class="app-return-bar">
                <div
                  class="app-return-bar__fill"
                  :class="Number(position.pnl_percent) < 0 ? 'is-loss' : 'is-gain'"
                  :style="returnBar(position.pnl_percent)"
                />
              </div>
            </td>
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
  // Symbol => what it is called, fetched from Yahoo or typed in.
  names: { type: Object, default: () => ({}) },
  // Brokerage id => symbol => its closes over the last 30 days, oldest first.
  trends: { type: Object, default: () => ({}) },
})

const dayMenu = ref(null)

// The app bar's height, read off the bar rather than written down: the column labels are
// pinned just under it. The layout is hHh with no reveal, so the bar is fixed and always on
// screen, and its height is the whole offset -- the earlier attempt assumed it scrolled away.
const barHeight = ref(0)
const bar = ref(null)

onMounted(() => {
  bar.value = document.querySelector('.q-layout .q-header')
})

useResizeObserver(bar, () => {
  barHeight.value = bar.value?.offsetHeight ?? 0
})

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

// The per-currency rows under the All view's HKD sum, folded away until asked for.
const byCurrency = useStorage('positions.byCurrency', false)

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

const inBaseCaption = (combined, all = true) =>
  [
    `${all ? 'all ' : ''}in ${props.base} at ${props.at ? "that day's" : "today's"} rate`,
    ...(combined.unconverted.length
      ? [`${combined.unconverted.join(', ')} left out of it, no rate yet`]
      : []),
  ].join(' · ')

const rowsOf = b => shown(b).map(position => ({ owner: b, position }))

// Same symbol at two brokerages stays two rows: each has its own cost basis.
const view = computed(() => {
  if (broker.value) {
    return {
      key: broker.value.id,
      all: false,
      title: broker.value.name,
      currencies: [broker.value.ccy],
      caption: [
        ...(broker.value.settles_into ? [`Settles into ${broker.value.settles_into}`] : []),
        ...(broker.value.combined ? [inBaseCaption(broker.value.combined, false)] : []),
      ].join(' · '),
      // A foreign brokerage also in the base currency, above its own figures as in All.
      figureRows: [
        ...(broker.value.combined && !broker.value.combined.unconverted.length
          ? [
              {
                ccy: 'all',
                totals: broker.value.combined,
                prefix: `In ${props.base} · `,
                combined: true,
              },
            ]
          : []),
        {
          ccy: broker.value.ccy,
          totals: broker.value,
          prefix: broker.value.combined ? `${broker.value.ccy} ` : '',
        },
      ],
      fold: false,
      // Keyed on the total, not on the rows on screen: the column must not come and go
      // with the sold-out toggle.
      dividends: received(broker.value.dividends),
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
      ...(props.combined ? [inBaseCaption(props.combined)] : []),
    ].join(' · '),
    fold: !!props.combined,
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
    dividends: props.totals.some(total => received(total.dividends)),
    traded: props.brokerages.some(b => b.positions.length > 0),
  }
})

// A figure is only worth drawing if something landed in it, and four places are always
// carried: an amount of nothing is "0.0000".
const received = value => /[1-9]/.test(String(value))

// Quasar's $q has no pluralize(), and a cell that throws while rendering takes the row
// with it, so the count is worded here as it is on Home.
const payments = count => `${count} payment${count === 1 ? '' : 's'}`

const shown = broker => broker.positions.filter(position => position.open || showClosed.value)

// Trimmed on the string: a quantity is a decimal, never a float.
const quantity = value => {
  const trimmed = String(value).includes('.') ? String(value).replace(/\.?0+$/, '') : String(value)

  return trimmed === '' ? '0' : trimmed
}

const $q = useQuasar()

const fetching = ref(false)

const fetchPrices = () => {
  router.post('/prices/fetch', props.at ? { at: props.at } : {}, {
    preserveScroll: true,
    // The button's own spinner -- see app.js.
    showProgress: false,
    onStart: () => (fetching.value = true),
    onSuccess: () => notifySuccess(),
    onFinish: () => (fetching.value = false),
  })
}

const saveName = (position, name) => {
  router.post(
    '/symbols/name',
    { symbol: position.symbol, name },
    {
      preserveScroll: true,
      onSuccess: () => notifySuccess(),
      onError: errors => $q.notify({ type: 'negative', message: Object.values(errors)[0] }),
    },
  )
}

const trendOf = (owner, position) => props.trends[owner.id]?.[position.symbol] ?? []

// A change for reading, not money, so a float.
const trendChange = (owner, position) => {
  const closes = trendOf(owner, position)
  const first = Number(closes[0]?.close)

  return first > 0 ? (Number(closes.at(-1).close) - first) / first : 0
}

const trendLabel = (owner, position) => {
  const change = trendChange(owner, position) * 100

  return `${change > 0 ? '+' : ''}${change.toFixed(1)}% 30d`
}

// The two closes the change is between, the server's strings; a gap of more than a week
// between closes is drawn straight across, and said so.
const trendTip = (owner, position) => {
  const closes = trendOf(owner, position)
  const first = closes[0]
  const last = closes.at(-1)
  const gap = closes.some(
    (close, i) => i > 0 && new Date(close.date) - new Date(closes[i - 1].date) > 7 * 86400000,
  )

  return `${money(first.close)} on ${formatDate(first.date)} → ${money(last.close)} on ${formatDate(last.date)}${gap ? ' · a gap of over a week is drawn straight across' : ''}`
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

// The symbol filter is a substring match, so a sibling ticker can come along; the
// transactions page shows what it found, rather than this column quietly counting it.
const openDividends = position => {
  // A blank cell has no pointer and no transactions behind it to open.
  if (!position.dividend_count) return

  router.visit('/transactions', {
    data: { filter: { type: 'dividend', symbol: position.symbol } },
  })
}

// The trades page's own query shape, so the filter row and its chips open already set:
// a value it writes as one string, not a bracketed array. The brokerage is named because
// the same ticker at two brokerages is two rows, and a symbol on its own would open the
// other one's trades beside these.
const openTrades = (owner, position) =>
  router.visit('/transactions', {
    data: { filter: { account_id: owner.id, type: 'buy,sell', symbol: position.symbol } },
  })

// A return needs the capital behind it, and there are two ways there is none: a holding
// with no price, which has no P&L to be a return on, and a position sold out, which has
// banked its P&L and left nothing held. Both say so rather than reading as a blank.
const pnlNote = totals => {
  if (totals.pnl === null) return ''

  return totals.pnl_percent === null ? 'no cost held' : `${totals.pnl_percent}%`
}

const pnlNoteClass = totals =>
  totals.pnl_percent === null ? 'text-grey-6' : signClass(totals.pnl_percent)

const signed = value =>
  String(value).startsWith('-') || !/[1-9]/.test(String(value)) ? money(value) : `+${money(value)}`

// Market value less unrealised is the cost of what is priced, the only cost the unrealised
// figure is a share of. A percentage for reading, so a float.
const unrealisedOf = totals => {
  const cost = Number(totals.market_value) - Number(totals.unrealised)

  return cost > 0 ? `${((Number(totals.unrealised) / cost) * 100).toFixed(2)}%` : ''
}

const unrealisedPercent = position => unrealisedOf(position)

const headline = totals => [
  {
    label: totals.unpriced ? `Market value (${totals.unpriced} unpriced)` : 'Market value',
    value: money(totals.market_value),
    class: 'text-grey-9',
    note: `cost ${money(totals.open_cost)}`,
    noteClass: 'text-grey-6',
  },
  {
    label: 'Unrealised',
    value: signed(totals.unrealised),
    class: signClass(totals.unrealised),
    note: unrealisedOf(totals),
    noteClass: signClass(totals.unrealised),
  },
  {
    label: 'Total return',
    value: totals.pnl === null ? '—' : signed(totals.pnl),
    class: signClass(totals.pnl),
    note: totals.pnl === null ? 'a holding has no price' : pnlNote(totals),
    noteClass: pnlNoteClass(totals),
  },
]

// The legs of the total return, and the fees it is before.
const details = totals =>
  [
    `Realised ${signed(totals.realised)}`,
    `Dividends ${money(totals.dividends)}`,
    `Fees ${money(totals.fees)}, not taken off the return`,
  ].join(' · ')

// Trades, the last of them, and the fees and realised gains a row has only sometimes.
const activity = position =>
  [
    `${position.trades} trade${position.trades === 1 ? '' : 's'}`,
    position.last_trade_date ? `last ${formatDate(position.last_trade_date)}` : null,
    received(position.fees) ? `fees ${money(position.fees)}` : null,
    received(position.realised) ? `realised ${signed(position.realised)}` : null,
  ]
    .filter(Boolean)
    .join(' · ')

// The day most prices are from, so only a price older than it, or set by hand, says when.
const latestPriceDate = computed(() =>
  props.brokerages
    .flatMap(b => b.positions.map(p => p.price_date))
    .filter(Boolean)
    .sort()
    .at(-1),
)

const priceNote = position =>
  [
    position.price_date !== latestPriceDate.value ? formatDate(position.price_date) : null,
    position.price_source === 'manual' ? 'manual' : null,
  ]
    .filter(Boolean)
    .join(' · ')

const returnBar = percent => {
  const width = Math.min(Math.abs(Number(percent)), 100) / 2

  return Number(percent) < 0
    ? { right: '50%', width: `${width}%` }
    : { left: '50%', width: `${width}%` }
}

const columns = computed(() => [
  { key: 'symbol', label: 'Symbol', align: 'left', sort: true },
  { key: 'price', label: 'Price' },
  { key: 'cost', label: 'Cost', sort: true },
  { key: 'market', label: 'Market value', sort: true },
  { key: 'unrealised', label: 'Unrealised', sort: true },
  ...(view.value?.dividends ? [{ key: 'dividends', label: 'Dividends', sort: true }] : []),
  { key: 'pnl', label: 'Total return', sort: true },
  { key: 'bar', label: 'Return' },
])

// Largest holding first unless asked otherwise; remembered per browser, a view choice.
const sort = useStorage('positions.sort', { key: 'market', desc: true })

const sortBy = key => {
  sort.value = { key, desc: sort.value.key === key ? !sort.value.desc : key !== 'symbol' }
}

// Numbers for ordering only. A sold-out row goes last whatever the order, since it holds
// nothing to compare.
const sortValue = {
  symbol: p => p.symbol,
  cost: p => Number(p.open ? p.cost : 0),
  market: p => Number(p.market_value ?? 0),
  unrealised: p => Number(p.unrealised ?? 0),
  dividends: p => Number(p.dividends ?? 0),
  pnl: p => Number(p.pnl ?? 0),
}

const ordered = rows =>
  [...rows].sort((a, b) => {
    if (a.position.open !== b.position.open) return a.position.open ? -1 : 1

    const read = sortValue[sort.value.key] ?? sortValue.market
    const [x, y] = [read(a.position), read(b.position)]
    const order = typeof x === 'string' ? x.localeCompare(y) : x - y

    return sort.value.desc ? -order : order
  })

// A brokerage's holdings under its own line in the All view; one list in a brokerage's.
const groups = computed(() => {
  if (!view.value) return []

  if (!view.value.all) return [{ owner: null, rows: ordered(view.value.rows) }]

  return props.brokerages
    .map(owner => ({ owner, rows: ordered(rowsOf(owner)) }))
    .filter(group => group.rows.length)
})

// What the allocation bar divides: the open holdings on show, in the base currency.
const holdings = computed(() =>
  (view.value?.rows ?? [])
    .filter(({ position }) => position.open)
    .map(({ owner, position }) => ({
      key: `${owner.id}-${position.symbol}`,
      label: position.symbol,
      name: props.names[position.symbol] ?? null,
      base: position.market_value_base,
    })),
)
</script>
