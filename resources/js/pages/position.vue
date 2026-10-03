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
        <q-btn flat dense no-caps class="app-toolbar__pick q-px-sm" icon-right="expand_more">
          <div class="row items-center no-wrap">
            <q-icon name="event" size="20px" :color="at ? 'primary' : 'grey-7'" class="q-mr-sm" />
            <div class="column items-start">
              <span class="text-caption text-grey-6 app-toolbar__label">As at</span>
              <span
                class="text-body2 text-weight-bold"
                :class="at ? 'text-primary' : 'text-grey-9'"
              >
                {{ at ? formatDate(at) : 'Today' }}
              </span>
            </div>
          </div>
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

        <!-- A chip that is on or off, with how many it would show, rather than a bare switch. -->
        <q-btn
          flat
          dense
          no-caps
          padding="xs sm"
          class="app-toolbar__chip"
          :class="{ 'app-toolbar__chip--on': showClosed }"
          :icon="showClosed ? 'visibility' : 'visibility_off'"
          @click="showClosed = !showClosed"
        >
          <span class="q-ml-xs">Sold out</span>
          <span v-if="soldOutCount" class="app-toolbar__count q-ml-xs">{{ soldOutCount }}</span>
          <q-tooltip :delay="500" :offset="[0, 6]">
            {{ showClosed ? 'Hide' : 'Show' }} the positions sold out, and what they realised
          </q-tooltip>
        </q-btn>

        <q-separator vertical inset class="q-mx-sm" />

        <!-- One button: what it does, and when it was last done under it. -->
        <q-btn
          unelevated
          no-caps
          class="app-btn app-toolbar__fetch"
          :loading="fetching"
          @click="fetchPrices"
        >
          <div class="row items-center no-wrap">
            <q-icon name="refresh" size="20px" class="q-mr-sm" />
            <div class="column items-start">
              <span class="text-weight-bold">Fetch prices</span>
              <span class="app-toolbar__fetch-note">
                {{
                  pricesUpdatedAt
                    ? `updated ${lowerFirst(whenUpdated(pricesUpdatedAt))}`
                    : 'never fetched'
                }}
              </span>
            </div>
          </div>
          <!-- A timestamp column, so the time formatter, not the calendar-day one. -->
          <q-tooltip :delay="500" :offset="[0, 6]">
            {{
              at
                ? `The week up to ${formatDate(at)}, for what was held then`
                : 'The last week, for what is held'
            }}{{ pricesUpdatedAt ? `. Last fetched ${formatTime(pricesUpdatedAt)}` : '' }}
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
        <!-- How what is held moved, its prices only so a buy or a sell is not a gain: a pill a
             period, and on hover its money and each currency's. -->
        <div v-if="viewMoves.length" class="row items-center no-wrap q-gutter-x-xs">
          <q-badge
            v-for="move in viewMoves[0].periods"
            :key="move.key"
            class="app-change"
            :class="moveTint(move)"
          >
            <span class="text-weight-medium q-mr-xs">{{ move.short }}</span>
            <span class="money">
              {{ move.percent === null ? '—' : `${signed(move.percent)}%` }}
            </span>
            <q-tooltip :delay="300" :offset="[0, 6]">
              <div class="text-weight-bold">{{ move.label }}, {{ move.note }}</div>
              <div class="money">{{ signed(move.change) }} {{ viewMoves[0].ccy }}</div>
              <div v-for="row in viewMoves.slice(1)" :key="row.ccy" class="money">
                {{ row.ccy }} {{ percentOf(row, move.key) }} ({{ changeOf(row, move.key) }})
              </div>
            </q-tooltip>
          </q-badge>
        </div>
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

      <!-- The figures that say how the holdings are doing, the return last as the sum of its legs,
           and the fees in a line. -->
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
        <!-- The cost of dealing, as the net worth page shows a change: a tinted pill, then what it is. -->
        <div
          v-if="Number(view.figureRows[0].totals.fees) > 0"
          class="row items-center no-wrap q-mt-md"
        >
          <q-badge class="app-change app-tint app-tint--muted q-mr-sm">
            <q-icon name="receipt_long" size="14px" />
            <span class="money">{{ money(view.figureRows[0].totals.fees) }}</span>
          </q-badge>
          <span class="text-caption text-grey-7">fees paid, not taken off the return</span>
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
          <span class="text-grey-7">fees {{ money(row.totals.fees) }}</span>
        </div>
      </q-card-section>

      <q-card-section v-if="holdings.length > 1" class="q-pt-none">
        <div class="text-caption text-grey-7 q-mb-xs">
          Share of market value, in {{ allocationCcy }}
        </div>
        <PositionAllocation :holdings="holdings" :base="allocationCcy" />
      </q-card-section>

      <q-separator />

      <q-markup-table
        v-if="view.rows.length"
        flat
        dense
        class="app-positions app-head-table"
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
                { 'app-positions__bar-cell': column.key === 'bar' },
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
              <!-- What is held at what cost, then the trades behind it as one segmented pill. -->
              <div class="app-holding-line money">
                <span v-if="position.open">
                  <span class="app-holding-line__held">{{ quantity(position.quantity) }}</span>
                  @ {{ money(position.average_cost) }}
                </span>
                <span v-else>nothing held</span>
                <span class="app-holding-line__pill">
                  <span v-for="part in activity(position)" :key="part.key" :class="part.class">
                    {{ part.label }}
                  </span>
                </span>
              </div>
            </td>

            <!-- The last 30 days' line and its change; empty for a holding with no closes. -->
            <td class="text-right money">
              <template v-if="trendOf(owner, position).length > 1">
                <div class="row items-start no-wrap justify-end">
                  <HomeSpark
                    :values="trendOf(owner, position).map(close => close.close)"
                    :colour="trendChange(owner, position) < 0 ? '#dc2626' : '#059669'"
                    :label="`${position.symbol} over the last 30 days`"
                    class="app-price-spark q-mr-sm"
                  />
                  <span
                    class="app-positions__change text-right"
                    :class="trendChange(owner, position) < 0 ? 'text-negative' : 'text-positive'"
                  >
                    {{ trendLabel(owner, position) }}
                  </span>
                </div>
                <q-tooltip :delay="500" :offset="[0, 6]">{{ trendTip(owner, position) }}</q-tooltip>
              </template>
            </td>

            <!-- Stopped, so setting a price does not also open the trades. -->
            <td class="text-right money" @click.stop>
              <div class="cursor-pointer">
                <template v-if="position.price">
                  <div>{{ money(position.price) }}</div>
                  <div v-if="priceNote(position)" class="text-caption text-grey-6">
                    {{ priceNote(position) }}
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

            <!-- What the closed parts of the line made. Unlike unrealised it gets no
                 percentage: a gain on shares already sold has no cost still held to
                 divide it by. The dash is hasFigure's doing, not a truth test on the
                 figure, because Positions.php opens a position's realised at "0.0000"
                 and that is a string, so the test would print 0.00 down the column. -->
            <td class="text-right money" :class="signClass(position.realised)">
              <template v-if="hasFigure(position.realised)">{{
                signed(position.realised)
              }}</template>
              <span v-else class="text-grey-5">—</span>
            </td>

            <!-- Received, on the symbol this row holds; its count opens those rows. -->
            <td
              v-if="view.dividends"
              class="text-right money"
              :class="{ 'cursor-pointer app-peek-trigger': position.dividend_count }"
              @click.stop="peekDividends(position, owner)"
            >
              <template v-if="position.dividend_count">
                <span class="app-text-dividend">{{ money(position.dividends) }}</span>
                <div class="text-caption text-grey-6">
                  {{ payments(position.dividend_count) }}
                  <!-- The cell opens the payments here; this goes on to the full list. -->
                  <q-btn
                    flat
                    round
                    dense
                    size="xs"
                    icon="open_in_new"
                    color="grey-5"
                    class="app-open-link"
                    aria-label="Open in Transactions"
                    @click.stop="openDividends(position)"
                  />
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

    <!-- One line's dividends, read when it is opened. -->
    <PeekDialog
      v-model="peek.open"
      :title="peek.meta?.position.symbol ?? ''"
      :subtitle="peekSubtitle"
      :total="peek.data?.total"
      :unit="peek.data?.ccy ?? ''"
      :count="peek.data?.count"
      noun="payment"
      :rows="peekRows"
      @open="openPeeked"
    />
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
  // What is held, at its prices then and now: per brokerage, per currency, and combined.
  moves: { type: Object, default: () => ({ brokers: {}, currencies: {}, combined: null }) },
})

const dayMenu = ref(null)

// The column labels are pinned just under the app bar.
const barHeight = useBarHeight()

const visit = day =>
  router.get('/positions', day ? { at: day } : {}, { preserveScroll: true, replace: true })

const pickDay = day => {
  dayMenu.value?.hide()
  visit(day && day !== props.today ? day : null)
}

const money = useMoney()
const formatDate = useCalendarDay()
const formatTime = useHongKongTime()

// Bound here rather than called in the template because auto-imports do not reach
// templates: vite.config.js sets no vueTemplate, so a composable used in one is
// undefined at runtime and neither lint nor build sees it.
const quantity = plainQuantity

// The same trap, and it needs its own name because script already has the auto-import
// under its own. Reads as it is used: a figure this row does not have.
const hasFigure = received

// "Today, 12:26" in Hong Kong's day; the tooltip carries the full timestamp.
const tz = usePage().props.tz
const dayOf = new Intl.DateTimeFormat('en-CA', { timeZone: tz })
const clock = new Intl.DateTimeFormat('en-GB', { timeZone: tz, hour: '2-digit', minute: '2-digit' })
const shortDay = new Intl.DateTimeFormat('en-GB', { timeZone: tz, day: 'numeric', month: 'short' })

// "Today, 12:42" reads as a sentence's middle after "updated".
const lowerFirst = text => text.charAt(0).toLowerCase() + text.slice(1)

const whenUpdated = value => {
  const at = new Date(value)
  const day = dayOf.format(at)
  const today = dayOf.format(new Date())
  const yesterday = dayOf.format(new Date(Date.now() - 86400000))

  const name = day === today ? 'Today' : day === yesterday ? 'Yesterday' : shortDay.format(at)

  return `${name}, ${clock.format(at)}`
}

const showClosed = ref(false)

// Across the brokerages in view, so the chip's count is what switching it on would add.
const soldOutCount = computed(
  () =>
    (view.value?.all ? props.brokerages : [broker.value])
      .flatMap(owner => owner?.positions ?? [])
      .filter(position => !position.open).length,
)

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
      // One brokerage is one currency, so its own figures alone: a second set in the base
      // currency beside them only repeated the same holdings at another size.
      caption: broker.value.settles_into ? `Settles into ${broker.value.settles_into}` : '',
      figureRows: [{ ccy: broker.value.ccy, totals: broker.value, prefix: '' }],
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

// Quasar's $q has no pluralize(), and a cell that throws while rendering takes the row
// with it, so the count is worded here as it is on Home.
const payments = count => `${count} payment${count === 1 ? '' : 's'}`

const shown = broker => broker.positions.filter(position => position.open || showClosed.value)

// The view's moves, first the figure it leads with -- the brokerage's, or all of them in the
// base currency -- then each currency under it, as the headline figures are laid out.
const movePeriods = sums => {
  const since = props.moves.since ?? {}
  const left = move => (move.left_out ? ` · ${move.left_out} left out, no close then` : '')

  return [
    { key: 'day', short: '1D', label: 'Since yesterday', note: 'against the last close' },
    { key: 'week', short: '1W', label: 'Past week', note: `since ${formatDate(since.week)}` },
    { key: 'month', short: '1M', label: 'Past month', note: `since ${formatDate(since.month)}` },
    {
      key: 'quarter',
      short: '3M',
      label: 'Past 3 months',
      note: `since ${formatDate(since.quarter)}`,
    },
    { key: 'half', short: '6M', label: 'Past 6 months', note: `since ${formatDate(since.half)}` },
    { key: 'year', short: '1Y', label: 'Past year', note: `since ${formatDate(since.year)}` },
  ].map(period => ({ ...period, ...sums[period.key], note: period.note + left(sums[period.key]) }))
}

const viewMoves = computed(() => {
  if (!view.value) return []

  if (!view.value.all) {
    const sums = props.moves.brokers?.[view.value.key]

    return sums ? [{ ccy: broker.value.ccy, periods: movePeriods(sums) }] : []
  }

  const currencies = Object.entries(props.moves.currencies ?? {}).map(([ccy, sums]) => ({
    ccy,
    periods: movePeriods(sums),
  }))

  return props.moves.combined
    ? [{ ccy: props.base, periods: movePeriods(props.moves.combined) }, ...currencies]
    : currencies
})

const periodOf = (row, key) => row.periods.find(period => period.key === key)
const percentOf = (row, key) => {
  const move = periodOf(row, key)

  return move.percent === null ? '—' : `${signed(move.percent)}%`
}
const changeOf = (row, key) => signed(periodOf(row, key).change)
const moveTint = move =>
  move.percent === null || !/[1-9]/.test(move.change)
    ? 'app-tint app-tint--muted'
    : String(move.change).startsWith('-')
      ? 'app-tint app-tint--negative'
      : 'app-tint app-tint--positive'

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

  return `${change > 0 ? '+' : ''}${change.toFixed(1)}%`
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

// The dividends one line has received, shown with its payments or not at all.
const peek = usePeek()

const peekDividends = (position, owner) => {
  // A blank cell has no payments behind it to show.
  if (!position.dividend_count) return

  peek.show(
    '/positions/dividends',
    { broker: owner.id, symbol: position.symbol, ...(props.at ? { at: props.at } : {}) },
    { position, owner },
  )
}

const peekSubtitle = computed(() => {
  const meta = peek.meta

  return meta
    ? [props.names[meta.position.symbol], meta.owner.name].filter(Boolean).join(' · ')
    : ''
})

const peekRows = computed(() =>
  (peek.data?.rows ?? []).map(row => ({
    id: row.id,
    label: formatDate(row.date),
    amount: row.amount,
  })),
)

const openPeeked = () => {
  peek.open = false
  openDividends(peek.meta.position)
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
    label: 'Cost',
    value: money(totals.open_cost),
    class: 'text-grey-9',
    note: 'of what is held now',
    noteClass: 'text-grey-6',
  },
  {
    label: totals.unpriced ? `Market value (${totals.unpriced} unpriced)` : 'Market value',
    value: money(totals.market_value),
    class: 'text-grey-9',
    note: totals.unpriced ? 'what has no price is left out' : 'at the latest prices',
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
    label: 'Realised',
    value: signed(totals.realised),
    class: signClass(totals.realised),
    note: 'on what was sold',
    noteClass: 'text-grey-6',
  },
  {
    label: 'Dividends',
    value: money(totals.dividends),
    class: 'app-text-dividend',
    note: 'received in all',
    noteClass: 'text-grey-6',
  },
  {
    label: 'Total return',
    value: totals.pnl === null ? '—' : signed(totals.pnl),
    class: signClass(totals.pnl),
    note: totals.pnl === null ? 'a holding has no price' : pnlNote(totals),
    noteClass: pnlNoteClass(totals),
  },
]

// Trades, the last of them, and the fees a row has only sometimes: the pill's segments.
// What the line realised is not repeated here: it has a column of its own.
const activity = position =>
  [
    {
      key: 'trades',
      label: [
        `${position.trades} trade${position.trades === 1 ? '' : 's'}`,
        position.last_trade_date ? formatDate(position.last_trade_date) : null,
      ]
        .filter(Boolean)
        .join(' · '),
    },
    received(position.fees)
      ? { key: 'fees', label: `fees ${money(position.fees)}`, class: 'app-holding-line__fees' }
      : null,
  ].filter(Boolean)

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
  { key: 'trend', label: '30d' },
  { key: 'price', label: 'Price' },
  { key: 'cost', label: 'Cost', sort: true },
  { key: 'market', label: 'Market value', sort: true },
  { key: 'unrealised', label: 'Unrealised', sort: true },
  { key: 'realised', label: 'Realised', sort: true },
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
  realised: p => Number(p.realised ?? 0),
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
      // One brokerage in its own money, as its figures above are; All in the base.
      base: view.value.all ? position.market_value_base : position.market_value,
    })),
)

const allocationCcy = computed(() => (view.value?.all ? props.base : view.value?.currencies[0]))
</script>
