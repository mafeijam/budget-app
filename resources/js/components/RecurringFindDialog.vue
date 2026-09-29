<template>
  <q-dialog v-model="open" transition-show="jump-down" transition-hide="jump-up" persistent>
    <q-card flat style="width: 1000px; max-width: 95vw">
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

      <q-card-section class="scroll q-pa-none">
        <q-markup-table flat dense>
          <thead>
            <tr class="text-grey-7">
              <th class="text-left">Pattern</th>
              <th class="text-left">Account</th>
              <th class="text-right">Payments</th>
              <th class="text-right">History</th>
              <th class="text-right">The rule</th>
              <th class="text-left">What changes</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="finding in findings"
              :key="finding.rule_id ?? finding.description"
              :class="{ 'text-grey-6': finding.verdict === 'unclear' }"
            >
              <td>
                <div class="row items-center no-wrap q-gutter-xs">
                  <q-badge :class="tint(finding.verdict)" :label="label(finding.verdict)" />
                  <span class="text-weight-medium text-grey-9">{{ finding.description }}</span>
                </div>
                <div class="text-caption text-grey-6">
                  {{ finding.type }}<span v-if="finding.cadence">, {{ finding.cadence }}</span>
                </div>
              </td>
              <td class="text-grey-8">{{ finding.account }}</td>
              <td class="text-right">
                {{ finding.occurrences }}<span class="text-grey-6">x</span>
              </td>
              <td class="text-right money text-grey-9">
                {{ finding.amount === null ? '' : money(finding.amount) }}
                <div class="text-caption text-grey-6">
                  day {{ finding.day }}
                  <span v-if="finding.tied_days.length"> or {{ finding.tied_days[1] }}</span>
                </div>
              </td>
              <td class="text-right money text-grey-7">
                <template v-if="finding.rule_amount !== null">
                  {{ money(finding.rule_amount) }}
                  <div class="text-caption">day {{ finding.rule_day }}</div>
                </template>
              </td>
              <td class="text-grey-8">{{ change(finding) }}</td>
            </tr>
          </tbody>
        </q-markup-table>
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
