<template>
  <FormDialog :name="ctx.meta.form" :title="title" @hide-form="resetEdit">
    <q-form :id="ctx.meta.form" class="row q-col-gutter-md" @submit="submit(target)">
      <q-input
        v-model="form.name"
        class="col-12 col-sm-8"
        label="Name"
        outlined
        :error="!!form.errors.name"
        :error-message="form.errors.name"
        autofocus
      >
        <template #prepend>
          <q-icon :name="typeIcon" color="grey-6" />
        </template>
      </q-input>
      <q-select
        v-model="form.ccy"
        :options="currencyOptions"
        class="col-12 col-sm-4"
        label="Currency"
        outlined
        emit-value
        map-options
        :error="!!form.errors.ccy"
        :error-message="form.errors.ccy"
      />

      <!-- Buttons, as on the transaction form: three types and two states, all worth seeing. -->
      <q-field
        class="col-12 col-sm-7 app-segment"
        borderless
        :error="!!form.errors.type"
        :error-message="form.errors.type"
      >
        <template #control>
          <div class="app-segment__box">
            <div class="app-segment__label">Type</div>
            <q-btn-toggle
              v-model="form.type"
              :options="typeButtons"
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
      <q-field
        class="col-12 col-sm-5 app-segment"
        borderless
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

      <!-- A type's own fields, set apart as the transaction form's are. -->
      <div v-if="hasExtras" class="col-12">
        <div class="app-form-panel">
          <div class="row items-center text-caption text-weight-medium text-grey-8 q-mb-sm">
            <q-icon :name="typeIcon" size="xs" class="q-mr-xs" />
            {{ extrasTitle }}
          </div>
          <div class="row q-col-gutter-md">
            <template v-if="form.type === 'card'">
              <q-input
                v-model="form.meta_data.statement_day"
                class="col-12 col-sm-6"
                label="Statement day"
                outlined
                bg-color="white"
                type="number"
                min="1"
                max="31"
                hint="Day of the month the statement closes"
                :error="!!form.errors['meta_data.statement_day']"
                :error-message="form.errors['meta_data.statement_day']"
              />
              <q-input
                v-model="form.meta_data.term_days"
                class="col-12 col-sm-6"
                label="Payment term (days)"
                outlined
                bg-color="white"
                type="number"
                min="1"
                max="31"
                hint="Days after the statement closes before it is due"
                :error="!!form.errors['meta_data.term_days']"
                :error-message="form.errors['meta_data.term_days']"
              />
            </template>

            <template v-if="['security', 'card'].includes(form.type)">
              <q-select
                v-model="form.meta_data.settlement_account_id"
                :options="settlementOptions"
                class="col-12"
                :label="settlementLabel"
                outlined
                bg-color="white"
                emit-value
                map-options
                :hint="settlementHint"
                :error="!!form.errors['meta_data.settlement_account_id']"
                :error-message="form.errors['meta_data.settlement_account_id']"
              >
                <template #prepend>
                  <q-icon name="account_balance" color="grey-6" />
                </template>
                <template #no-option>
                  <q-item>
                    <q-item-section class="text-grey"> No cash account yet </q-item-section>
                  </q-item>
                </template>
              </q-select>
            </template>
          </div>
        </div>
      </div>
    </q-form>
  </FormDialog>
</template>

<script setup>
// Its own page's props, or the Add menu's for it: see useFormContext().
const ctx = useFormContext()

const pagination = inject('pagination')

const { schema, form } = useFormEmpty()
const { target, resetEdit } = useEdit(form)
const submit = useSubmit(form, pagination)

// settlementOptions comes from props because the table paginates.
const settlementOptions = computed(() => ctx.settlementOptions ?? [])

const currencyOptions = computed(() => ctx.currencyOptions ?? [])

const typeOptions = computed(() => ctx.typeOptions ?? [])

const statusOptions = computed(() => ctx.statusOptions ?? [])

const title = computed(() => {
  return target.value ? 'Edit account' : 'Create new account'
})

const typeTitles = { cash: 'Cash', card: 'Card', security: 'Security' }
const typeIcons = { cash: 'account_balance', card: 'credit_card', security: 'show_chart' }

// A label for a value the maps have not met yet, rather than a blank button.
const capitalised = value => value.charAt(0).toUpperCase() + value.slice(1)

const typeButtons = computed(() =>
  typeOptions.value.map(type => ({
    label: typeTitles[type] ?? capitalised(type),
    value: type,
  })),
)

const statusButtons = computed(() =>
  statusOptions.value.map(status => ({ label: capitalised(status), value: status })),
)

const typeIcon = computed(() => typeIcons[form.type] ?? 'account_balance_wallet')

const hasExtras = computed(() => maySettle(form.type))

const extrasTitle = computed(() =>
  form.type === 'card' ? 'The statement, and the bank that pays it' : 'Where trades settle',
)

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
