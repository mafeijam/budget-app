<template>
  <div class="column no-wrap q-gutter-md">
    <FormRecurring :options="options" />

    <div>
      <div class="row items-center">
        <div class="text-h6 text-weight-medium q-mr-md">Recurring</div>
        <q-space />
        <div class="row items-center q-gutter-sm no-wrap">
          <RecurringFindDialog v-model="findOpen" :findings="findings ?? []" />
          <!-- The two runs as one toolbar, as the other pages keep their controls. -->
          <div class="app-toolbar row items-center no-wrap">
            <q-btn
              flat
              no-caps
              color="grey-9"
              padding="xs md"
              class="text-weight-medium"
              icon="manage_search"
              label="Find from history"
              :loading="finding"
              @click="find"
            >
              <q-tooltip :delay="500" :offset="[0, 6]">
                Look for recurring payments in the last two years, and put the rules in line with
                what they say
              </q-tooltip>
            </q-btn>

            <q-separator vertical inset class="q-mx-xs" />

            <q-btn
              flat
              no-caps
              color="grey-9"
              padding="xs md"
              class="text-weight-medium"
              icon="play_arrow"
              label="Run now"
              :loading="running"
              @click="runNow"
            >
              <q-tooltip :delay="500" :offset="[0, 6]">
                Record everything due through today, as the nightly run does
              </q-tooltip>
            </q-btn>
          </div>
          <CreateBtn label="New rule" />
        </div>
      </div>
      <div class="text-caption text-grey-7 q-mt-xs">
        Each is recorded as a pending transaction on the day it falls due, and checked against the
        last two years of history. Click a rule for its transactions.
      </div>
    </div>

    <div
      v-for="code in costs.unconverted ?? []"
      :key="code"
      class="app-note app-note--warning row no-wrap"
    >
      <q-icon name="warning_amber" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
      <div>No {{ code }} rate for today, so {{ code }} rules are left out of the totals.</div>
    </div>

    <!-- What the rules come to a month, and when in the next 30 days each one lands. -->
    <q-card flat bordered>
      <q-card-section>
        <div class="app-recurring-figures">
          <div v-for="figure in figures" :key="figure.label">
            <div class="text-caption text-grey-7">{{ figure.label }}</div>
            <div class="text-h6 text-weight-bold money" :class="figure.class">
              {{ figure.value }}
            </div>
            <div class="text-caption text-grey-6 money">{{ figure.note }}</div>
          </div>
        </div>
      </q-card-section>

      <q-separator />

      <q-card-section>
        <div class="text-caption text-grey-7 q-mb-xs">The next 30 days</div>
        <div class="app-recurring-strip">
          <div
            v-for="day in strip"
            :key="day.date"
            class="app-recurring-strip__day"
            :class="{ 'app-recurring-strip__day--month': day.first }"
          >
            <div class="app-recurring-strip__dots">
              <span
                v-for="rule in day.rules"
                :key="rule.id"
                class="app-recurring-strip__dot"
                :style="{ background: groupColours[costs.rules[rule.id]?.group] }"
              />
            </div>
            <div class="app-recurring-strip__label">{{ day.label }}</div>
            <q-tooltip v-if="day.rules.length" :offset="[0, 6]">
              <div class="text-weight-bold">{{ formatDay(day.date) }}</div>
              <div v-for="rule in day.rules" :key="rule.id" class="money">
                {{ rule.description }} · {{ signedAmount(rule) }}
              </div>
            </q-tooltip>
          </div>
        </div>
        <div class="row q-gutter-md text-caption text-grey-7 q-mt-xs">
          <div v-for="group in groupOrder" :key="group" class="row items-center no-wrap">
            <span
              class="app-recurring-strip__dot q-mr-xs"
              :style="{ background: groupColours[group] }"
            />
            {{ groupTitles[group] }}
          </div>
        </div>
      </q-card-section>
    </q-card>

    <q-card v-for="section in sections" :key="section.key" flat bordered>
      <q-card-section class="row items-center no-wrap q-py-sm">
        <q-icon :name="section.icon" size="sm" color="grey-7" class="q-mr-sm" />
        <div class="text-subtitle1 text-weight-medium">{{ section.title }}</div>
        <div class="text-caption text-grey-6 q-ml-sm">{{ section.rules.length }}</div>
        <q-space />
        <div v-if="section.key === 'annual' && section.yearly" class="text-right">
          <div class="money text-weight-bold">{{ base }} {{ money(section.yearly) }} a year</div>
          <div class="text-caption text-grey-6 money">{{ money(section.total) }} a month</div>
        </div>
        <div v-else-if="section.total" class="text-right">
          <div class="money text-weight-bold">{{ base }} {{ money(section.total) }} a month</div>
          <div class="text-caption text-grey-6 money">{{ money(section.yearly) }} a year</div>
        </div>
      </q-card-section>

      <q-separator />

      <div
        v-for="rule in section.rules"
        :key="rule.id"
        class="app-recurring-row cursor-pointer"
        :class="{ 'app-recurring-row--paused': !isRunning(rule) }"
        @click="openTransactions(rule)"
      >
        <div class="app-recurring-row__name">
          <div class="row items-center no-wrap">
            <q-icon
              :name="accountOf(rule)?.type === 'card' ? 'credit_card' : 'account_balance'"
              size="xs"
              color="grey-6"
              class="q-mr-xs"
            />
            <span class="text-weight-medium text-grey-9 ellipsis">{{ rule.description }}</span>
            <q-badge
              v-if="!isRunning(rule)"
              class="app-tint app-tint--muted q-ml-sm"
              :label="rule.active ? 'ended' : 'paused'"
            />
          </div>
          <div class="text-caption text-grey-6 ellipsis">
            {{
              [categoryName(rule), accountOf(rule)?.label, schedule(rule)]
                .filter(Boolean)
                .join(' · ')
            }}
          </div>
        </div>

        <div class="app-recurring-row__next">
          <template v-if="isRunning(rule) && nextDates[rule.id]">
            <div class="text-grey-9">{{ formatDay(nextDates[rule.id]) }}</div>
            <q-badge v-bind="countdown(nextDates[rule.id])" />
          </template>
        </div>

        <!-- What the history says of the rule, and the fix for it where it has drifted. -->
        <div class="app-recurring-row__health text-caption" @click.stop>
          <template v-if="health[rule.id]?.verdict === 'differs'">
            <q-badge class="app-tint app-tint--warning" :label="driftLabel(rule)" />
            <q-btn
              flat
              dense
              no-caps
              size="sm"
              color="primary"
              label="Use it"
              class="q-ml-xs"
              :loading="adopting === rule.id"
              @click="adopt(rule)"
            >
              <q-tooltip :delay="500" :offset="[0, 6]">
                Set the rule to what the history shows
              </q-tooltip>
            </q-btn>
          </template>
          <template v-else-if="health[rule.id]?.verdict === 'stopped'">
            <q-badge
              class="app-tint app-tint--negative"
              :label="`no payment since ${formatDay(health[rule.id].last)}`"
            />
            <q-btn
              flat
              dense
              no-caps
              size="sm"
              color="negative"
              label="Delete"
              class="q-ml-xs"
              :loading="adopting === rule.id"
              @click="adopt(rule)"
            >
              <q-tooltip :delay="500" :offset="[0, 6]">
                The payments stopped, so delete the rule; what it recorded stays
              </q-tooltip>
            </q-btn>
          </template>
          <span v-else-if="health[rule.id]?.last" class="text-grey-6">
            last paid {{ formatDay(health[rule.id].last) }}
          </span>
          <span v-else class="text-grey-5">not in the history yet</span>
        </div>

        <div class="app-recurring-row__amount">
          <div class="money text-weight-medium" :class="amountClass(rule)">
            {{ signedAmount(rule) }}
          </div>
          <div
            v-if="rule.frequency === 'monthly' && costs.rules[rule.id]?.yearly"
            class="text-caption text-grey-6 money"
          >
            {{ money(costs.rules[rule.id].yearly) }} a year
          </div>
        </div>

        <!-- Stopped, so an edit or a delete does not also open the transactions. -->
        <div class="app-recurring-row__actions row items-center justify-end no-wrap" @click.stop>
          <AppTableActions :cell="{ row: rule }" />
        </div>
      </div>
    </q-card>

    <q-card v-if="!sections.length" flat bordered class="q-pa-lg text-center text-grey-7">
      No recurring rules yet. Find them from history, or add one.
    </q-card>
  </div>
</template>

<script setup>
const props = defineProps({
  ...hasTableProps,
  options: { type: Object, default: Object },
  nextDates: { type: Object, default: Object },
  findings: { type: Array, default: null },
  // Rule id => {group, monthly, yearly} in the base currency, and each group's total.
  costs: { type: Object, default: () => ({ rules: {}, totals: {}, unconverted: [] }) },
  // Rule id => what the history scan says of it.
  health: { type: Object, default: () => ({}) },
  base: { type: String, default: 'HKD' },
})

const pagination = usePagination()
provide('pagination', pagination)

const running = ref(false)
const finding = ref(false)
const findOpen = ref(false)
const adopting = ref(null)

// The scan comes back with the page rather than in a message, so the dialog opens on it.
// Nothing on any other visit, so a page reload does not reopen a dialog nobody asked for.
watch(
  () => props.findings,
  found => {
    if (found?.length) findOpen.value = true
  },
  { immediate: true },
)

const find = () =>
  router.post(
    '/recurring/find',
    {},
    {
      preserveScroll: true,
      // The button's own spinner -- see app.js.
      showProgress: false,
      onStart: () => (finding.value = true),
      onFinish: () => (finding.value = false),
    },
  )

const runNow = () =>
  router.post(
    '/recurring/run',
    {},
    {
      preserveScroll: true,
      // The button's own spinner -- see app.js.
      showProgress: false,
      onStart: () => (running.value = true),
      onSuccess: () => notifySuccess(),
      onFinish: () => (running.value = false),
    },
  )

// One rule put in line with its history; the server scans again rather than trusting this.
const adopt = rule =>
  router.post(
    `/recurring/${rule.id}/adopt`,
    {},
    {
      preserveScroll: true,
      showProgress: false,
      onStart: () => (adopting.value = rule.id),
      onSuccess: () => notifySuccess(),
      onFinish: () => (adopting.value = null),
    },
  )

const formatDay = useCalendarDay()
const money = useMoney()

// Hong Kong's day, as the server counts it, not the browser's.
const today = computed(() =>
  new Intl.DateTimeFormat('en-CA', { timeZone: usePage().props.tz }).format(new Date()),
)

const accountOf = rule => props.options?.accounts?.find(a => a.value === rule.account_id)
const categoryName = rule =>
  props.options?.categories?.find(c => c.value === rule.category_id)?.label ?? null

const schedule = rule => {
  const [, month, day] = rule.start_date.split('-')

  const repeats =
    rule.frequency === 'yearly' ? `yearly on ${month}-${day}` : `monthly on day ${Number(day)}`

  return rule.end_date ? `${repeats}, until ${formatDay(rule.end_date)}` : repeats
}

// Running: active, and not past its end date. Anything else sits in its own section.
const isRunning = rule => rule.active && (!rule.end_date || rule.end_date >= today.value)

const groupOrder = ['income', 'bills', 'cards']
const groupTitles = { income: 'Income', bills: 'Bills', cards: 'Card charges' }
const groupIcons = { income: 'savings', bills: 'receipt_long', cards: 'credit_card' }
// The app's positive and negative for money in and out, and the categorical orange for the
// card charges, so the two kinds of money out tell apart on the strip.
const groupColours = { income: '#059669', bills: '#dc2626', cards: '#eb6834' }

const groupOf = rule => props.costs.rules?.[rule.id]?.group ?? 'bills'

// A yearly bill or charge, listed apart since a year apart it is easy to forget; a yearly
// income stays with the income. The server's `annual` total is the same rules.
const isAnnual = rule => rule.frequency === 'yearly' && groupOf(rule) !== 'income'

// Soonest first within a group, so the next thing to leave the account is at the top.
const bySoonest = (a, b) =>
  (props.nextDates[a.id] ?? '9999').localeCompare(props.nextDates[b.id] ?? '9999') ||
  a.description.localeCompare(b.description)

const sections = computed(() => {
  const rules = props.data.data

  const running = groupOrder
    .map(group => ({
      key: group,
      title: groupTitles[group],
      icon: groupIcons[group],
      total: props.costs.totals?.[group] ?? null,
      yearly: props.costs.yearly?.[group] ?? null,
      rules: rules
        .filter(rule => isRunning(rule) && !isAnnual(rule) && groupOf(rule) === group)
        .sort(bySoonest),
    }))
    .concat({
      key: 'annual',
      title: 'Yearly',
      icon: 'event_repeat',
      total: props.costs.totals?.annual ?? null,
      yearly: props.costs.yearly?.annual ?? null,
      rules: rules.filter(rule => isRunning(rule) && isAnnual(rule)).sort(bySoonest),
    })
    .filter(section => section.rules.length)

  const idle = rules.filter(rule => !isRunning(rule))

  return idle.length
    ? [
        ...running,
        {
          key: 'idle',
          title: 'Paused or ended',
          icon: 'pause_circle',
          total: null,
          rules: [...idle].sort((a, b) => a.description.localeCompare(b.description)),
        },
      ]
    : running
})

const figures = computed(() => {
  const totals = props.costs.totals ?? {}

  return [
    {
      label: 'Income a month',
      value: `${props.base} ${money(totals.income ?? '0')}`,
      class: 'text-positive',
      note: 'from the rules that pay in',
    },
    {
      label: 'Bills a month',
      value: `${props.base} ${money(totals.bills ?? '0')}`,
      class: 'text-negative',
      note: 'cash going out',
    },
    {
      label: 'Card charges a month',
      value: `${props.base} ${money(totals.cards ?? '0')}`,
      class: 'text-negative',
      note: `${money(props.costs.yearly?.cards ?? '0')} a year`,
    },
    {
      label: 'Left a month',
      value: `${props.base} ${money(totals.net ?? '0')}`,
      class: String(totals.net).startsWith('-') ? 'text-negative' : 'text-grey-9',
      note: 'income less both, before everyday spending',
    },
  ]
})

const signedAmount = rule =>
  `${groupOf(rule) === 'income' ? '+' : '−'}${money(rule.amount)} ${rule.ccy}`

const amountClass = rule => (groupOf(rule) === 'income' ? 'text-positive' : 'text-grey-9')

// Calendar days between two date strings, at noon UTC so no daylight change can shift them.
const daysBetween = (from, to) =>
  Math.round((new Date(`${to}T12:00:00Z`) - new Date(`${from}T12:00:00Z`)) / 86400000)

const countdown = date => {
  const days = daysBetween(today.value, date)

  if (days < 0) return { class: 'app-tint app-tint--warning', label: 'overdue' }
  if (days === 0) return { class: 'app-tint app-tint--warning', label: 'today' }
  if (days <= 7) {
    return { class: 'app-tint app-tint--info', label: `in ${days} day${days === 1 ? '' : 's'}` }
  }

  return { class: 'app-tint app-tint--muted', label: `in ${days} days` }
}

const driftLabel = rule => {
  const found = props.health[rule.id]
  const parts = []

  if (found.flags.includes('amount')) parts.push(`history ${money(found.amount)}`)
  if (found.flags.includes('day')) parts.push(`on day ${found.day}`)

  return parts.join(' ') || 'differs from history'
}

const shortDay = new Intl.DateTimeFormat('en', { day: 'numeric', timeZone: 'UTC' })
const shortMonth = new Intl.DateTimeFormat('en', { month: 'short', timeZone: 'UTC' })

// Today and the 30 days after it, each with the running rules next due on it.
const strip = computed(() => {
  const start = new Date(`${today.value}T12:00:00Z`)

  return Array.from({ length: 31 }, (_, i) => {
    const day = new Date(start.getTime() + i * 86400000)
    const date = day.toISOString().slice(0, 10)
    const first = i === 0 || date.endsWith('-01')

    return {
      date,
      first,
      label: first
        ? `${shortDay.format(day)} ${shortMonth.format(day)}`
        : i % 7 === 0
          ? shortDay.format(day)
          : '',
      rules: props.data.data.filter(rule => isRunning(rule) && props.nextDates[rule.id] === date),
    }
  })
})

// A rule's own rows: its account, and its description, which is what the recorder writes.
const openTransactions = rule =>
  router.visit('/transactions', {
    data: { filter: { account_id: rule.account_id, description: rule.description } },
  })
</script>
