<template>
  <FormDialog :name="$page.props.meta.form" :title="title" @hide-form="resetEdit">
    <q-form :id="$page.props.meta.form" class="row q-col-gutter-md" @submit="submit(target)">
      <q-input
        v-model="form.name"
        class="col-6"
        label="Name"
        filled
        :error="!!form.errors.name"
        :error-message="form.errors.name"
        autofocus
      />
      <q-select
        v-model="form.ccy"
        :options="currencyOptions"
        class="col-6"
        label="Currency"
        filled
        emit-value
        map-options
        :error="!!form.errors.ccy"
        :error-message="form.errors.ccy"
      />
      <q-select
        v-model="form.type"
        :options="typeOptions"
        class="col-6"
        label="Type"
        filled
        :error="!!form.errors.type"
        :error-message="form.errors.type"
      />
      <q-select
        v-model="form.status"
        :options="statusOptions"
        class="col-6"
        label="Status"
        filled
        :error="!!form.errors.status"
        :error-message="form.errors.status"
      />

      <template v-if="form.type === 'card'">
        <q-input
          v-model="form.meta_data.term_days"
          class="col-6"
          label="Payment term (days)"
          filled
          type="number"
          min="1"
          max="31"
          hint="Days after the statement closes before it is due"
          :error="!!form.errors['meta_data.term_days']"
          :error-message="form.errors['meta_data.term_days']"
        />
        <q-input
          v-model="form.meta_data.statement_day"
          class="col-6"
          label="Statement day"
          filled
          type="number"
          min="1"
          max="31"
          hint="Day of the month the statement closes"
          :error="!!form.errors['meta_data.statement_day']"
          :error-message="form.errors['meta_data.statement_day']"
        />
      </template>

      <!--
        Card as well as securities, because the rule allows it on both: a brokerage
        settles trades into a bank, a card is paid from one. Offering it for securities
        alone would leave a card with no way to record where it is paid from.
      -->
      <template v-if="['security', 'card'].includes(form.type)">
        <q-select
          v-model="form.meta_data.settlement_account_id"
          :options="settlementOptions"
          class="col-6"
          :label="settlementLabel"
          filled
          emit-value
          map-options
          :hint="settlementHint"
          :error="!!form.errors['meta_data.settlement_account_id']"
          :error-message="form.errors['meta_data.settlement_account_id']"
        >
          <template #no-option>
            <q-item>
              <q-item-section class="text-grey"> No cash account yet </q-item-section>
            </q-item>
          </template>
        </q-select>
      </template>
    </q-form>
  </FormDialog>
</template>

<script setup>
const pagination = inject('pagination')

const { schema, form } = useFormEmpty()
const { target, resetEdit } = useEdit(form)
const submit = useSubmit(form, pagination)

// Every list from the page props, never hardcoded here: the controller derives each from
// the enum that decides it, so a dropdown cannot offer a value AccountData would reject
// nor fall short of one it accepts. `?? []` rather than a literal fallback, so a missing
// prop shows an empty dropdown rather than a stale hardcoded set.
//
// settlementOptions is read from the props rather than the table's rows because the
// table paginates -- a securities account could not settle into a bank off page one.
const settlementOptions = computed(() => usePage().props.settlementOptions ?? [])

const currencyOptions = computed(() => usePage().props.currencyOptions ?? [])

const typeOptions = computed(() => usePage().props.typeOptions ?? [])

const statusOptions = computed(() => usePage().props.statusOptions ?? [])

const title = computed(() => {
  return target.value ? 'edit account' : 'create new account'
})

// Named for what the link means to each type. A computed rather than an inline
// ternary on form.type, which FormContractTest parses with a fixed set of syntaxes --
// and a template it cannot read is one whose visibility conditions it then cannot
// check, so anything conditional belongs here.
const settlementLabel = computed(() => (form.type === 'card' ? 'Paid from' : 'Settles into'))

// Why this picker exists, which differs by account type: a brokerage has to have a
// bank or its trades mean nothing, while a card works without one and simply cannot
// be settled until it names a bank.
const settlementHint = computed(() => {
  if (form.type === 'card') {
    return form.meta_data.settlement_account_id
      ? 'Settling this card records a payment here too'
      : 'Needed before this card can be settled'
  }

  return 'Required: trades settle through this bank'
})

useWatchTarget(target, schema, form)

// The two account types that may carry a settlement link, mirroring AccountMetaData's
// `prohibited_unless:type,security,card`. The form cannot read a validation rule, so
// this is a copy that can go stale -- visibly, since a field shown for a cash account
// is one the save then refuses.
//
// Not cleared on the way in: an account that may not have held a link has nothing stale
// to drop, and clearing unconditionally would wipe it off an account toggled away and
// back.
const maySettle = type => ['security', 'card'].includes(type)

watch(
  () => form.type,
  (val, oldVal) => {
    if (oldVal && form.isDirty) {
      form.meta_data = useCloneForm(schema.meta_data)

      if (!maySettle(val)) {
        form.meta_data.settlement_account_id = null
      }
    }
  },
)

provide('form', form)
</script>
