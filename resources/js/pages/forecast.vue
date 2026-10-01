<template>
  <div class="column no-wrap q-gutter-lg">
    <div>
      <div class="row items-center">
        <div class="text-h6 text-weight-medium q-mr-md">Forecast</div>
        <q-select
          v-if="currencies.length > 1"
          :model-value="ccy"
          :options="currencyOptions"
          class="app-broker-select"
          dense
          outlined
          emit-value
          map-options
          options-dense
          @update:model-value="value => (ccy = value)"
        >
          <template #prepend>
            <q-icon name="payments" size="xs" color="grey-7" />
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
      </div>
      <div class="text-caption text-grey-7 q-mt-xs">
        Each cash account from today: rows dated ahead or still pending, recurring rules, and card
        statements paid from their bank on the due date. Typical spending is an estimate and shown
        apart.
      </div>
    </div>

    <div
      v-for="warning in allWarnings"
      :key="warning"
      class="app-note app-note--warning row no-wrap"
    >
      <q-icon name="warning_amber" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
      <div>{{ warning }}</div>
    </div>

    <div v-if="!projection.length" class="text-grey-6">No cash accounts to forecast.</div>

    <!-- What the projection leaves free: the lowest the balance gets on the horizon, less a
         reserve of months of spending, is cash that could go elsewhere and never take the
         balance under the reserve. -->
    <q-card v-for="spare in spares" :key="spare.ccy" flat bordered>
      <!-- The runway card's header: its title on the left and its toolbar on the right. To the
           top, not the middle: this toolbar is twice the runway's height for its slider, and
           centred on it the title sat twenty pixels lower than the runway's does. -->
      <q-card-section class="row items-start q-gutter-y-sm">
        <div class="row items-center no-wrap">
          <q-icon name="savings" size="sm" color="grey-6" class="q-mr-sm" />
          <div>
            <div class="text-subtitle1 text-weight-medium">
              {{ spare.short ? 'Short of the reserve' : 'Spare cash' }}
            </div>
            <div class="text-caption text-grey-7">
              What could be moved out today and leave the reserve untouched, in {{ spare.ccy }}.
            </div>
          </div>
        </div>
        <q-space />

        <div class="col-auto app-toolbar app-toolbar--slider row items-center">
          <q-icon name="shield" size="xs" color="grey-6" class="q-mx-sm">
            <q-tooltip :delay="500" :offset="[0, 6]">The reserve, in months of spending</q-tooltip>
          </q-icon>
          <!-- From nothing to a year in half months, wide enough to land on a half: each month
               marked above the track and the one picked under the thumb, as Quasar's own
               marker-labels example lays them out. -->
          <div class="q-px-md app-toolbar__slider app-toolbar__slider--labelled">
            <q-slider
              v-model="reserveMonths"
              :min="0"
              :max="12"
              :step="0.5"
              :markers="1"
              marker-labels
              marker-labels-class="text-caption text-grey-7"
              switch-marker-labels-side
              label-always
              switch-label-side
              :label-value="monthsLabel(reserveMonths)"
              color="primary"
            />
          </div>
        </div>
      </q-card-section>

      <!-- The sum itself, each part named where it stands, rather than a sentence to unpick. -->
      <q-card-section class="q-pt-none">
        <div class="app-spare">
          <div>
            <div class="text-caption text-grey-7">Lowest ahead</div>
            <div class="text-h6 text-weight-medium money text-grey-9">
              {{ spare.estimate ? '≈ ' : '' }}{{ money(spare.lowest) }}
            </div>
            <div class="text-caption text-grey-6">{{ spare.lowNote }}</div>
          </div>
          <div class="app-spare__op text-h6 text-grey-5">−</div>
          <div>
            <div class="text-caption text-grey-7">Reserve</div>
            <div class="text-h6 text-weight-medium money text-grey-9">
              {{ spare.estimate ? '≈ ' : '' }}{{ money(spare.reserve) }}
            </div>
            <div class="text-caption text-grey-6">{{ monthsLabel(reserveMonths) }} of spending</div>
          </div>
          <div class="app-spare__op text-h6 text-grey-5">=</div>
          <div>
            <div class="text-caption text-grey-7">{{ spare.short ? 'Short by' : 'Spare' }}</div>
            <div
              class="text-h4 text-weight-bold money"
              :class="spare.short ? 'text-negative' : 'text-positive'"
            >
              {{ spare.estimate ? '≈ ' : '' }}{{ money(spare.amount) }}
            </div>
            <div class="text-caption text-grey-6">
              {{ spare.short ? 'more is needed to keep the reserve' : 'could be moved out today' }}
            </div>
          </div>
        </div>
        <div class="text-caption text-grey-7 q-mt-sm">
          {{
            spare.short
              ? 'The balance would fall below the reserve on the lowest day ahead.'
              : 'Moved out today, the balance would still never fall below the reserve.'
          }}
        </div>
      </q-card-section>
    </q-card>

    <q-card v-for="section in projection" :key="section.ccy" flat bordered>
      <q-card-section class="row items-center q-gutter-y-sm">
        <q-icon name="query_stats" size="sm" color="grey-6" class="q-mr-sm" />
        <div>
          <div class="text-subtitle1 text-weight-medium">Cash runway</div>
          <div class="text-caption text-grey-7">
            <template v-if="ccy">Every {{ ccy }} account, in {{ ccy }}.</template>
            <template v-else>Every cash account, in {{ base }} at today's rate.</template>
          </div>
        </div>
        <q-space />

        <!-- On the chart it changes, as the cash flow page keeps its: the toggle draws the
             pale second line, and the horizon is the one the figures below count to. -->
        <div class="col-auto app-toolbar row items-center">
          <q-toggle
            v-model="withTypical"
            label="Typical spending"
            color="amber-8"
            dense
            class="q-px-sm"
          />
          <!-- What the typical spending would be if it ran above or below the median month. -->
          <div class="row items-center no-wrap q-px-sm app-toolbar__slider">
            <q-slider
              v-model="spendingChange"
              :min="-50"
              :max="50"
              :step="10"
              :disable="!withTypical"
              markers
              snap
              dense
              color="amber-8"
              class="col"
            />
            <span class="text-caption money q-ml-sm text-grey-8" style="width: 40px">
              {{ spendingChange > 0 ? '+' : '' }}{{ spendingChange }}%
            </span>
            <q-tooltip>Typical spending, up to half again either way</q-tooltip>
          </div>
          <!-- On by default: off is the question "what if the year has no large month". -->
          <q-toggle
            v-if="!isZero(section.typical_basis?.irregular ?? '0')"
            v-model="irregular"
            label="Irregular spending"
            :disable="!withTypical"
            dense
            color="amber-8"
            class="q-px-sm"
          />
          <q-toggle v-model="noIncome" label="No income" dense color="negative" class="q-px-sm" />
          <q-btn
            flat
            round
            dense
            size="sm"
            icon="restart_alt"
            color="primary"
            :disable="!whatIfOn"
            @click="resetWhatIf"
          >
            <q-tooltip>Back to the typical month</q-tooltip>
          </q-btn>
          <!-- The sums under the chart: a place to check an estimate from, not to read every visit. -->
          <q-toggle
            v-model="showBasis"
            label="Breakdown"
            :disable="!withTypical"
            color="amber-8"
            dense
            class="q-px-sm"
          />

          <div class="row items-center no-wrap self-stretch">
            <q-separator vertical inset class="q-mx-sm" />

            <q-icon name="date_range" size="xs" color="grey-6" class="q-mx-sm" />
            <q-btn-toggle
              :model-value="months"
              :options="horizons.map(n => ({ label: `${n} months`, value: n }))"
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
        </div>
      </q-card-section>

      <!-- What the chart is showing, as figures rather than a row of columns beside the
           title: three of them, or five or six with the estimates on. -->
      <q-card-section class="q-pt-none">
        <div class="app-outlook app-outlook--runway">
          <div v-for="figure in figures(section)" :key="figure.label" class="app-outlook__tile">
            <div class="text-caption text-grey-7">{{ figure.label }}</div>
            <div class="text-h6 text-weight-bold money" :class="figure.class">
              {{ figure.value }}
            </div>
            <div class="text-caption text-grey-6">{{ figure.caption }}</div>
          </div>
        </div>
      </q-card-section>

      <q-separator />

      <q-card-section>
        <ForecastChart
          :points="section.points"
          :ccy="section.ccy"
          :lowest="section.lowest_ahead"
          :typical="withTypical && !isZero(section.typical_monthly)"
          :events="section.events"
          :what-if="whatIf"
        />

        <!-- Laid out as sums, so each estimate can be checked from its parts. -->
        <div
          v-if="
            withTypical && showBasis && section.typical_basis && !isZero(section.typical_monthly)
          "
          class="row q-col-gutter-lg q-mt-xs text-caption"
        >
          <!-- A month, because that is the unit the estimate is made in: it adds up, with the
               rules, to the last twelve months' average. -->
          <div class="col-12 col-md-6">
            <div class="app-basis">
              <div class="app-basis__title">Typical spending, a month</div>
              <div class="app-basis__row">
                <span>Cash, spread from tomorrow</span>
                <span class="money">{{ money(section.typical_basis.cash) }}</span>
              </div>
              <div class="app-basis__row">
                <span>Cards, paid on each card's due dates</span>
                <span class="money">{{ money(section.typical_basis.card) }}</span>
              </div>
              <div class="app-basis__row">
                <span>Irregular, spread from tomorrow</span>
                <span class="money">{{ money(section.typical_basis.irregular ?? '0') }}</span>
              </div>
              <div class="app-basis__row app-basis__row--total">
                <span>Typical</span>
                <span class="money app-text-estimate">{{ money(typicalTotal(section)) }}</span>
              </div>
              <div class="app-basis__row">
                <span>Recurring rules, on the chart as their own payments</span>
                <span class="money">{{ money(section.typical_basis.recurring) }}</span>
              </div>
              <div class="app-basis__row app-basis__row--total">
                <span>With the rules</span>
                <span class="money">{{ money(spendingInAll(section)) }}</span>
              </div>
              <div class="app-basis__note">
                Cash and cards are each the median month of the last 12 complete months, so one
                large month does not set them, and irregular is what those months spent beyond that.
                Leaving out the rows marked one-off, which are in none of this, they averaged
                {{ money(section.typical_basis.average) }} a month.
              </div>
            </div>
          </div>
          <!-- Over the horizon, because most of it lands on dates rather than by the month: it
               adds up to what the typical line earns, which is the month chart's bars too. -->
          <div class="col-12 col-md-6">
            <div class="app-basis">
              <div class="app-basis__title">Typical income, over the {{ months }} months</div>
              <div class="app-basis__row">
                <span>Everyday, {{ money(section.typical_income) }} a month from tomorrow</span>
                <span class="money">{{ money(section.typical_income_basis.spread) }}</span>
              </div>
              <div v-for="row in incomeEstimates(section)" :key="row.label" class="app-basis__row">
                <span>{{ row.label }}</span>
                <span class="money">{{ money(row.value) }}</span>
              </div>
              <div class="app-basis__row app-basis__row--total">
                <span>Typical</span>
                <span class="money app-text-estimate">{{ money(incomeTotal(section)) }}</span>
              </div>
              <div class="app-basis__row">
                <span>Recurring income, on the chart as its own payments</span>
                <span class="money">{{ money(section.points.at(-1).recurring_in) }}</span>
              </div>
              <div class="app-basis__note">
                Everyday is the median month of the last 12 complete months, less what the recurring
                rules paid in each, so a bonus or a refund month does not set it. Those months
                brought {{ money(section.typical_income_basis.average) }} a month in all.
              </div>
            </div>
          </div>
        </div>
      </q-card-section>

      <q-separator />

      <q-card-section>
        <div class="text-subtitle2 text-weight-medium q-mb-sm">Month by month</div>
        <ForecastMonths
          :months="monthsShown(section)"
          :current="section.months[0]?.month"
          :ccy="section.ccy"
          :typical="withTypical && !isZero(section.typical_monthly)"
        />
      </q-card-section>

      <q-separator />

      <!-- Each account from today to the horizon: where it ends, by how much, and how low it
           goes on the way, with a bar on one scale across them all. -->
      <div class="app-runway-accounts">
        <div class="app-runway-accounts__head text-caption text-grey-7">
          <span>Account</span>
          <span>Month ends</span>
          <span class="text-right">Today</span>
          <span class="text-right">Change</span>
          <span class="text-right">In {{ months }} months</span>
        </div>
        <template v-for="group in accountGroups(section)" :key="group.ccy">
          <div v-if="group.titled" class="app-runway-group">
            <div class="text-weight-medium text-grey-8">{{ group.ccy }}</div>
            <div />
            <div class="text-right money text-grey-7">{{ group.opening }}</div>
            <div />
            <div class="text-right money text-grey-7">{{ group.closing }}</div>
          </div>
          <div v-for="account in group.accounts" :key="account.id" class="app-runway-account">
            <div class="app-runway-account__name">
              <div class="text-weight-medium text-grey-9 ellipsis">{{ account.name }}</div>
              <div
                class="text-caption ellipsis"
                :class="lowClass(account.lowest.amount) || 'text-grey-6'"
              >
                <template v-if="fallsBelowToday(account)">
                  {{ withTypical ? 'known lowest' : 'lowest' }}
                  {{ money(account.lowest.amount) }} on
                  {{ formatDate(account.lowest.date) }}
                </template>
                <template v-else>never below today</template>
              </div>
            </div>

            <!-- Today and each month end, so a dip on the way and the direction both show. -->
            <div class="app-runway-account__bar">
              <HomeSpark
                v-if="account.path.length > 1"
                :values="account.path.map(point => (withTypical ? point.expected : point.known))"
                :colour="negative(change(account)) ? '#dc2626' : '#059669'"
                :label="`${account.name}, today and each month end`"
                class="app-account-spark"
              />
            </div>

            <div class="text-right money">
              <div class="text-grey-8">{{ money(account.opening) }}</div>
              <div v-if="foreign(account)" class="text-caption text-grey-6">
                {{ account.ccy }} {{ money(account.native.opening) }}
              </div>
            </div>

            <div
              class="text-right money app-runway-account__change"
              :class="isZero(change(account)) ? 'text-grey-6' : signClass(change(account))"
            >
              {{ isZero(change(account)) ? 'no change' : signed(change(account)) }}
            </div>

            <div class="text-right money">
              <div class="text-weight-bold text-grey-9">{{ money(closingOf(account)) }}</div>
              <template v-if="withTypical">
                <div
                  v-for="kind in expectedKinds(account)"
                  :key="kind.key"
                  class="text-caption app-text-estimate"
                >
                  with ~{{ money(account[kind.key]) }} {{ kind.label }}
                </div>
              </template>
              <div v-if="foreign(account)" class="text-caption text-grey-6">
                {{ account.ccy }}
                {{ money(withTypical ? account.native.closing_expected : account.native.closing) }}
              </div>
            </div>
          </div>
        </template>
      </div>
    </q-card>

    <q-card flat bordered>
      <q-card-section>
        <div class="text-subtitle1 text-weight-medium">Next {{ upcomingDays }} days</div>
        <div class="text-caption text-grey-7">
          What is known to move cash, earliest first, and the balance each leaves.
        </div>
      </q-card-section>

      <q-separator />

      <q-markup-table v-if="soon.length" flat dense>
        <thead>
          <tr class="text-grey-7">
            <th class="text-left" colspan="4" />
            <th class="text-right">Amount</th>
            <th class="text-right">Balance after</th>
            <th v-if="withTypical" class="text-right">With typical</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="(event, i) in soon" :key="i">
            <td class="text-grey-8" style="width: 110px">{{ formatDate(event.date) }}</td>
            <td style="width: 110px">
              <q-badge v-bind="kinds[event.kind]" />
            </td>
            <td>{{ event.description }}</td>
            <td class="text-grey-7">{{ event.account }}</td>
            <td class="text-right money text-weight-medium" :class="signClass(event.amount)">
              {{ signed(event.amount) }}
              <span class="text-caption text-grey-7">{{ event.ccy }}</span>
            </td>
            <!-- An estimate moves only the typical line, so it has no known balance of its own. -->
            <td v-if="event.estimate" class="text-right text-grey-5">—</td>
            <td v-else class="text-right money" :class="lowClass(event.balance)">
              {{ money(event.balance) }}
            </td>
            <td v-if="withTypical" class="text-right money app-text-estimate">
              {{ money(event.with_typical) }}
            </td>
            <td class="text-right" style="width: 48px">
              <q-btn
                flat
                dense
                round
                size="sm"
                color="grey-7"
                icon="open_in_new"
                @click="open(event)"
              >
                <q-tooltip :delay="500" :offset="[0, 6]">{{ openLabel(event) }}</q-tooltip>
              </q-btn>
            </td>
          </tr>
        </tbody>
      </q-markup-table>
      <q-card-section v-else class="text-grey-6"
        >Nothing known in the next {{ upcomingDays }} days.</q-card-section
      >
      <q-card-section
        v-if="withTypical && soonAllowance && !isZero(soonAllowance)"
        class="text-caption text-grey-7 money q-pt-sm"
      >
        Typical spending over these {{ upcomingDays }} days comes to about
        {{ money(soonAllowance) }}, and typical income to {{ money(soonEarned) }}, spread across
        them rather than on any one day, so the balance with both is in the last column.
      </q-card-section>
    </q-card>
  </div>
</template>

<script setup>
const props = defineProps({
  views: { type: Object, default: () => ({}) },
  months: { type: Number, default: 3 },
  horizons: { type: Array, default: () => [3, 6, 12] },
  upcomingDays: { type: Number, default: 30 },
  currencies: { type: Array, default: () => [] },
  base: { type: String, default: 'HKD' },
})

// The currency is the browser's to remember, as the other pages' dropdowns are, and not in
// the URL. The server sends a view of each, so a choice is a lookup. A remembered currency
// the accounts no longer hold falls back to all of them.
const keptCcy = useLocalStorage('forecast.ccy', '')
const ccy = computed({
  get: () => (props.currencies.includes(keptCcy.value) ? keptCcy.value : ''),
  set: value => (keptCcy.value = value || ''),
})
const view = computed(() => props.views[ccy.value || 'all'] ?? {})
const projection = computed(() => view.value.projection ?? [])
const upcoming = computed(() => view.value.upcoming ?? [])
const outlook = computed(() => view.value.outlook ?? [])
const warnings = computed(() => view.value.warnings ?? [])

const currencyOptions = computed(() => [
  { label: `All, in ${props.base}`, value: '', caption: "Converted at today's rate" },
  ...props.currencies.map(code => ({ label: code, value: code, caption: `${code} accounts only` })),
])

// Held in another currency than the one shown, so its own figure is given too.
const foreign = account => account.ccy !== (ccy.value || props.base)

// The horizon, off the URL at its default: the server draws the chart to it.
const visit = ({ months = props.months }) =>
  router.get('/forecast', months === 3 ? {} : { months }, { preserveScroll: true, replace: true })

const money = useMoney()
const formatDate = useCalendarDay()

// Remembered per browser: a view preference, not data anyone else needs.
const withTypical = useLocalStorage('forecast.typical', true)
const showBasis = useLocalStorage('forecast.basis', true)

// The what-if is remembered with the rest of the toolbar, so the page opens as it was left.
// It is not in the URL: a link carries the horizon and the currency, which the server needs,
// and the chart redraws the rest itself.
// The reserve Spare cash keeps back, in months of spending, remembered as the other controls are.
const reserveMonths = useLocalStorage('forecast.reserveMonths', 3)
const monthsLabel = n => `${n} month${n === 1 ? '' : 's'}`

const spendingChange = useLocalStorage('forecast.spendingChange', 0)
const noIncome = useLocalStorage('forecast.noIncome', false)
const irregular = useLocalStorage('forecast.irregular', true)
const whatIf = computed(() => ({
  factor: withTypical.value ? 1 + spendingChange.value / 100 : 0,
  noIncome: noIncome.value,
  irregular: irregular.value,
}))
const whatIfOn = computed(() => spendingChange.value !== 0 || noIncome.value || !irregular.value)
const resetWhatIf = () => {
  spendingChange.value = 0
  noIncome.value = false
  irregular.value = true
}

// Per currency shown: the lowest the balance gets on the horizon, on the path the runway's
// figures use, less the reserve -- a month's spending, typical and the rules' together, as
// the spending box adds it up -- times the months kept. Untouched by the what-if, both are
// the server's decimals, added and multiplied as money. With the what-if on, the low is
// found on the line the chart draws, by the chart's own arithmetic, and a month of reserve
// is scaled as that line's spending is: an estimate of an estimate, so whole units and
// marked as one, as the runway's What if figure is. Nothing to say without a typical month.
const spares = computed(() =>
  projection.value.flatMap(section => {
    if (!section.typical_basis || isZero(section.typical_monthly)) return []

    const typical = withTypical.value
    const months = reserveMonths.value
    const spare = (lowest, date, reserve, left, estimate) => {
      const short = negative(left)
      const amount = short ? String(left).slice(1) : String(left)
      const how = [typical && 'typical spending', estimate && 'the what-if'].filter(Boolean)

      return {
        ccy: section.ccy,
        estimate,
        short,
        amount,
        lowest,
        reserve,
        lowNote: [formatDate(date), how.length ? `with ${how.join(' and ')}` : 'known only'].join(
          ' · ',
        ),
      }
    }

    if (!whatIfOn.value) {
      const low = section.lowest_ahead?.[typical ? 'typical' : 'known']

      if (!low) return []

      // Half months as whole units of a half, so the reserve is still decimal money.
      const reserve = fromUnits(
        (scaled(spendingInAll(section)) * BigInt(Math.round(months * 2))) / 2n,
      )

      return [spare(low.amount, low.date, reserve, minus(low.amount, reserve), false)]
    }

    const { factor } = whatIf.value
    const lineOf = point => {
      const known = Number(point.known) - (noIncome.value ? Number(point.recurring_in) : 0)

      if (!typical) return known

      const earned = noIncome.value ? 0 : Number(point.earned)
      const spent = Number(point.allowance) + (irregular.value ? Number(point.irregular ?? 0) : 0)

      return known - spent * factor + earned
    }

    // After today, as the server's lowest ahead is.
    const low = section.points.slice(1).reduce((best, point) => {
      const value = lineOf(point)

      return best && best.value <= value ? best : { value, date: point.date }
    }, null)

    if (!low) return []

    const basis = section.typical_basis
    const scaledSpending =
      (Number(basis.cash) + Number(basis.card) + (irregular.value ? Number(basis.irregular) : 0)) *
      (typical ? factor : 1)
    const reserve = Math.round((scaledSpending + Number(basis.recurring)) * months)
    const lowest = Math.round(low.value)

    return [spare(String(lowest), low.date, String(reserve), String(lowest - reserve), true)]
  }),
)

// The events the projection placed within the list's reach, with the balance each leaves.
const soon = computed(() => {
  const section = projection.value[0]

  if (!section) return []

  const until = section.points[Math.min(props.upcomingDays, section.points.length - 1)]?.date

  // An expected dividend or bonus is an estimate, so only while the estimates are shown.
  return section.events.filter(
    event => event.date <= until && (withTypical.value || !event.estimate),
  )
})

// A box's total, of its rows as they are shown: each rounded to the cent first, or four-place
// parts summed and then rounded print a total a cent off the rows above it.
const addsUp = values =>
  values.map(value => money(value ?? '0').replaceAll(',', '')).reduce(plus, '0')

// The month the spending box adds up to: the ordinary month and the irregular spending, and those
// with the rules.
const typicalTotal = section =>
  addsUp([section.typical_basis.cash, section.typical_basis.card, section.typical_basis.irregular])
const spendingInAll = section => addsUp([typicalTotal(section), section.typical_basis.recurring])
const incomeTotal = section =>
  addsUp([section.typical_income_basis.spread, ...incomeEstimates(section).map(row => row.value)])

// The estimates on their dates, each a row of the income box where there is one: a bonus row
// of nothing on a horizon no bonus falls in says there is a bonus to expect.
const incomeEstimates = section =>
  [
    { label: 'Dividends, on the dates they paid a year ago', value: section.expected_dividends },
    { label: 'Bonus, on the date it was paid', value: section.expected_bonuses },
    { label: 'Double pay, on the date it was paid', value: section.expected_double_pay },
  ].filter(row => !isZero(row.value))

// The month the forecast starts in, as the whole month: the outlook's figures, what has
// happened since the first and what is still to come. From tomorrow alone it left out a
// salary paid on the first and kept that month's card statements, and drew a month the
// outlook above it called a good one as a loss of 26,000.
const wholeMonth = (month, row) => ({
  ...month,
  whole: true,
  in: plus(row.so_far.income, row.to_come.income),
  out: plus(row.so_far.spending, row.to_come.spending),
  typical_in: row.typical_income_rest,
  typical: row.typical_rest,
  net_known: row.likely_known,
  net_typical: row.likely_net,
})

// And the horizon's last month left off when it is part of one: counted from the first it is
// a single day, a salary and nothing spent. The day is still in the balances and the line.
const endsAMonth = day => {
  const [year, month, date] = day.split('-').map(Number)

  return new Date(Date.UTC(year, month, 0)).getUTCDate() === date
}

const monthsShown = section => {
  const row = outlook.value.find(o => o.ccy === section.ccy)
  const months = section.months.map((month, i) =>
    i === 0 && row?.month === month.month ? wholeMonth(month, row) : month,
  )
  const end = section.points.at(-1)?.date

  return !end || endsAMonth(end) ? months : months.slice(0, -1)
}

// The what-if's end of horizon: an estimate of an estimate, so whole units, never a
// figure to the cent -- the same arithmetic the chart draws its line with.
const whatIfEnd = section => {
  const last = section.points.at(-1)
  const known = Number(last.known) - (noIncome.value ? Number(last.recurring_in) : 0)
  const earned = noIncome.value ? 0 : Number(last.earned)
  const spent = Number(last.allowance) + (irregular.value ? Number(last.irregular ?? 0) : 0)

  return known - spent * whatIf.value.factor + earned
}

const soonPoint = computed(() => {
  const points = projection.value[0]?.points ?? []

  return points[Math.min(props.upcomingDays, points.length - 1)] ?? null
})

// The irregular spending with the ordinary allowance: the column beside it spends both.
const soonAllowance = computed(() =>
  soonPoint.value ? plus(soonPoint.value.allowance, soonPoint.value.irregular ?? '0') : null,
)
const soonEarned = computed(() => soonPoint.value?.earned ?? '0')

// Three months is the default, so it stays off the URL.
const choose = n => visit({ months: n })

// Closing less opening, on the decimal strings: BigInt at four places, so the change shown
// is the exact difference of the two figures beside it and not a float's rounding of it.
const scaled = value => {
  const [, sign, whole, fraction = ''] = String(value ?? '0').match(/^(-?)(\d*)\.?(\d*)$/) ?? []
  const units = BigInt((whole || '0') + fraction.padEnd(4, '0').slice(0, 4))

  return sign ? -units : units
}

// With the estimates shown, an account's end has its expected dividends in it; the known
// closing alone otherwise, as the chart's two lines are.
const expects = account => withTypical.value && !isZero(account.expected)

// What is expected into an account, one line to a kind that is: a double pay is not a dividend.
const expectedKinds = account =>
  [
    { key: 'dividends', label: 'dividends' },
    { key: 'bonuses', label: 'bonus' },
    { key: 'double_pay', label: 'double pay' },
  ].filter(kind => !isZero(account[kind.key]))
const closingOf = account => (expects(account) ? account.closing_expected : account.closing)

const fromUnits = units => {
  const negative = units < 0n
  const digits = (negative ? -units : units).toString().padStart(5, '0')

  return `${negative ? '-' : ''}${digits.slice(0, -4)}.${digits.slice(-4)}`
}

const change = account => fromUnits(scaled(closingOf(account)) - scaled(account.opening))

// The accounts by their own currency, the section's first, the biggest today first within
// each. A group is titled and totalled only when there is more than one: a list of one
// currency needs neither. The totals are the columns' own, in the section's currency.
const accountGroups = section => {
  const byCcy = new Map()

  section.accounts.forEach(account => {
    byCcy.set(account.ccy, [...(byCcy.get(account.ccy) ?? []), account])
  })

  const groups = [...byCcy]
    .sort(([a], [b]) => (a === section.ccy ? -1 : b === section.ccy ? 1 : a.localeCompare(b)))
    .map(([ccy, accounts]) => ({
      ccy,
      titled: byCcy.size > 1,
      accounts: [...accounts].sort((a, b) => {
        const gap = scaled(b.opening) - scaled(a.opening)

        return gap > 0n ? 1 : gap < 0n ? -1 : a.name.localeCompare(b.name)
      }),
    }))

  return groups.map(group => ({
    ...group,
    opening: money(
      fromUnits(group.accounts.reduce((sum, account) => sum + scaled(account.opening), 0n)),
    ),
    closing: money(
      fromUnits(group.accounts.reduce((sum, account) => sum + scaled(closingOf(account)), 0n)),
    ),
  }))
}

const fallsBelowToday = account => Number(account.lowest.amount) < Number(account.opening)

// On the decimal string, not a float.
const isZero = value => /^-?0*(\.0*)?$/.test(String(value ?? '0'))
const negative = value => String(value).startsWith('-') && !isZero(value)

const signClass = value =>
  negative(value) ? 'text-negative' : isZero(value) ? '' : 'text-positive'
const lowClass = value => (negative(value) ? 'text-negative' : '')

const signed = value => (negative(value) ? `−${money(String(value).slice(1))}` : `+${money(value)}`)

// Only the known figures raise a warning: an estimate crossing zero is not a fact.
const allWarnings = computed(() => [
  ...projection.value.flatMap(section =>
    section.accounts
      .filter(account => negative(account.lowest.amount))
      .map(
        account =>
          `${account.name} goes below zero: ${money(account.native.lowest)} ${account.ccy} on ${formatDate(account.lowest.date)}.`,
      ),
  ),
  ...warnings.value,
])

const figures = section => {
  const last = section.points.at(-1)
  const typical = withTypical.value && !isZero(section.typical_monthly)
  const low = section.lowest_ahead?.[typical ? 'typical' : 'known']

  // The estimate first when it is shown: it is the balance there will be. The known one
  // counts every salary and almost nothing spent, so as the headline it promised a figure
  // nobody would reach -- 1,050,464 against a likely 737,872 on a year.
  return [
    ...(typical
      ? [
          {
            label: `In ${props.months} months`,
            value: money(last?.typical),
            class: 'app-text-estimate',
            caption: 'with typical spending',
          },
          {
            label: 'Known only',
            value: money(last?.known),
            class: 'text-grey-7',
            caption: 'no everyday spending',
          },
        ]
      : [{ label: `In ${props.months} months`, value: money(last?.known) }]),
    ...(low
      ? [
          {
            label: 'Lowest ahead',
            value: money(low.amount),
            class: lowClass(low.amount),
            caption: formatDate(low.date),
          },
        ]
      : []),
    ...(typical
      ? [
          {
            label: 'A month',
            value: signed(section.saving_monthly),
            class: signClass(section.saving_monthly),
            caption: 'with typical spending',
          },
        ]
      : []),
    ...(whatIfOn.value
      ? [
          {
            label: 'What if',
            value: `≈ ${money(String(Math.round(whatIfEnd(section))))}`,
            class: 'text-primary',
            caption: `in ${props.months} months`,
          },
        ]
      : []),
    ...(section.runway_months
      ? [
          {
            label: 'Runway',
            value: `${section.runway_months} months`,
            caption: 'at the average spend, no income',
          },
        ]
      : []),
  ]
}

const kinds = {
  scheduled: { label: 'scheduled', class: 'app-tint app-tint--info' },
  pending: { label: 'pending', class: 'app-tint app-tint--warning' },
  recurring: { label: 'recurring', class: 'app-tint app-tint--positive' },
  statement: { label: 'card statement', class: 'app-tint app-tint--negative' },
  'expected dividend': { label: 'expected dividend', class: 'app-tint app-tint--muted' },
  'expected bonus': { label: 'expected bonus', class: 'app-tint app-tint--muted' },
  'expected double pay': { label: 'expected double pay', class: 'app-tint app-tint--muted' },
}

const openLabel = event =>
  event.estimate
    ? "Last year's payment it is expected from"
    : event.link.recurring
      ? 'Open the recurring rules'
      : event.link.card
        ? "This statement's transactions"
        : 'Open the transaction'

const open = event => {
  if (event.link.recurring) return router.visit('/recurring')

  if (event.link.card) {
    return router.visit('/transactions', {
      data: { filter: { account_id: event.link.card, due_date: event.link.due_date } },
    })
  }

  // The row's own day as well as the description, which is a substring match and is not
  // always unique -- every settlement for one card reads "Card payment [NAME]", so without
  // the day this lands on that card's whole payment history rather than the event clicked.
  // link.date is the row's own date, not the day the panel lists it under, because a
  // pending row from an earlier day is listed under today and filtering on that finds
  // nothing. Without it this degrades to the description alone, as it always did.
  return router.visit('/transactions', {
    data: {
      filter: {
        account_id: event.account_id,
        description: event.description,
        date_from: event.link.date,
        date_to: event.link.date,
      },
    },
  })
}
</script>
