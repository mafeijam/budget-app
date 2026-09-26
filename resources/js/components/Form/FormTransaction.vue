<template>
  <FormDialog :name="$page.props.meta.form" :title="title" @hide-form="resetEdit">
    <q-form :id="$page.props.meta.form" class="row q-col-gutter-md" @submit="submit(target)">
      <q-select
        v-model="form.account_id"
        :options="accountOptions"
        class="col-6"
        label="Account"
        filled
        emit-value
        map-options
        :error="!!form.errors.account_id"
        :error-message="form.errors.account_id"
      />

      <q-select
        v-model="form.type"
        :options="typeOptions"
        :disable="!form.account_id"
        class="col-6"
        label="Type"
        filled
        :error="!!form.errors.type"
        :error-message="form.errors.type"
      />

      <q-input
        v-model="form.date"
        class="col-4"
        label="Date"
        filled
        type="date"
        :error="!!form.errors.date"
        :error-message="form.errors.date"
      />

      <q-input
        v-model="form.amount"
        class="col-4"
        label="Amount"
        filled
        type="number"
        step="0.0001"
        min="0"
        :disable="derivesAmount"
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
        hint="A pending charge does not count toward what the card owes"
        :error="!!form.errors.status"
        :error-message="form.errors.status"
      />

      <template v-if="form.type === 'charge'">
        <q-input
          v-model="form.meta_data.merchant"
          class="col-6"
          label="Merchant"
          filled
          :error="!!form.errors['meta_data.merchant']"
          :error-message="form.errors['meta_data.merchant']"
        />
      </template>

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

      <q-input
        v-model="form.meta_data.fx_rate"
        class="col-6"
        label="Exchange rate"
        filled
        type="number"
        step="0.00000001"
        hint="Only if the transaction's currency is not the account's"
        :error="!!form.errors['meta_data.fx_rate']"
        :error-message="form.errors['meta_data.fx_rate']"
      />
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
// form.meta_data.merchant, so a null bag throws during render and takes the whole
// form with it -- no fields at all, not just that one. Which rows those are: every
// cash expense, income, dividend and payment, so most of what anyone would edit.
//
// Repaired here rather than in the bindings, because the bindings are what they are
// and the shape they need is the one the schema declares. Derived rather than
// mutated, so the row the edit map holds is not quietly rewritten -- and so
// useWatchTarget, which watches this, hands the form something it can bind.
const target = computed(() => {
  const editing = row.value

  if (!editing) return null

  return editing.meta_data ? editing : { ...editing, meta_data: useCloneForm(schema.meta_data) }
})

// Every option list arrives from the controller, derived from the enum that decides
// it, so the pickers cannot offer a value TransactionData would reject nor fall
// short of one it accepts. `?? []` rather than a literal fallback, so a missing
// prop shows an empty dropdown instead of a stale hardcoded set.
const typeOptionsByAccountType = computed(() => usePage().props.typeOptions ?? {})
const statusOptions = computed(() => usePage().props.statusOptions ?? [])
const currencyOptions = computed(() => usePage().props.currencyOptions ?? [])

const accountOptions = computed(() => props.options?.accounts ?? [])
const categoryOptions = computed(() => props.options?.categories ?? [])

// A trade's amount is quantity x unit price, computed on the server, and
// TransactionData prohibits a client-supplied one for those types outright. The
// field is disabled rather than hidden so the shape of the form does not jump, and
// a value left over from a previous type is cleared below -- otherwise a buy made
// after an expense would carry an amount the server refuses.
const derivesAmount = computed(() => ['buy', 'sell'].includes(form.type))

// Only what the chosen account accepts, because the pairing is what makes a type
// legal at all. Disabled until an account is picked, since there is nothing to
// narrow by before then.
const chosenAccount = computed(() => accountOptions.value.find(a => a.value === form.account_id))

const typeOptions = computed(() => {
  const type = chosenAccount.value?.type

  return type ? (typeOptionsByAccountType.value[type] ?? []) : []
})

const title = computed(() => {
  return target.value ? 'edit transaction' : 'create new transaction'
})

// Two resets, and both are needed because the rules prohibit rather than ignore.
//
// Changing the account invalidates the type, since the new account may not accept
// it; changing the type invalidates every bag field that the new type prohibits.
// TransactionMetaData marks each of those prohibited_unless rather than dropping
// them, so a stale value fails the save over a field the user can no longer see.
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

    form.type = null
    clearBag()

    // Default the currency to the account's own, which is right almost every time
    // and saves re-picking it. fx_rate covers the case where it is not.
    const ccy = accountOptions.value.find(a => a.value === accountId)?.ccy

    if (ccy) form.ccy = ccy
  },
)

watch(
  () => form.type,
  (type, previousType) => {
    if (previousType) clearBag()
    if (derivesAmount.value) form.amount = null
  },
)

useWatchTarget(target, schema, form)

provide('form', form)
</script>
