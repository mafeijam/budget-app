<template>
  <!-- As the form dialog opens and closes, and kept open on a stray click once typed in. -->
  <q-dialog
    v-model="open"
    transition-show="jump-down"
    transition-hide="jump-up"
    :no-backdrop-dismiss="form.isDirty"
    :no-esc-dismiss="form.isDirty"
  >
    <q-card flat class="card-form-dialog app-dialog--narrow">
      <q-card-section class="row items-start no-wrap">
        <q-icon
          :name="exchange ? 'currency_exchange' : 'sync_alt'"
          size="sm"
          color="grey-6"
          class="q-mr-sm q-mt-xs"
        />
        <div>
          <div class="text-h6 text-grey-9 text-weight-bold">
            {{ editingId ? 'Edit' : 'New' }} {{ exchange ? 'exchange' : 'transfer' }}
          </div>
          <div class="text-caption text-grey-7">
            Out of one cash account and into another: neither income nor spending.
          </div>
        </div>
        <q-space />
        <q-btn flat round color="grey-6" icon="close" @click="open = false" />
      </q-card-section>

      <q-form class="q-px-md" @submit="submit">
        <!-- Plain cols, not col-sm: the breakpoints follow the screen, not this narrow dialog. -->
        <div class="row q-col-gutter-md items-start">
          <!-- The two accounts on a line of their own, the swap between them. -->
          <div class="col-12">
            <div class="row items-start no-wrap app-transfer__accounts">
              <q-select
                v-model="form.from_account_id"
                :options="accountOptions"
                class="col"
                label="From"
                outlined
                emit-value
                map-options
                :error="!!form.errors.from_account_id"
                :error-message="form.errors.from_account_id"
              >
                <template #prepend>
                  <q-icon name="account_balance" color="grey-6" />
                </template>
                <template #option="scope">
                  <q-item-label
                    v-if="scope.opt.heading"
                    header
                    class="app-filter-group q-py-xs"
                    v-bind="scope.itemProps"
                  >
                    {{ scope.opt.label }}
                  </q-item-label>
                  <q-item v-else v-bind="scope.itemProps" dense>
                    <q-item-section>{{ scope.opt.label }}</q-item-section>
                    <q-item-section side class="text-caption">{{ scope.opt.ccy }}</q-item-section>
                  </q-item>
                </template>
              </q-select>

              <div class="col-auto q-pt-md">
                <q-btn flat round dense icon="swap_horiz" color="grey-7" @click="swap">
                  <q-tooltip :delay="500" :offset="[0, 6]">Swap the two accounts</q-tooltip>
                </q-btn>
              </div>

              <q-select
                v-model="form.to_account_id"
                :options="accountOptions"
                class="col"
                label="To"
                outlined
                emit-value
                map-options
                :error="!!form.errors.to_account_id"
                :error-message="form.errors.to_account_id"
              >
                <template #prepend>
                  <q-icon name="account_balance" color="grey-6" />
                </template>
                <template #option="scope">
                  <q-item-label
                    v-if="scope.opt.heading"
                    header
                    class="app-filter-group q-py-xs"
                    v-bind="scope.itemProps"
                  >
                    {{ scope.opt.label }}
                  </q-item-label>
                  <q-item
                    v-else
                    v-bind="scope.itemProps"
                    dense
                    :disable="scope.opt.value === form.from_account_id"
                  >
                    <q-item-section>{{ scope.opt.label }}</q-item-section>
                    <q-item-section side class="text-caption">{{ scope.opt.ccy }}</q-item-section>
                  </q-item>
                </template>
              </q-select>
            </div>
          </div>

          <!-- The amount reads largest with its currency in front, as the transaction
               form's does; the received figure the same when the accounts differ. -->
          <q-input
            v-model="form.amount"
            :class="exchange ? 'col-6 app-form-amount' : 'col-12 app-form-amount'"
            :label="exchange ? 'Sent' : 'Amount'"
            outlined
            type="number"
            step="0.01"
            min="0"
            :prefix="fromPrefix"
            :error="!!form.errors.amount"
            :error-message="form.errors.amount"
          />

          <!-- Only between currencies: the second figure is the user's, never worked out from
               a rate, which would leave the other balance off by what the bank charged. -->
          <q-input
            v-if="exchange"
            v-model="form.amount_in"
            class="col-6 app-form-amount"
            label="Received"
            outlined
            type="number"
            step="0.01"
            min="0"
            :prefix="toPrefix"
            :hint="rateHint"
            :error="!!form.errors.amount_in"
            :error-message="form.errors.amount_in"
          />

          <q-input
            v-model="form.description"
            class="col-12"
            label="Description"
            outlined
            :hint="form.description || !defaultDescription ? '' : `Empty: ${defaultDescription}`"
            :error="!!form.errors.description"
            :error-message="form.errors.description"
          >
            <template #prepend>
              <q-icon name="notes" color="grey-6" />
            </template>
          </q-input>

          <!-- The calendar control every date here uses: a mask on q-input itself breaks. -->
          <q-input
            v-model="form.date"
            class="col-5"
            label="Date"
            outlined
            :error="!!form.errors.date"
            :error-message="form.errors.date"
          >
            <template #append>
              <q-btn flat dense icon="event" rounded>
                <q-menu ref="dateMenu" :offset="[10, 15]" anchor="bottom right" self="top right">
                  <q-date
                    :model-value="form.date"
                    mask="YYYY-MM-DD"
                    minimal
                    color="primary"
                    @update:model-value="pickDate"
                  />
                </q-menu>
              </q-btn>
            </template>
          </q-input>

          <!-- The transaction form's status control, so the two read alike. -->
          <q-field
            class="col-7 app-segment"
            borderless
            hint="Pending moves neither balance until posted"
            :error="!!form.errors.status"
            :error-message="form.errors.status"
          >
            <template #control>
              <div class="app-segment__box">
                <div class="app-segment__label">Status</div>
                <q-btn-toggle
                  v-model="form.status"
                  :options="statusButtons"
                  class="app-segment__buttons"
                  spread
                  unelevated
                  no-caps
                  color="white"
                  text-color="grey-8"
                  toggle-color="blue-1"
                  toggle-text-color="primary"
                />
              </div>
            </template>
          </q-field>
        </div>

        <q-card-actions class="q-px-none q-py-md">
          <q-space />
          <q-btn
            v-if="form.isDirty"
            class="q-mr-md"
            color="grey-6 text-weight-bold"
            padding="sm md"
            flat
            no-caps
            icon="restart_alt"
            label="Reset"
            @click="(form.reset(), form.clearErrors())"
          />
          <q-btn
            type="submit"
            class="text-weight-bold app-btn app-btn--positive"
            padding="sm md"
            unelevated
            no-caps
            icon="check"
            label="Submit"
            :loading="form.processing"
          />
        </q-card-actions>
      </q-form>
    </q-card>
  </q-dialog>
</template>

<script setup>
// Given by the Add menu, which opens this over any page; on Transactions, the page's own.
const props = defineProps({
  accounts: { type: Array, default: null },
  today: { type: String, default: null },
})

const page = usePage()

const open = ref(false)
const editingId = ref(null)
const dateMenu = ref(null)

const blank = () => ({
  from_account_id: null,
  to_account_id: null,
  // The server's day, as the transaction form is seeded, not the browser's.
  date: props.today ?? page.props.formEmpty?.date ?? null,
  amount: '',
  amount_in: '',
  description: '',
  status: 'posted',
})

const form = useForm(blank())

const statusButtons = [
  { label: 'Pending', value: 'pending' },
  { label: 'Posted', value: 'posted' },
]

// Cash accounts only: a card is paid by settling its statement, a brokerage by a trade.
const accounts = computed(() =>
  (props.accounts ?? page.props.filterOptions?.accounts ?? []).filter(
    account => account.type === 'cash',
  ),
)

// QSelect has no grouped options, so each currency is a disabled heading above its
// accounts, as the transaction form groups its accounts under their type.
const accountOptions = computed(() => {
  const currencies = [...new Set(accounts.value.map(account => account.ccy))]

  return currencies.flatMap(ccy => [
    { label: ccy, value: `ccy:${ccy}`, disable: true, heading: true },
    ...accounts.value.filter(account => account.ccy === ccy),
  ])
})

const from = computed(() => accounts.value.find(a => a.value === form.from_account_id) ?? null)
const to = computed(() => accounts.value.find(a => a.value === form.to_account_id) ?? null)
const exchange = computed(() => !!from.value && !!to.value && from.value.ccy !== to.value.ccy)

// The currency in front of each figure, as the transaction form's amount has it;
// nothing until its account is picked.
const fromPrefix = computed(() => (from.value?.ccy ? `${from.value.ccy} ` : ''))
const toPrefix = computed(() => (to.value?.ccy ? `${to.value.ccy} ` : ''))

// For reading only, as the cash flow page's shares are: the figures sent are the strings typed.
const rateHint = computed(() => {
  const sent = Number(form.amount)
  const got = Number(form.amount_in)

  if (!(sent > 0 && got > 0)) return `How much arrived, in ${to.value?.ccy ?? 'its currency'}`

  return `1 ${from.value.ccy} = ${(got / sent).toPrecision(4)} ${to.value.ccy}`
})

// What the server writes when the description is left empty; see Transfer::descriptions().
const defaultDescription = computed(() => {
  if (!from.value || !to.value) return ''

  return exchange.value
    ? `EXCHANGE ${from.value.ccy} TO ${to.value.ccy} ${form.amount_in || '…'}`
    : `TRANSFER TO ${to.value.label.toUpperCase()}`
})

const swap = () => {
  ;[form.from_account_id, form.to_account_id] = [form.to_account_id, form.from_account_id]
}

// '120.5000' to '120.5' on the string: money here is never a float.
const plain = value => {
  const text = String(value)

  return text.includes('.') ? text.replace(/0+$/, '').replace(/\.$/, '') : text
}

const pickDate = value => {
  form.date = value
  dateMenu.value?.hide()
}

/**
 * Fresh, or on a transfer: either half opens it, with the withdrawal as From. A description
 * only the default would have given is left empty, so editing the accounts rewrites it.
 */
const show = (row = null, other = null) => {
  form.clearErrors()

  if (!row || !other) {
    editingId.value = null
    form.defaults(blank())
    form.reset()
    open.value = true

    return
  }

  const [out, into] = row.type === 'withdraw' ? [row, other] : [other, row]
  const outAccount = row.type === 'withdraw' ? row.account_id : other.account_id
  const inAccount = row.type === 'withdraw' ? other.account_id : row.account_id

  editingId.value = row.id
  form.defaults({
    from_account_id: outAccount,
    to_account_id: inAccount,
    date: row.date,
    amount: plain(out.amount),
    amount_in: plain(into.amount),
    description: /^(TRANSFER (TO|FROM) |EXCHANGE )/i.test(row.description) ? '' : row.description,
    status: row.status ?? 'posted',
  })
  form.reset()
  open.value = true
}

const submit = () => {
  const options = {
    preserveScroll: true,
    onSuccess: () => {
      open.value = false
      notifySuccess()
    },
  }

  // Sent as typed; between one currency's accounts the server takes the one amount as both.
  const payload = data => ({ ...data, amount_in: exchange.value ? data.amount_in : null })

  form.transform(payload)

  if (editingId.value) form.put(`/transfers/${editingId.value}`, options)
  else form.post('/transfers', options)
}

defineExpose({ show })
</script>

<style scoped>
.app-transfer__accounts {
  gap: 8px;
}
</style>
