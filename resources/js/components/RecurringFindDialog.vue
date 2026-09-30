<template>
  <q-dialog v-model="open" transition-show="jump-down" transition-hide="jump-up" persistent>
    <q-card flat style="width: 860px; max-width: 95vw">
      <q-card-section>
        <div class="row items-center no-wrap q-gutter-x-sm">
          <q-icon name="manage_search" size="sm" color="grey-6" />
          <div class="text-h6 text-grey-9 text-weight-bold">From the transaction history</div>
          <q-space />
          <q-btn flat round color="grey-6" icon="close" @click="open = false" />
        </div>
        <div class="text-caption text-grey-7">
          The last {{ months }} months of cash and card movements. Nothing is written until you
          apply.
        </div>
      </q-card-section>

      <q-separator />

      <!-- How the findings split, before any one of them is read. -->
      <q-card-section class="q-pb-sm">
        <div class="app-find-counts">
          <div
            v-for="tile in tiles"
            :key="tile.verdict"
            class="app-find-count"
            :class="`app-find-count--${tile.verdict}`"
          >
            <div class="text-h5 text-weight-bold">{{ tile.count }}</div>
            <div class="text-caption">{{ tile.label }}</div>
          </div>
        </div>
      </q-card-section>

      <q-card-section class="scroll q-pt-sm app-find-body">
        <!-- What applying would do, one card each, the change said in words. -->
        <div v-if="actions.length" class="column q-gutter-sm">
          <div
            v-for="finding in actions"
            :key="finding.rule_id ?? finding.description"
            class="app-find-item"
            :class="`app-find-item--${finding.verdict}`"
          >
            <div class="row items-start no-wrap">
              <div class="col">
                <div class="row items-center no-wrap q-gutter-x-sm">
                  <q-badge :class="tint(finding.verdict)" :label="label(finding.verdict)" />
                  <span class="text-subtitle2 text-weight-bold text-grey-9 ellipsis">
                    {{ finding.description }}
                  </span>
                </div>
                <div class="text-caption text-grey-7 q-mt-xs">
                  {{ finding.account }} · {{ finding.type
                  }}<span v-if="finding.cadence">, {{ finding.cadence }}</span> ·
                  {{ finding.occurrences }} payment{{ finding.occurrences === 1 ? '' : 's' }}
                </div>
                <div class="text-body2 text-grey-9 q-mt-xs">{{ change(finding) }}</div>
              </div>
              <!-- The figure and day the rule has, and the ones it would have. -->
              <div v-if="finding.amount !== null" class="app-find-figures money text-right">
                <span v-if="finding.rule_amount !== null" class="text-grey-6">
                  {{ money(finding.rule_amount) }}
                  <span class="text-caption">day {{ finding.rule_day }}</span>
                  <q-icon name="arrow_forward" size="xs" class="q-mx-xs" />
                </span>
                <span class="text-weight-bold text-grey-9">{{ money(finding.amount) }}</span>
                <span class="text-caption text-grey-7">
                  day {{ finding.day
                  }}<span v-if="finding.tied_days.length"> or {{ finding.tied_days[1] }}</span>
                </span>
              </div>
            </div>
          </div>
        </div>

        <div v-else class="app-find-clear row items-center no-wrap">
          <q-icon name="check_circle" size="sm" color="positive" class="q-mr-sm" />
          Every rule agrees with the history, and nothing new recurs.
        </div>

        <!-- Nothing to do for these, so a list to look down rather than cards to read. -->
        <q-expansion-item
          v-for="fold in folds"
          :key="fold.verdict"
          dense
          dense-toggle
          :label="fold.label"
          header-class="text-grey-8 text-weight-medium q-px-none q-mt-sm"
          :default-opened="!actions.length && fold.verdict === 'matches'"
        >
          <div class="app-find-quiet">
            <div
              v-for="finding in fold.findings"
              :key="finding.rule_id ?? finding.description"
              class="app-find-quiet__row"
            >
              <span class="text-grey-9 ellipsis">{{ finding.description }}</span>
              <span class="text-grey-6 ellipsis">{{ finding.account }}</span>
              <span class="money text-grey-8 text-right">
                {{ finding.amount === null ? '' : money(finding.amount) }}
              </span>
              <span class="text-grey-6 text-right">
                {{ finding.cadence ?? '' }}{{ finding.day ? `, day ${finding.day}` : '' }}
              </span>
              <span class="text-grey-6 text-right">{{ finding.occurrences }}x</span>
            </div>
          </div>
        </q-expansion-item>
      </q-card-section>

      <q-separator />

      <q-card-actions class="q-pa-md">
        <div class="text-caption text-grey-7">
          {{ summary }}
        </div>
        <q-space />
        <q-btn flat no-caps color="grey-8" label="Close" @click="open = false" />
        <q-btn
          unelevated
          no-caps
          class="text-weight-bold app-btn"
          icon="check"
          label="Apply"
          :loading="applying"
          :disable="!actionable"
          @click="apply"
        >
          <q-tooltip :delay="500" :offset="[0, 6]">
            {{ actionable ? 'Put the rules in line with the history' : 'Nothing to change' }}
          </q-tooltip>
        </q-btn>
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  findings: { type: Array, default: () => [] },
  months: { type: Number, default: 24 },
})

const emit = defineEmits(['update:modelValue'])

const open = computed({
  get: () => props.modelValue,
  set: value => emit('update:modelValue', value),
})

const money = useMoney()

const applying = ref(false)

const labels = {
  new: 'new',
  differs: 'change',
  stopped: 'stopped',
  unclear: 'unclear',
  matches: 'agrees',
}

const tints = {
  new: 'app-tint app-tint--positive',
  differs: 'app-tint app-tint--warning',
  stopped: 'app-tint app-tint--negative',
  unclear: 'app-tint app-tint--muted',
  matches: 'app-tint app-tint--muted',
}

const label = verdict => labels[verdict] ?? verdict
const tint = verdict => tints[verdict] ?? tints.unclear

// A finding in a sentence, because "amount 174.00 to 184.00" is a thing being said about
// money and a column of numbers leaves the reader to work out which way round it goes.
const change = finding => {
  const parts = []

  if (finding.verdict === 'new') {
    return `Adds a ${finding.cadence} rule on day ${finding.day}, from ${finding.start_date}.`
  }

  if (finding.verdict === 'stopped') {
    return finding.last === null
      ? 'No payments in the history. The rule is removed.'
      : `No payment since ${formatDate(finding.last)}. The rule is removed.`
  }

  if (finding.verdict === 'unclear') {
    return `${finding.occurrences} payments, not on a cadence. Nothing to change.`
  }

  if (finding.verdict === 'matches') {
    return 'The rule already says this.'
  }

  if (finding.flags.includes('amount')) {
    parts.push(`${money(finding.rule_amount)} becomes ${money(finding.amount)}`)
  }

  if (finding.flags.includes('day')) {
    const both = finding.tied_days.length > 1 ? ` or ${finding.tied_days[1]}` : ''

    parts.push(`day ${finding.rule_day} becomes day ${finding.day}${both}`)
  }

  return parts.join('. ') + '.'
}

const order = { new: 0, differs: 1, stopped: 2 }

// What applying would change, adding first, then updating, then removing.
const actions = computed(() =>
  props.findings
    .filter(f => f.verdict in order)
    .sort(
      (a, b) => order[a.verdict] - order[b.verdict] || a.description.localeCompare(b.description),
    ),
)

const folds = computed(() =>
  [
    ['matches', 'already agree'],
    ['unclear', 'not on a cadence, so left alone'],
  ]
    .map(([verdict, noun]) => {
      const findings = props.findings
        .filter(f => f.verdict === verdict)
        .sort((a, b) => a.description.localeCompare(b.description))

      return { verdict, findings, label: `${findings.length} ${noun}` }
    })
    .filter(fold => fold.findings.length),
)

const tiles = computed(() =>
  [
    ['new', 'to add'],
    ['differs', 'to update'],
    ['stopped', 'to remove'],
    ['unclear', 'unclear'],
    ['matches', 'agree'],
  ].map(([verdict, label]) => ({
    verdict,
    label,
    count: props.findings.filter(f => f.verdict === verdict).length,
  })),
)

const actionable = computed(() =>
  props.findings.some(f => ['new', 'differs', 'stopped'].includes(f.verdict)),
)

const summary = computed(() => {
  const count = verdict => props.findings.filter(f => f.verdict === verdict).length

  const parts = []

  for (const [verdict, noun] of [
    ['new', 'to add'],
    ['differs', 'to bring up to date'],
    ['stopped', 'to remove'],
  ]) {
    if (count(verdict) > 0) {
      parts.push(`${count(verdict)} ${noun}`)
    }
  }

  return parts.length === 0 ? 'Nothing here needs changing.' : `${parts.join(', ')}.`
})

const formatDate = useCalendarDay()

const apply = () =>
  router.post(
    '/recurring/find/apply',
    {},
    {
      // The apply lands on the first page of a refreshed table, so there is nothing to
      // preserve a scroll position within.
      preserveScroll: false,
      // The button's own spinner -- see app.js.
      showProgress: false,
      onStart: () => (applying.value = true),
      onSuccess: () => {
        applying.value = false
        open.value = false
      },
      // Not on finish: a refusal leaves the dialog open with the report still on screen,
      // rather than closing over a table that has not changed.
      onError: () => (applying.value = false),
    },
  )
</script>
