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

// settlementOptions comes from props because the table paginates.
const settlementOptions = computed(() => usePage().props.settlementOptions ?? [])

const currencyOptions = computed(() => usePage().props.currencyOptions ?? [])

const typeOptions = computed(() => usePage().props.typeOptions ?? [])

const statusOptions = computed(() => usePage().props.statusOptions ?? [])

const title = computed(() => {
  return target.value ? 'Edit account' : 'Create new account'
})

// A computed, not an inline ternary, which FormContractTest cannot parse.
const settlementLabel = computed(() => (form.type === 'card' ? 'Paid from' : 'Settles into'))

const settlementHint = computed(() => {
  if (form.type === 'card') {
    return form.meta_data.settlement_account_id
      ? 'Settling this card records a payment here too'
      : 'Needed before this card can be settled'
  }

  return 'Required: trades settle through this bank'
})

useWatchTarget(target, schema, form)

// Mirrors AccountMetaData's `prohibited_unless:type,security,card`.
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
