<template>
  <div class="column no-wrap q-gutter-lg">
    <div>
      <div class="row items-center q-mb-sm">
        <div class="text-h6 text-weight-medium">Cash accounts</div>
      </div>

      <div v-if="cash.length" class="row q-col-gutter-md">
        <div v-for="account in cash" :key="account.id" class="col-12 col-sm-6 col-md-4 col-lg-3">
          <q-card flat bordered class="full-height">
            <q-card-section>
              <div class="row items-center no-wrap">
                <q-icon name="account_balance" size="sm" color="grey-6" class="q-mr-sm" />
                <div class="text-subtitle1 text-weight-medium ellipsis">{{ account.name }}</div>
                <q-space />
                <q-badge outline color="grey-7" :label="account.ccy" />
              </div>
              <div
                class="text-h4 text-weight-bold money q-mt-md"
                :class="amountClass(account.balance)"
              >
                {{ money(account.balance) }}
              </div>
              <!-- Shown only while it still holds money; see HomeController. -->
              <q-badge
                v-if="account.status !== 'active'"
                class="q-mt-sm app-tint app-tint--muted"
                :label="`${account.status}, still holding money`"
              />
            </q-card-section>
          </q-card>
        </div>
      </div>
      <div v-else class="text-grey-6">No cash account yet.</div>
    </div>

    <div v-if="brokerages.length">
      <div class="row items-center q-mb-sm">
        <div class="text-h6 text-weight-medium">Brokerages</div>
      </div>

      <div class="row q-col-gutter-md">
        <div
          v-for="broker in brokerages"
          :key="broker.id"
          class="col-12 col-sm-6 col-md-4 col-lg-3"
        >
          <q-card flat bordered class="full-height">
            <q-card-section>
              <div class="row items-center no-wrap">
                <q-icon name="show_chart" size="sm" color="grey-6" class="q-mr-sm" />
                <div class="text-subtitle1 text-weight-medium ellipsis">{{ broker.name }}</div>
                <q-space />
                <q-badge outline color="grey-7" :label="broker.ccy" />
              </div>
              <!-- Market value, not a balance: what the holdings are worth at the latest price. -->
              <div class="text-caption text-grey-7 q-mt-md">Market value</div>
              <div class="text-h4 text-weight-bold text-grey-9 money">
                {{ money(broker.market_value) }}
              </div>
              <div class="text-body2 text-weight-medium" :class="gainClass(broker.unrealised)">
                {{ signed(broker.unrealised) }} unrealised
              </div>
              <div class="text-caption text-grey-7 q-mt-xs">
                {{ count(broker.open, 'holding') }} · cost {{ money(broker.open_cost) }}
                <template v-if="broker.unpriced"> · {{ broker.unpriced }} unpriced</template>
              </div>
            </q-card-section>
          </q-card>
        </div>
      </div>
    </div>

    <div>
      <div class="row items-center q-mb-sm">
        <div class="text-h6 text-weight-medium">Card statements owing</div>
      </div>

      <div v-if="statements.length" class="row q-col-gutter-md">
        <div
          v-for="statement in statements"
          :key="`${statement.card.id}-${statement.due_date}`"
          class="col-12 col-sm-6 col-md-4 col-lg-3"
        >
          <q-card flat bordered class="full-height">
            <q-card-section>
              <div class="row items-center no-wrap">
                <q-icon name="credit_card" size="sm" color="grey-6" class="q-mr-sm" />
                <div class="text-subtitle1 text-weight-medium ellipsis">
                  {{ statement.card.name }}
                </div>
                <q-space />
                <q-badge outline color="grey-7" :label="statement.card.ccy" />
              </div>
              <!-- The day and how far off it is, read together. -->
              <div class="row items-center text-caption text-grey-7 q-mt-xs">
                Due {{ formatDate(statement.due_date) }}
                <q-badge v-bind="dueBadge(statement)" class="q-ml-sm" />
              </div>
              <div class="text-h4 text-weight-bold text-negative money q-mt-sm">
                {{ money(statement.owed) }}
              </div>
              <!--
                The totals only once something is paid: before that, what was charged is the
                figure above, and saying it twice says nothing.
              -->
              <div class="text-caption text-grey-7">
                {{ count(statement.charge_count, 'charge') }}
                <template v-if="statement.payment_count">
                  · {{ money(statement.charged) }} charged · {{ money(statement.paid) }} paid
                </template>
              </div>
              <!-- A total with pending rows is not final, and cannot be settled yet. -->
              <q-badge
                v-if="statement.pending_count"
                class="q-mt-sm app-tint app-tint--warning"
                :label="`${statement.pending_count} not yet posted`"
              />
            </q-card-section>
          </q-card>
        </div>
      </div>
      <div v-else class="text-grey-6">Nothing owed on any card.</div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  cash: { type: Array, default: Array },
  brokerages: { type: Array, default: Array },
  statements: { type: Array, default: Array },
})

const money = useMoney()
const dueBadge = useDueBadge()
const formatDate = useCalendarDay()

const count = (n, noun) => `${n} ${noun}${n === 1 ? '' : 's'}`

// A gain green and a loss red; nothing plain.
const gainClass = value => {
  if (String(value).startsWith('-')) return 'text-negative'

  return /[1-9]/.test(String(value)) ? 'text-positive' : 'text-grey-7'
}

// With its sign, so a gain reads as one rather than as a bare figure.
const signed = value => (String(value).startsWith('-') ? money(value) : `+${money(value)}`)

// Red only when a cash balance has gone below zero, an overdraft.
const amountClass = value => (String(value).startsWith('-') ? 'text-negative' : 'text-grey-9')
</script>
