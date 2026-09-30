<template>
  <FormDialog :name="$page.props.meta.form" :title="title" @hide-form="resetEdit">
    <q-form :id="$page.props.meta.form" class="row q-col-gutter-md" @submit="submit(target)">
      <q-select
        v-model="form.account_id"
        :options="accountOptionList"
        class="col-12 col-sm-6"
        label="Account"
        outlined
        emit-value
        map-options
        :error="!!form.errors.account_id"
        :error-message="form.errors.account_id"
      >
        <template #prepend>
          <q-icon :name="accountIcon" :color="chosenAccount ? 'primary' : 'grey-6'" />
        </template>
        <template #option="scope">
          <q-item-label
            v-if="scope.opt.heading"
            header
            class="app-filter-group q-py-xs"
            v-bind="scope.itemProps"
          >
            {{ accountTypeTitles[scope.opt.label] ?? scope.opt.label }}
          </q-item-label>
          <q-item v-else v-bind="scope.itemProps" dense>
            <q-item-section>{{ scope.opt.label }}</q-item-section>
            <q-item-section side class="text-caption">{{ scope.opt.ccy }}</q-item-section>
          </q-item>
        </template>
      </q-select>

      <!-- Buttons rather than a list, as the transaction form has. -->
      <q-field
        class="col-12 col-sm-6 app-segment"
        borderless
        :disable="!form.account_id"
        :hint="typeButtons.length ? '' : 'Pick an account first'"
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

      <q-input
        v-model="form.amount"
        class="col-12 col-sm-7 app-form-amount"
        label="Amount"
        outlined
        type="number"
        step="0.01"
        min="0"
        :prefix="amountPrefix"
        hint="Recorded as pending, so it can be corrected before it counts"
        :error="!!form.errors.amount"
        :error-message="form.errors.amount"
      />

      <q-select
        v-model="form.ccy"
        :options="currencyOptions"
        class="col-12 col-sm-5"
        label="Currency"
        outlined
        emit-value
        map-options
        :disable="currencyLocked"
        :hint="
          currencyLocked && chosenAccount ? 'Only a card charge may be in another currency' : ''
        "
        :error="!!form.errors.ccy"
        :error-message="form.errors.ccy"
      />

      <q-input
        v-if="needsCardAmount"
        v-model="form.card_amount"
        class="col-12 col-sm-6"
        label="Amount in the card's currency"
        outlined
        bg-color="white"
        type="number"
        step="0.01"
        :hint="`What the card owes for this, in ${chosenAccount?.ccy}`"
        :error="!!form.errors.card_amount"
        :error-message="form.errors.card_amount"
      />

      <q-input
        v-model="form.description"
        class="col-12"
        label="Description"
        outlined
        autocomplete="off"
        :error="!!form.errors.description"
        :error-message="form.errors.description"
      >
        <template #prepend>
          <q-icon name="notes" color="grey-6" />
        </template>
      </q-input>

      <q-select
        v-model="form.category_id"
        :options="shownCategories"
        class="col-12"
        label="Category"
        outlined
        emit-value
        map-options
        clearable
        autocomplete="off"
        use-input
        fill-input
        hide-selected
        input-debounce="0"
        :error="!!form.errors.category_id"
        :error-message="form.errors.category_id"
        @filter="filterCategories"
      >
        <template #prepend>
          <q-icon name="label" color="grey-6" />
        </template>
        <template #no-option>
          <q-item>
            <q-item-section class="text-grey">No category matches</q-item-section>
          </q-item>
        </template>
      </q-select>

      <!-- The schedule, set apart: when it lands, and whether it still does. -->
      <div class="col-12">
        <div class="app-form-panel">
          <div class="row items-center text-caption text-weight-medium text-grey-8 q-mb-sm">
            <q-icon name="event_repeat" size="xs" class="q-mr-xs" />
            The schedule
          </div>
          <div class="row q-col-gutter-md">
            <q-field
              class="col-12 col-sm-4 app-segment"
              borderless
              :error="!!form.errors.frequency"
              :error-message="form.errors.frequency"
            >
              <template #control>
                <div class="app-segment__box">
                  <div class="app-segment__label">Repeats</div>
                  <q-btn-toggle
                    v-model="form.frequency"
                    :options="frequencyButtons"
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

            <!-- The mask goes on q-date only; on q-input it breaks. See FormTransaction.vue. -->
            <q-input
              v-model="form.start_date"
              class="col-12 col-sm-4"
              label="First date"
              outlined
              bg-color="white"
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
              class="col-12 col-sm-4"
              label="Ends"
              placeholder="Never"
              outlined
              bg-color="white"
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
          </div>
        </div>
      </div>
    </q-form>
  </FormDialog>
</template>

<script setup>
const props = defineProps({
  options: { type: Object, default: Object },
})

const pagination = inject('pagination')

const accountTypeTitles = { cash: 'Cash', card: 'Cards', security: 'Securities' }
const accountTypeIcons = { cash: 'account_balance', card: 'credit_card', security: 'show_chart' }

const typeLabels = {
  withdraw: 'Withdraw',
  deposit: 'Deposit',
  charge: 'Charge',
  payment: 'Payment',
  buy: 'Buy',
  sell: 'Sell',
  dividend: 'Dividend',
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

// QSelect has no grouped options, so each type's heading is a disabled entry, in the
// order the server's type map lists the account types.
const accountOptionList = computed(() =>
  Object.keys(typeOptionsByAccountType.value).flatMap(type => {
    const accounts = accountOptions.value.filter(account => account.type === type)

    return accounts.length === 0
      ? []
      : [{ label: type, value: `type:${type}`, disable: true, heading: true }, ...accounts]
  }),
)

const shownCategories = ref([])

const filterCategories = filterInto(shownCategories, categoryOptions, (category, needle) =>
  category.label.toLowerCase().includes(needle),
)

const chosenAccount = computed(() =>
  (props.options?.accounts ?? []).find(a => a.value === form.account_id),
)

const typeOptions = computed(() => typeOptionsByAccountType.value[chosenAccount.value?.type] ?? [])

const capitalised = value => value.charAt(0).toUpperCase() + value.slice(1)

const typeButtons = computed(() =>
  typeOptions.value.map(type => ({ label: typeLabels[type] ?? capitalised(type), value: type })),
)

const frequencyButtons = computed(() =>
  frequencyOptions.value.map(frequency => ({ label: capitalised(frequency), value: frequency })),
)

const accountIcon = computed(
  () => accountTypeIcons[chosenAccount.value?.type] ?? 'account_balance_wallet',
)

const amountPrefix = computed(() => (form.ccy ? `${form.ccy} ` : ''))

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
