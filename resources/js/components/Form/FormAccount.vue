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
          v-model="form.meta_data.due"
          class="col-6"
          label="Due day"
          filled
          type="number"
          min="1"
          max="31"
          hint="Day of the month the card is paid on"
          :error="!!form.errors['meta_data.due']"
          :error-message="form.errors['meta_data.due']"
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

      <template v-if="form.type === 'security'">
        <q-select
          v-model="form.settlement_account_id"
          :options="settlementOptions"
          class="col-6"
          label="Settles into"
          filled
          emit-value
          map-options
          :error="!!form.errors.settlement_account_id"
          :error-message="form.errors.settlement_account_id"
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

// Cash accounts only, straight off the server. Read from the page props rather
// than the table's rows: the table paginates, so a securities account could not
// settle into a bank that happened to be off the first page. Server-rendered
// rather than derived here so the options cannot disagree with what AccountData
// will accept.
const settlementOptions = computed(() => usePage().props.settlementOptions ?? [])

// Currency options, for the same reason and by the same route: derived from the
// server so the dropdown cannot offer a currency AccountData would reject, nor
// fall short of one it accepts. See the comment on the controller.
//
// `?? []` rather than a literal fallback, so a missing prop shows an empty
// dropdown rather than silently offering a stale hardcoded set.
const currencyOptions = computed(() => usePage().props.currencyOptions ?? [])

// Type and status, same reason and same route. These two were the last option
// lists still written into this template, which left a case added to either enum
// accepted by AccountData and unoffered here -- a brokerage type the user could
// not create, with nothing failing. `?? []` for the same reason as above.
const typeOptions = computed(() => usePage().props.typeOptions ?? [])

const statusOptions = computed(() => usePage().props.statusOptions ?? [])

const title = computed(() => {
  return target.value ? 'edit account' : 'create new account'
})

useWatchTarget(target, schema, form)

provide('form', form)
</script>
