<template>
  <div class="column q-gutter-lg">
    <!-- The two answers first: what is held, and what is owed, per currency. -->
    <div class="row q-col-gutter-md">
      <div v-for="(total, ccy) in cashTotals" :key="`cash-${ccy}`" class="col-12 col-sm-6 col-md-3">
        <q-card flat bordered class="full-height">
          <q-card-section>
            <div class="row items-center text-grey-7 text-caption">
              <q-icon name="account_balance" size="xs" class="q-mr-xs" />
              Cash held · {{ ccy }}
            </div>
            <div class="text-h5 text-weight-bold q-mt-xs" :class="amountClass(total)">
              {{ money(total) }}
            </div>
          </q-card-section>
        </q-card>
      </div>

      <div v-for="(total, ccy) in owedTotals" :key="`owed-${ccy}`" class="col-12 col-sm-6 col-md-3">
        <q-card flat bordered class="full-height">
          <q-card-section>
            <div class="row items-center text-grey-7 text-caption">
              <q-icon name="credit_card" size="xs" class="q-mr-xs" />
              Owed on cards · {{ ccy }}
            </div>
            <div class="text-h5 text-weight-bold text-negative q-mt-xs">{{ money(total) }}</div>
          </q-card-section>
        </q-card>
      </div>
    </div>

    <div>
      <div class="row items-center q-mb-sm">
        <div class="text-h6 text-weight-medium">Cash accounts</div>
        <q-space />
        <q-btn
          flat
          dense
          no-caps
          color="blue-9"
          label="All accounts"
          @click="router.visit('/accounts')"
        />
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
              <div class="text-h5 text-weight-bold q-mt-md" :class="amountClass(account.balance)">
                {{ money(account.balance) }}
              </div>
              <!-- Shown only while it still holds money; see HomeController. -->
              <q-badge
                v-if="account.status !== 'active'"
                color="grey-3"
                text-color="grey-8"
                class="q-mt-sm"
                :label="`${account.status}, still holding money`"
              />
            </q-card-section>
          </q-card>
        </div>
      </div>
      <div v-else class="text-grey-6">No cash account yet.</div>
    </div>

    <div>
      <div class="row items-center q-mb-sm">
        <div class="text-h6 text-weight-medium">Card statements owing</div>
        <q-space />
        <q-btn
          flat
          dense
          no-caps
          color="blue-9"
          label="Settle on Transactions"
          @click="router.visit('/transactions')"
        />
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
                <q-badge v-bind="dueBadge(statement)" />
              </div>
              <div class="text-caption text-grey-7 q-mt-xs">
                Due {{ formatDate(statement.due_date) }}
              </div>
              <div class="text-h5 text-weight-bold text-negative q-mt-sm">
                {{ money(statement.owed) }}
                <span class="text-subtitle2 text-grey-7">{{ statement.card.ccy }}</span>
              </div>
              <div class="text-caption text-grey-7">
                {{ count(statement.charge_count, 'charge') }} · {{ money(statement.charged) }}
                <template v-if="statement.payment_count">
                  · {{ money(statement.paid) }} paid
                </template>
              </div>
              <!-- A total with pending rows is not final, and cannot be settled yet. -->
              <q-badge
                v-if="statement.pending_count"
                color="amber-9"
                class="q-mt-sm"
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
  cashTotals: { type: Object, default: () => ({}) },
  statements: { type: Array, default: Array },
  owedTotals: { type: Object, default: () => ({}) },
})

const money = useMoney()
const dueBadge = useDueBadge()
const formatDate = useCalendarDay()

const count = (n, noun) => `${n} ${noun}${n === 1 ? '' : 's'}`

// Red only when a cash balance has gone below zero, an overdraft.
const amountClass = value => (String(value).startsWith('-') ? 'text-negative' : 'text-grey-9')
</script>
