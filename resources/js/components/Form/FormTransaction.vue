<template>
  <FormDialog :name="$page.props.meta.form" :title="title" @hide-form="resetEdit">
    <q-form :id="$page.props.meta.form" class="row q-col-gutter-md" @submit="submit(target)">
      <!--
        Wrapped, because the gutter pads the column and a banner coloured to its edges
        would sit offset from every field below it.
      -->
      <div v-if="lock" class="col-12">
        <q-banner rounded dense class="bg-amber-1 text-amber-10">
          <template #avatar>
            <q-icon name="lock" color="amber-8" />
          </template>
          {{ lock.message }}
        </q-banner>
      </div>

      <q-select
        v-model="form.account_id"
        :options="accountOptions"
        class="col-6"
        label="Account"
        filled
        emit-value
        map-options
        :disable="locked('account_id')"
        :error="!!form.errors.account_id"
        :error-message="form.errors.account_id"
      />

      <q-select
        v-model="form.type"
        :options="typeOptions"
        :disable="!form.account_id || locked('type')"
        class="col-6"
        label="Type"
        filled
        :error="!!form.errors.type"
        :error-message="form.errors.type"
      />

      <!--
        A Quasar calendar rather than a native control, which renders in the browser's
        locale whatever the app's is -- the visible notation would not be the stored
        one.

        The mask belongs to the q-date and to nothing else: q-input masks through a
        parser whose only token is #, so the same string there is nine literals and the
        field renders "YYYY-MM-DD" and accepts no keystroke. FormContractTest pins this.

        A button in the append slot holding a q-menu, not a q-popup-proxy on the input,
        which anchors the calendar to the field's own box and leaves it open after a day
        is chosen, so the next click lands on the calendar instead of on what was under
        it.
      -->
      <q-input
        v-model="form.date"
        class="col-4"
        label="Date"
        filled
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
                color="green-7"
                @update:model-value="pickDate"
              />
            </q-menu>
          </q-btn>
        </template>
      </q-input>

      <q-input
        v-model="form.amount"
        class="col-4"
        label="Amount"
        filled
        type="number"
        step="0.0001"
        min="0"
        :disable="derivesAmount || locked('amount')"
        :hint="derivesAmount ? 'Derived from quantity and price' : ''"
        :error="!!form.errors.amount"
        :error-message="form.errors.amount"
      />

      <q-select
        v-model="form.ccy"
        :options="currencyOptions"
        class="col-4"
        label="Currency"
        filled
        emit-value
        map-options
        :disable="locked('ccy')"
        :error="!!form.errors.ccy"
        :error-message="form.errors.ccy"
      />

      <q-input
        v-model="form.description"
        class="col-12"
        label="Description"
        filled
        autofocus
        :error="!!form.errors.description"
        :error-message="form.errors.description"
      />

      <q-select
        v-model="form.category_id"
        :options="categoryOptions"
        class="col-6"
        label="Category"
        filled
        emit-value
        map-options
        clearable
        :error="!!form.errors.category_id"
        :error-message="form.errors.category_id"
      />

      <q-select
        v-model="form.status"
        :options="statusOptions"
        class="col-6"
        label="Status"
        filled
        :disable="locked('status')"
        hint="A pending charge does not count toward what the card owes"
        :error="!!form.errors.status"
        :error-message="form.errors.status"
      />

      <!--
        Shown only when it applies rather than always, because it is refused when it
        does not: CardStatement prefers it over `amount`, so a stale figure left from a
        currency the user has since changed back would replace the real amount in what
        the card owes. Clearing it below is the same rule, kept on this side so the
        field disappears rather than sitting there refusing the save.
      -->
      <q-input
        v-if="needsCardAmount"
        v-model="form.meta_data.card_amount"
        class="col-6"
        label="Amount in the card's currency"
        filled
        type="number"
        step="0.0001"
        :disable="locked('meta_data.card_amount')"
        :hint="`What the card owes for this, in ${chosenAccount?.ccy}`"
        :error="!!form.errors['meta_data.card_amount']"
        :error-message="form.errors['meta_data.card_amount']"
      />

      <template v-if="derivesAmount">
        <q-input
          v-model="form.meta_data.symbol"
          class="col-6"
          label="Symbol"
          filled
          :error="!!form.errors['meta_data.symbol']"
          :error-message="form.errors['meta_data.symbol']"
        />
        <q-input
          v-model="form.meta_data.quantity"
          class="col-6"
          label="Quantity"
          filled
          type="number"
          step="0.00000001"
          :error="!!form.errors['meta_data.quantity']"
          :error-message="form.errors['meta_data.quantity']"
        />
        <q-input
          v-model="form.meta_data.unit_price"
          class="col-6"
          label="Unit price"
          filled
          type="number"
          step="0.0001"
          :error="!!form.errors['meta_data.unit_price']"
          :error-message="form.errors['meta_data.unit_price']"
        />
        <q-input
          v-model="form.meta_data.fees"
          class="col-6"
          label="Fees"
          filled
          type="number"
          step="0.0001"
          :error="!!form.errors['meta_data.fees']"
          :error-message="form.errors['meta_data.fees']"
        />
      </template>
    </q-form>
  </FormDialog>
</template>

<script setup>
const props = defineProps({
  options: { type: Object, default: Object },
})

const pagination = inject('pagination')

const { schema, form } = useFormEmpty()
const { target: row, resetEdit } = useEdit(form)
const submit = useSubmit(form, pagination)

// A row with no bag hydrates meta_data to null, and the template binds
// form.meta_data.card_amount, so a null bag throws during render and takes the whole
// form with it -- no fields at all, not just that one. Which rows those are: every
// cash expense, income, dividend and payment, so most of what anyone would edit.
//
// Derived rather than mutated, so useWatchTarget, which watches this, is handed
// something it can bind.
const target = computed(() => {
  const editing = row.value

  if (!editing) return null

  return editing.meta_data ? editing : { ...editing, meta_data: useCloneForm(schema.meta_data) }
})

// Why this row's figures are fixed, from the same TransactionData::figureLock() the
// save is refused by, so the form says it before the user tries rather than after.
// The fields are disabled as well as named; the refusal still stands behind them for a
// stale page.
const lock = computed(() =>
  target.value ? (usePage().props.editLocks?.[target.value.id] ?? null) : null,
)
const locked = field => lock.value?.fields.includes(field) ?? false

// Every option list arrives from the controller, derived from the enum that decides
// it, so the pickers cannot offer a value TransactionData would reject nor fall
// short of one it accepts. `?? []` rather than a literal fallback, so a missing prop
// shows an empty dropdown instead of a stale hardcoded set.
const typeOptionsByAccountType = computed(() => usePage().props.typeOptions ?? {})
const typeDefaults = computed(() => usePage().props.typeDefaults ?? {})
const statusOptions = computed(() => usePage().props.statusOptions ?? [])
const currencyOptions = computed(() => usePage().props.currencyOptions ?? [])

const accountOptions = computed(() => props.options?.accounts ?? [])
const categoryOptions = computed(() => props.options?.categories ?? [])

// A trade's amount is computed on the server and TransactionData prohibits a
// client-supplied one for those types. Disabled rather than hidden so the shape of
// the form does not jump, and cleared below -- otherwise a buy made after an expense
// would carry an amount the server refuses.
const derivesAmount = computed(() => ['buy', 'sell'].includes(form.type))

// Only what the chosen account accepts, because the pairing is what makes a type legal
// at all. Empty until an account is picked, since there is nothing to narrow by.
const chosenAccount = computed(() => accountOptions.value.find(a => a.value === form.account_id))

// The calendar's menu, so a chosen day can close it. A template ref rather than
// $refs, which does not exist under <script setup>.
const dateMenu = ref(null)

// Writing the value and closing the menu together, so they cannot come apart: a
// v-model on the calendar plus a separate listener leaves the menu open whenever only
// one of the two runs.
//
// update:model-value, not input -- Quasar 2's q-date emits only that, and a listener
// for an event that is never emitted is not an error, it is a menu that never closes.
const pickDate = value => {
  form.date = typeof value === 'string' ? value : ''
  dateMenu.value?.hide()
}

const typeOptions = computed(() => {
  const type = chosenAccount.value?.type

  return type ? (typeOptionsByAccountType.value[type] ?? []) : []
})

// A charge in a currency the card is not needs what it came to in the card's own --
// the figure the statement will total and the settlement will pay. The account's ccy
// rides along on its option, which is why the options carry more than a label: without
// it the browser could not know whether the field applies and would have to show it
// always.
const needsCardAmount = computed(
  () =>
    form.type === 'charge' &&
    Boolean(chosenAccount.value?.ccy) &&
    chosenAccount.value.ccy !== form.ccy,
)

const title = computed(() => {
  return target.value ? 'edit transaction' : 'create new transaction'
})

// Two resets, and both are needed because the rules prohibit rather than ignore.
// Changing the account invalidates the type, since the new account may not accept it;
// changing the type invalidates every bag field the new type prohibits. A stale value
// fails the save over a field the user can no longer see.
const clearBag = () => {
  form.meta_data = useCloneForm(schema.meta_data)
}

watch(
  () => form.account_id,
  accountId => {
    // Guarded on isDirty, not on the previous value. useWatchTarget seeds the form
    // from a whole table row when editing, and form.reset() moves account_id off
    // null the same way a user's pick does -- so a guard on `previousId` cannot tell
    // the two apart, and both plausible versions of one get something wrong:
    //
    //   `if (!previousId) return`  skips the first account a user picks, which is
    //                             every first pick, leaving the currency unset
    //   no guard at all            fires while seeding, clearing the type the row
    //                             came with and failing the save over a field the
    //                             user never touched
    //
    // Dirty separates them exactly: a form that has just been reset is clean, and
    // anything the user changes makes it dirty before the watcher runs.
    if (!form.isDirty || !accountId) return

    const account = chosenAccount.value

    // The type the new account most likely wants, and null where the enum has no
    // opinion -- a securities account accepts a buy, a sell and a dividend, and
    // pre-filling one hands the user a type they did not choose.
    form.type = account?.type ? (typeDefaults.value[account.type] ?? null) : null

    clearBag()

    // The account's own currency, which is right almost every time and saves re-picking
    // it. A charge in another currency needs the card-currency figure, and that field
    // is what covers the case where this default is not the answer.
    if (account?.ccy) form.ccy = account.ccy
  },
)

watch(
  () => form.type,
  (type, previousType) => {
    if (previousType) clearBag()
    if (derivesAmount.value) form.amount = null
  },
)

// The figure is only meaningful while the two currencies differ, and the DTO refuses
// it otherwise rather than ignoring it -- so a leftover value from before the user
// changed the currency would fail the save over a field that is no longer on screen.
//
// Two watchers rather than one: needsCardAmount depends on both the account and the
// currency, so changing the currency back to the card's own fires it only once both
// have settled, and the field may already be hidden by then.
watch(needsCardAmount, applies => {
  if (!applies) form.meta_data.card_amount = null
})

watch(
  () => form.ccy,
  () => {
    if (!needsCardAmount.value) form.meta_data.card_amount = null
  },
)

useWatchTarget(target, schema, form)

provide('form', form)
</script>
