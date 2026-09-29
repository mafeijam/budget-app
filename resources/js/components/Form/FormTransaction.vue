<template>
  <FormDialog :name="$page.props.meta.form" :title="title" @hide-form="resetEdit">
    <q-form :id="$page.props.meta.form" class="row q-col-gutter-md" @submit="submit(target)">
      <!-- Wrapped, or the gutter offsets the tinted banner from the fields. -->
      <div v-if="lock" class="col-12">
        <q-banner rounded dense class="app-tint app-tint--warning">
          <template #avatar>
            <q-icon name="lock" />
          </template>
          {{ lock.message }}
        </q-banner>
      </div>

      <q-select
        v-model="templateChoice"
        :options="shownTemplates"
        class="col-6"
        label="Template"
        placeholder="Fill the form from a saved one"
        filled
        emit-value
        map-options
        filterable
        input-debounce="0"
        :disable="!!target"
        @filter="filterTemplates"
        @update:model-value="applyTemplate"
      >
        <template #no-option>
          <q-item>
            <q-item-section class="text-grey"> No templates saved yet </q-item-section>
          </q-item>
        </template>

        <template #option="scope">
          <q-item v-bind="scope.itemProps">
            <q-item-section>
              {{ scope.opt.label }}
              <q-item-label caption>{{ scope.opt.account_name }}</q-item-label>
            </q-item-section>

            <q-item-section side>
              <q-btn
                flat
                dense
                round
                icon="delete"
                color="negative"
                @click.stop="destroyTemplate(scope.opt)"
              >
                <q-tooltip :delay="500" :offset="[0, 6]">Delete this template</q-tooltip>
              </q-btn>
            </q-item-section>
          </q-item>
        </template>
      </q-select>

      <div class="col-6 row items-center q-gutter-sm">
        <q-btn
          class="text-weight-bold app-btn"
          unelevated
          no-caps
          padding="sm md"
          icon="bookmark_add"
          label="Save as template"
          :disable="!canTemplate"
          @click="saveTemplate"
        />

        <q-btn
          v-if="loadedTemplate"
          class="text-weight-bold app-btn"
          unelevated
          no-caps
          padding="sm md"
          icon="save"
          :label="`Update ${loadedTemplate.name}`"
          :disable="!form.isDirty"
          @click="updateTemplate"
        />
      </div>

      <q-select
        v-model="form.account_id"
        :options="accountOptionList"
        class="col-6"
        label="Account"
        filled
        emit-value
        map-options
        :disable="locked('account_id')"
        :error="!!form.errors.account_id"
        :error-message="form.errors.account_id"
      >
        <template #option="scope">
          <q-item
            v-if="scope.opt.heading"
            v-bind="scope.itemProps"
            dense
            class="app-tint app-tint--muted"
          >
            <q-item-section class="text-caption">{{ scope.opt.label }}</q-item-section>
          </q-item>
          <q-item v-else v-bind="scope.itemProps">
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
        :disable="!form.account_id || locked('type')"
        class="col-6"
        label="Type"
        filled
        :error="!!form.errors.type"
        :error-message="form.errors.type"
      />

      <!-- The mask goes on q-date only; on q-input it breaks. FormContractTest pins this. -->
      <q-input
        v-model="form.date"
        class="col-4"
        label="Date"
        filled
        :disable="locked('date')"
        :error="!!form.errors.date"
        :error-message="form.errors.date"
      >
        <template #append>
          <q-btn flat dense icon="event" rounded :disable="locked('date')">
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

      <q-input
        v-model="form.amount"
        class="col-4"
        label="Amount"
        filled
        type="number"
        step="0.01"
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
        :disable="locked('ccy') || currencyLocked"
        :hint="
          currencyLocked && chosenAccount
            ? 'Only a charge on a card may be in another currency'
            : ''
        "
        :error="!!form.errors.ccy"
        :error-message="form.errors.ccy"
      />

      <!-- Without @filter, QSelect never narrows the list. -->
      <q-select
        v-model="form.description"
        :options="shownDescriptions"
        class="col-12"
        label="Description"
        filled
        autocomplete="off"
        use-input
        input-debounce="0"
        new-value-mode="add-unique"
        :clearable="false"
        :error="!!form.errors.description"
        :error-message="form.errors.description"
        @filter="filterDescriptions"
      >
        <template #no-option>
          <q-item>
            <q-item-section class="text-grey"> Nothing matches; Enter adds it </q-item-section>
          </q-item>
        </template>
      </q-select>

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

      <q-input
        v-if="needsCardAmount"
        v-model="form.meta_data.card_amount"
        class="col-6"
        label="Amount in the card's currency"
        filled
        type="number"
        step="0.01"
        :disable="locked('meta_data.card_amount')"
        :hint="`What the card owes for this, in ${chosenAccount?.ccy}`"
        :error="!!form.errors['meta_data.card_amount']"
        :error-message="form.errors['meta_data.card_amount']"
      />

      <template v-if="writesCashSide">
        <!-- No fill-input: QSelect already draws the value, so it would show twice. -->
        <q-select
          v-if="isDividend"
          v-model="form.meta_data.symbol"
          :options="shownSymbols"
          class="col-6"
          label="Symbol"
          filled
          emit-value
          map-options
          autocomplete="off"
          use-input
          input-debounce="0"
          new-value-mode="add-unique"
          :clearable="false"
          :error="!!form.errors['meta_data.symbol']"
          :error-message="form.errors['meta_data.symbol']"
          @filter="filterSymbols"
        >
          <template #no-option>
            <q-item>
              <q-item-section class="text-grey"> Not held here; Enter adds it </q-item-section>
            </q-item>
          </template>
        </q-select>

        <q-input
          v-else
          v-model="form.meta_data.symbol"
          class="col-6"
          label="Symbol"
          filled
          :error="!!form.errors['meta_data.symbol']"
          :error-message="form.errors['meta_data.symbol']"
        />

        <!-- false-value null, or unticking stores false. -->
        <q-toggle
          v-model="form.meta_data.no_cash"
          :false-value="null"
          class="col-6"
          label="No cash side"
          color="primary"
          dense
          :error="!!form.errors['meta_data.no_cash']"
          :error-message="form.errors['meta_data.no_cash']"
        />
      </template>

      <template v-if="derivesAmount">
        <q-input
          v-model="form.meta_data.quantity"
          class="col-4"
          label="Quantity"
          filled
          type="number"
          step="0.00000001"
          :error="!!form.errors['meta_data.quantity']"
          :error-message="form.errors['meta_data.quantity']"
        />
        <q-input
          v-model="form.meta_data.unit_price"
          class="col-4"
          label="Unit price"
          filled
          type="number"
          step="0.0001"
          :error="!!form.errors['meta_data.unit_price']"
          :error-message="form.errors['meta_data.unit_price']"
        />
        <q-input
          v-model="form.meta_data.fees"
          class="col-4"
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
// Imported: handed to Dialog.create(), which auto-registration cannot see.
import { Dialog } from 'quasar'
import DeleteDialog from '../DeleteDialog.vue'

const props = defineProps({
  options: { type: Object, default: Object },
})

const pagination = inject('pagination')

// Repeated rather than shared: an auto-imported const of this shape has come through the
// build undefined, silently dropping the colours.
const accountTypeBadges = {
  cash: { color: 'teal-1', textColor: 'teal-9' },
  card: { color: 'deep-purple-1', textColor: 'deep-purple-9' },
  security: { color: 'orange-1', textColor: 'orange-10' },
}

const { schema, form } = useFormEmpty()
const { target: row, resetEdit } = useEdit(form)
const submit = useSubmit(form, pagination)

// A row with no bag hydrates meta_data to null, which throws on render and blanks the form.
const target = computed(() => {
  const editing = row.value

  if (!editing) return null

  const meta = editing.meta_data ?? useCloneForm(schema.meta_data)

  return {
    ...editing,
    amount: twoPlaces(editing.amount),
    meta_data: { ...meta, card_amount: twoPlaces(meta.card_amount) },
  }
})

const lock = computed(() =>
  target.value ? (usePage().props.editLocks?.[target.value.id] ?? null) : null,
)
const locked = field => lock.value?.fields.includes(field) ?? false

const typeOptionsByAccountType = computed(() => usePage().props.typeOptions ?? {})
const typeDefaults = computed(() => usePage().props.typeDefaults ?? {})
const statusOptions = computed(() => usePage().props.statusOptions ?? [])
const currencyOptions = computed(() => usePage().props.currencyOptions ?? [])

const accountOptions = computed(() => props.options?.accounts ?? [])

const descriptionHints = computed(() => usePage().props.descriptionHints ?? [])

const accountTypeOrder = computed(() => Object.keys(typeOptionsByAccountType.value))

// QSelect has no grouped options, so each heading is a disabled entry.
const accountOptionList = computed(() =>
  accountTypeOrder.value.flatMap(type => {
    const accounts = accountOptions.value.filter(account => account.type === type)

    return accounts.length === 0
      ? []
      : [{ label: type, value: `type:${type}`, disable: true, heading: true }, ...accounts]
  }),
)

const categoryOptions = computed(() => props.options?.categories ?? [])

const derivesAmount = computed(() => (usePage().props.derivesAmountTypes ?? []).includes(form.type))

const writesCashSide = computed(() => (usePage().props.cashSideTypes ?? []).includes(form.type))

const isDividend = computed(() => writesCashSide.value && !derivesAmount.value)

const chosenAccount = computed(() => accountOptions.value.find(a => a.value === form.account_id))

const heldSymbols = computed(() => usePage().props.heldSymbols ?? {})

const symbolOptions = computed(() =>
  (heldSymbols.value[chosenAccount.value?.value] ?? []).map(symbol => ({
    label: symbol,
    value: symbol,
  })),
)

const dateMenu = ref(null)

const pickDate = value => {
  form.date = typeof value === 'string' ? value : ''
  dateMenu.value?.hide()
}

const typeOptions = computed(() => {
  const type = chosenAccount.value?.type

  return type ? (typeOptionsByAccountType.value[type] ?? []) : []
})

const needsCardAmount = computed(
  () =>
    form.type === 'charge' &&
    Boolean(chosenAccount.value?.ccy) &&
    chosenAccount.value.ccy !== form.ccy,
)

const title = computed(() => {
  return target.value ? 'Edit transaction' : 'Create new transaction'
})

const templates = computed(() => usePage().props.templates ?? [])

const templateOptions = computed(() =>
  templates.value.map(template => ({ ...template, label: template.name })),
)

const shownDescriptions = ref([])

const shownTemplates = ref([])

const shownSymbols = ref([])

const filterDescriptions = filterInto(shownDescriptions, descriptionHints, (description, needle) =>
  description.toLowerCase().includes(needle),
)

const filterTemplates = filterInto(shownTemplates, templateOptions, (template, needle) =>
  template.label.toLowerCase().includes(needle),
)

const filterSymbols = filterInto(shownSymbols, symbolOptions, (option, needle) =>
  option.label.toLowerCase().includes(needle),
)

// Only the description a symbol pick wrote; one the user typed is never overwritten.
const claimedDescription = ref(null)

const describeDividend = symbol => {
  const text = `Dividend ${symbol}`

  if (form.description && form.description !== claimedDescription.value) {
    claimedDescription.value = null

    return
  }

  form.description = text
  claimedDescription.value = text
}

watch(
  () => form.meta_data.symbol,
  symbol => {
    if (isDividend.value && symbol) describeDividend(symbol)
  },
)

const templateChoice = ref(null)

// Not cleared by a Reset: Update is disabled on a clean form, so it cannot misfire.
const loadedTemplate = ref(null)

// Checked here because the prompt closes before the request runs, so a server refusal
// would land nowhere visible.
const canTemplate = computed(() => Boolean(form.account_id && form.type))

// The whole form: which keys a template keeps is the server's list, not a copy here.
const templateBody = name => ({
  name,
  account_id: form.account_id,
  category_id: form.category_id,
  payload: { ...form },
})

const saveTemplate = () => {
  Dialog.create({
    title: 'Save as template',
    message: 'A name for these values, so they can be filled in again.',
    prompt: {
      model: '',
      type: 'text',
      label: 'Name',
      outlined: true,
      maxlength: 255,
      isValid: value => value.trim() !== '',
    },
    ok: 'Save',
    cancel: true,
  }).onOk(name => {
    router.post('/transaction-templates', templateBody(name.trim()), {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => notifySuccess(),
    })
  })
}

const applyTemplate = template => {
  if (!template) return

  form.defaults({
    ...schema,
    ...template.payload,
    amount: twoPlaces(template.payload.amount),

    account_id: template.account_id,
    category_id: template.category_id,

    // Merged: a template stores only the keys it keeps, and the form binds the rest.
    meta_data: {
      ...schema.meta_data,
      ...template.payload.meta_data,
      card_amount: twoPlaces(template.payload.meta_data?.card_amount ?? null),
    },
  })

  form.reset()
  form.clearErrors()

  loadedTemplate.value = template
  templateChoice.value = null
}

const updateTemplate = () =>
  router.put(
    `/transaction-templates/${loadedTemplate.value.id}`,
    templateBody(loadedTemplate.value.name),
    {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => notifySuccess(),
    },
  )

const destroyTemplate = template => {
  Dialog.create({
    component: DeleteDialog,
    componentProps: {
      title: 'delete template',
      message: `[${template.name}] will be deleted permanently.`,
    },
  }).onOk(() => {
    if (loadedTemplate.value?.id === template.id) loadedTemplate.value = null

    router.delete(`/transaction-templates/${template.id}`, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => notifySuccess(),
    })
  })
}

// Locked, not forced, so editing a stored row never silently rewrites its currency.
const currencyLocked = computed(
  () => !(chosenAccount.value?.type === 'card' && form.type === 'charge'),
)

watch(
  () => form.account_id,
  accountId => {
    // isDirty, not previousId: seeding an edit also moves account_id off null.
    if (!form.isDirty || !accountId) return

    // Creating only: on an edit, resetting would silently cost the row's type and figures.
    if (target.value) return

    const account = chosenAccount.value

    form.type = account?.type ? (typeDefaults.value[account.type] ?? null) : null

    // Not gated on currencyLocked, or a card's default charge starts with no currency.
    if (account?.ccy) form.ccy = account.ccy
  },
)

// Clears only what the new type refuses, so a typed symbol survives a type change.
watch(
  () => form.type,
  (type, previousType) => {
    if (!previousType) return

    if (derivesAmount.value) form.amount = null

    if (!writesCashSide.value) form.meta_data.no_cash = null
  },
)

// The DTO refuses a card_amount it does not need, even once the field is hidden.
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
