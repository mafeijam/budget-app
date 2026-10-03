<template>
  <div class="column no-wrap q-gutter-md">
    <FormTransaction :options="options" />

    <TransferDialog ref="transferDialog" :accounts="options.accounts ?? []" />

    <SimpleFilters
      :filter="filter"
      :options="filterOptions"
      :status-options="statusOptions ?? []"
    />

    <!-- The URL stays as it was opened, so a save, which comes back to it, lands on the list
         from its top rather than on the last page scrolled in, alone. -->
    <InfiniteScroll data="data" preserve-url :buffer="400" :params="{ only: ['editLocks'] }">
      <div class="column no-wrap q-gutter-md">
        <q-card v-for="day in days" :key="day.date" flat bordered class="overflow-hidden">
          <q-card-section class="app-card-head">
            {{ day.label }}
          </q-card-section>
          <q-list>
            <!-- Swiped left to delete, and a pending row right to post. A row the server will
                 not delete or post still swipes, to say why, since a row that would not move
                 at all says nothing. -->
            <q-slide-item
              v-for="row in day.rows"
              :key="row.id"
              :left-color="statusLock(row) ? 'grey-7' : 'positive'"
              :right-color="row.refusal ? 'grey-7' : 'negative'"
              class="app-tx-slide"
              @left="swipedRight(row, $event)"
              @right="swiped(row, $event)"
            >
              <template v-if="canPost(row)" #left>
                <div class="row items-center no-wrap q-gutter-x-sm">
                  <q-icon :name="statusLock(row) ? 'block' : 'done'" />
                  <span class="text-weight-bold">{{
                    statusLock(row) ? "Can't post" : 'Post'
                  }}</span>
                </div>
              </template>
              <template #right>
                <div class="row items-center no-wrap q-gutter-x-sm">
                  <span class="text-weight-bold">{{
                    row.refusal ? "Can't delete" : 'Delete'
                  }}</span>
                  <q-icon :name="row.refusal ? 'block' : 'delete'" />
                </div>
              </template>
              <q-item v-ripple clickable class="q-py-sm" @click="edit(row)">
                <q-item-section avatar style="min-width: 40px">
                  <!-- Untinted while pending, as on the full list: it has not moved the balance. -->
                  <span
                    class="app-tx-icon"
                    :class="row.status === 'pending' ? null : `app-tx-icon--${directionName(row)}`"
                  >
                    <q-icon
                      :name="transferOf(row) ? 'sync_alt' : (typeIcons[row.type] ?? 'help_outline')"
                      size="16px"
                    />
                  </span>
                </q-item-section>
                <q-item-section>
                  <q-item-label class="text-weight-medium text-grey-9 ellipsis">
                    {{ row.description }}
                  </q-item-label>
                  <q-item-label caption class="ellipsis">
                    <span v-if="row.status === 'pending'" class="app-tx-pending">Pending · </span>
                    {{ [categoryName(row), row.account_name].filter(Boolean).join(' · ') }}
                  </q-item-label>
                </q-item-section>
                <q-item-section side class="text-right">
                  <q-item-label
                    class="text-weight-bold money text-no-wrap"
                    :class="amountClass(row)"
                  >
                    {{ signed(row) }}
                    <span v-if="row.ccy !== base" class="text-caption">{{ row.ccy }}</span>
                  </q-item-label>
                  <!-- ≈ where it was converted here rather than stated by the card. -->
                  <q-item-label v-if="row.base" caption class="money text-no-wrap">
                    {{ row.base.estimate ? '≈ ' : '' }}{{ base }} {{ money(row.base.amount) }}
                  </q-item-label>
                </q-item-section>
              </q-item>
            </q-slide-item>
          </q-list>
        </q-card>
      </div>

      <template #loading>
        <div class="row justify-center q-py-md">
          <q-spinner-dots color="primary" size="32px" />
        </div>
      </template>
    </InfiniteScroll>

    <div v-if="!days.length" class="text-center text-grey-6 q-py-xl">
      {{ Object.keys(filter).length ? 'Nothing matches these filters.' : 'No transactions yet.' }}
    </div>
  </div>
</template>

<script setup>
import { InfiniteScroll } from '@inertiajs/vue3'
import simple from '../layout-simple.vue'

defineOptions({ layout: simple })

const props = defineProps({
  // The rows, a page at a time: each also carries its direction and its transfer's other half.
  data: { type: Object, default: () => ({ data: [] }) },
  options: { type: Object, default: Object },
  formEmpty: { type: Object, default: Object },
  // The filter as the URL names it, and what the filters choose from.
  filter: { type: Object, default: () => ({}) },
  filterOptions: { type: Object, default: () => ({}) },
  statusOptions: { type: Array, default: Array },
  base: { type: String, default: 'HKD' },
})

// The form saves through useSubmit(), which pages a table when it has one; this list has none.
provide('pagination', null)

const transferDialog = ref(null)

const money = useMoney()

const typeIcons = {
  withdraw: 'shopping_cart',
  deposit: 'move_to_inbox',
  charge: 'credit_card',
  payment: 'task_alt',
  buy: 'trending_up',
  sell: 'trending_down',
  dividend: 'savings',
}

const directionName = row => ({ 1: 'in', '-1': 'out' })[row.direction] ?? 'none'

const signed = row => `${{ 1: '+', '-1': '−' }[row.direction] ?? ''}${money(row.amount)}`

const amountClass = row => {
  if (row.status === 'pending') return 'text-grey-6'

  return { 1: 'text-positive', '-1': 'text-negative' }[row.direction] ?? 'text-grey-9'
}

const categoryName = row =>
  props.options?.categories?.find(category => category.value === row.category_id)?.label ?? ''

// The other half of a transfer; a card payment's or a trade's is an other half too, but those
// edit as one row.
const transferOf = row => (row.linked?.kind === 'transfer' ? row.linked : null)

const { setEdit } = useEdit()

// Without what the list added to it: the form takes the row as its values, and would send
// them back with the save.
const valuesOf = row => {
  const { direction, linked, refusal, base, ...values } = row

  return values
}

const edit = row => {
  if (transferOf(row)) transferDialog.value.show(valuesOf(row), transferOf(row))
  else setEdit(valuesOf(row))
}

const { destroy } = useDestroy()

// Slid back at once: the confirmation is what decides, and a row left open behind a cancelled
// one would read as half deleted.
const swiped = (row, { reset }) => {
  reset()

  if (row.refusal) notifyFailure(row.refusal)
  else destroy(row, row.linked)
}

const { post, canPost } = usePost()

// The edit form's lock, as the table's post button reads it: a settled statement's status is
// fixed, and a post that the save refuses would look, from the list, like nothing happened.
const statusLock = row => {
  const lock = usePage().props.editLocks?.[row.id]

  return lock?.fields.includes('status') ? lock.message : null
}

const swipedRight = (row, { reset }) => {
  reset()

  if (statusLock(row)) notifyFailure(statusLock(row))
  else post(valuesOf(row))
}

// A day's header as words, from the date string alone: read in UTC, the day it names is the
// day it is, where the browser's own zone could move it to the day before. Assembled from the
// parts, since en-GB spells September "Sept" and en-US puts the month first.
const dayParts = new Intl.DateTimeFormat('en-US', {
  weekday: 'short',
  day: 'numeric',
  month: 'short',
  year: 'numeric',
  timeZone: 'UTC',
})

const dayWords = (date, withYear) => {
  const part = Object.fromEntries(
    dayParts.formatToParts(new Date(`${date}T00:00:00Z`)).map(({ type, value }) => [type, value]),
  )

  return [part.weekday, part.day, part.month, withYear ? part.year : null].filter(Boolean).join(' ')
}

// The server's today, which is Hong Kong's: the browser's can be a day either side of it.
const today = computed(() => props.formEmpty?.date ?? null)

const shift = (date, days) => {
  const day = new Date(`${date}T00:00:00Z`)
  day.setUTCDate(day.getUTCDate() + days)

  return day.toISOString().slice(0, 10)
}

const labelOf = date => {
  if (date === today.value) return 'Today'
  if (today.value && date === shift(today.value, -1)) return 'Yesterday'

  return dayWords(date, date.slice(0, 4) !== today.value?.slice(0, 4))
}

// The rows arrive newest first, so a day's are together and the days in order.
const days = computed(() => {
  const groups = []

  for (const row of props.data?.data ?? []) {
    const last = groups[groups.length - 1]

    if (last?.date === row.date) last.rows.push(row)
    else groups.push({ date: row.date, label: labelOf(row.date), rows: [row] })
  }

  return groups
})
</script>
