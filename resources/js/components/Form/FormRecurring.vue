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
      >
        <template #option="scope">
          <q-item v-bind="scope.itemProps">
            <q-item-section>
              {{ scope.opt.label }}
              <q-item-label caption>{{ scope.opt.ccy }}</q-item-label>
            </q-item-section>
            <q-item-section side>
              <q-badge v-bind="accountTypeBadges[scope.opt.type] ?? {}" :label="scope.opt.type" />
            </q-item-section>
          </q-item>
        </template>
      </q-select>

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
        v-model="form.description"
        class="col-12"
        label="Description"
        filled
        autocomplete="off"
        :error="!!form.errors.description"
        :error-message="form.errors.description"
      />

      <q-input
        v-model="form.amount"
        class="col-6"
        label="Amount"
        filled
        type="number"
        step="0.01"
        min="0"
        hint="Recorded as pending, so it can be corrected before it counts"
        :error="!!form.errors.amount"
        :error-message="form.errors.amount"
      />

      <q-select
        v-model="form.ccy"
        :options="currencyOptions"
        class="col-6"
        label="Currency"
        filled
        emit-value
        map-options
        :disable="currencyLocked"
        :hint="
          currencyLocked && chosenAccount
            ? 'Only a charge on a card may be in another currency'
            : ''
        "
        :error="!!form.errors.ccy"
        :error-message="form.errors.ccy"
      />

      <q-input
        v-if="needsCardAmount"
        v-model="form.card_amount"
        class="col-6"
        label="Amount in the card's currency"
        filled
        type="number"
        step="0.01"
        :hint="`What the card owes for this, in ${chosenAccount?.ccy}`"
        :error="!!form.errors.card_amount"
        :error-message="form.errors.card_amount"
      />

      <q-select
        v-model="form.category_id"
        :options="categoryOptions"
        class="col-12"
        label="Category"
        filled
        emit-value
        map-options
        clearable
        :error="!!form.errors.category_id"
        :error-message="form.errors.category_id"
      />

      <q-select
        v-model="form.frequency"
        :options="frequencyOptions"
        class="col-4"
        label="Repeats"
        filled
        :error="!!form.errors.frequency"
        :error-message="form.errors.frequency"
      />

      <!-- The mask goes on q-date only; on q-input it breaks. See FormTransaction.vue. -->
      <q-input
        v-model="form.start_date"
        class="col-4"
        label="First date"
        filled
        :hint="scheduleHint"
        :error="!!form.errors.start_date"
        :error-message="form.errors.start_date"
      >
        <template #append>
          <q-btn flat dense icon="event" rounded>
            <q-menu ref="startMenu" :offset="[10, 15]" anchor="bottom right" self="top right">
              <q-date
                :model-value="form.start_date"
                mask="YYYY-MM-DD"
                minimal
                color="primary"
                @update:model-value="pickStart"
              />
            </q-menu>
          </q-btn>
        </template>
      </q-input>

      <q-input
        v-model="form.end_date"
        class="col-4"
        label="Ends"
        placeholder="Never"
        filled
        clearable
        :error="!!form.errors.end_date"
        :error-message="form.errors.end_date"
      >
        <template #append>
          <q-btn flat dense icon="event" rounded>
            <q-menu ref="endMenu" :offset="[10, 15]" anchor="bottom right" self="top right">
              <q-date
                :model-value="form.end_date"
                mask="YYYY-MM-DD"
                minimal
                color="primary"
                @update:model-value="pickEnd"
              />
            </q-menu>
          </q-btn>
        </template>
      </q-input>

      <!-- Resuming skips what fell due while paused rather than catching up. -->
      <q-toggle
        v-model="form.active"
        class="col-12"
        label="Active"
        color="primary"
        :error="!!form.errors.active"
        :error-message="form.errors.active"
      />
    </q-form>
  </FormDialog>
</template>

<script setup>
const props = defineProps({
  options: { type: Object, default: Object },
})

const pagination = inject('pagination')

// Repeated rather than shared, as in FormTransaction.vue.
const accountTypeBadges = {
  cash: { color: 'teal-1', textColor: 'teal-9' },
  card: { color: 'deep-purple-1', textColor: 'deep-purple-9' },
}

const { schema, form } = useFormEmpty()
const { target: row, resetEdit } = useEdit(form)

const target = computed(() =>
  row.value
    ? {
        ...row.value,
        amount: twoPlaces(row.value.amount),
        card_amount: twoPlaces(row.value.card_amount),
      }
    : null,
)
const submit = useSubmit(form, pagination)

const typeOptionsByAccountType = computed(() => usePage().props.typeOptions ?? {})
const typeDefaults = computed(() => usePage().props.typeDefaults ?? {})
const currencyOptions = computed(() => usePage().props.currencyOptions ?? [])
const frequencyOptions = computed(() => usePage().props.frequencyOptions ?? [])
const categoryOptions = computed(() => props.options?.categories ?? [])

// A closed account is offered only to the rule already on it.
const accountOptions = computed(() =>
  (props.options?.accounts ?? []).filter(
    account => account.active || account.value === target.value?.account_id,
  ),
)

const chosenAccount = computed(() =>
  (props.options?.accounts ?? []).find(a => a.value === form.account_id),
)

const typeOptions = computed(() => typeOptionsByAccountType.value[chosenAccount.value?.type] ?? [])

const needsCardAmount = computed(
  () =>
    form.type === 'charge' &&
    Boolean(chosenAccount.value?.ccy) &&
    chosenAccount.value.ccy !== form.ccy,
)

const currencyLocked = computed(
  () => !(chosenAccount.value?.type === 'card' && form.type === 'charge'),
)

const title = computed(() =>
  target.value ? 'Edit recurring transaction' : 'Create new recurring transaction',
)

// The server counts from the first date without overflow, so the 31st lands on the 28th
// in February and back on the 31st in March.
const scheduleHint = computed(() => {
  const [, month, day] = (form.start_date ?? '').split('-')

  if (!day) return ''

  return form.frequency === 'yearly'
    ? `Every year on ${month}-${day}`
    : `Every month on day ${Number(day)}, or the month's last day`
})

const startMenu = ref(null)
const endMenu = ref(null)

const pickStart = value => {
  form.start_date = typeof value === 'string' ? value : ''
  startMenu.value?.hide()
}

const pickEnd = value => {
  form.end_date = typeof value === 'string' ? value : null
  endMenu.value?.hide()
}

watch(
  () => form.account_id,
  accountId => {
    // isDirty, not previousId: seeding an edit also moves account_id off null.
    if (!form.isDirty || !accountId) return

    const account = chosenAccount.value

    if (!typeOptions.value.includes(form.type)) {
      form.type = typeDefaults.value[account?.type] ?? null
    }

    if (account?.ccy) form.ccy = account.ccy
  },
)

// The DTO refuses a card_amount it does not need, even once the field is hidden.
watch(needsCardAmount, applies => {
  if (!applies) form.card_amount = null
})

useWatchTarget(target, schema, form)

provide('form', form)
</script>
