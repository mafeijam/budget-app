<template>
  <FormDialog :name="$page.props.meta.form" :title="title" @hide-form="closeForm">
    <!-- Out of the form body: a template fills the fields, it is not one of them. -->
    <template #header>
      <q-btn
        v-if="!target"
        flat
        no-caps
        padding="xs sm"
        color="grey-8"
        icon="bookmarks"
        icon-right="expand_more"
        label="Templates"
        class="text-weight-medium"
      >
        <q-menu anchor="bottom right" self="top right" :offset="[0, 6]" @show="templateSearch = ''">
          <div class="app-template-menu column no-wrap">
            <div class="app-template-menu__search">
              <q-input
                v-model="templateSearch"
                dense
                outlined
                autofocus
                clearable
                placeholder="Search templates"
              >
                <template #prepend>
                  <q-icon name="search" />
                </template>
              </q-input>
            </div>

            <q-list dense class="app-template-menu__list col">
              <template v-for="group in templateGroups" :key="group.account">
                <q-item-label header class="app-filter-group row items-center no-wrap q-py-xs">
                  <q-icon :name="group.icon" size="14px" class="q-mr-xs" />
                  {{ group.account }}
                  <q-space />
                  <span class="text-weight-regular">{{ group.templates.length }}</span>
                </q-item-label>
                <q-item
                  v-for="template in group.templates"
                  :key="template.key"
                  v-close-popup
                  clickable
                  class="app-template-item"
                  @click="applyTemplate(template)"
                >
                  <!-- Where the figure comes from: a recurring rule is kept up to date from
                       the history and has no row to edit or delete; a saved one is a copy. -->
                  <q-item-section avatar class="app-template-item__avatar">
                    <q-avatar
                      size="28px"
                      :class="
                        template.derived ? 'app-tint app-tint--info' : 'app-tint app-tint--muted'
                      "
                      :icon="template.derived ? 'autorenew' : 'bookmark'"
                    >
                      <q-tooltip :delay="500" :offset="[0, 6]">
                        {{ template.derived ? 'From a recurring rule' : 'A saved template' }}
                      </q-tooltip>
                    </q-avatar>
                  </q-item-section>
                  <q-item-section>
                    <q-item-label class="ellipsis text-weight-medium">{{
                      template.name
                    }}</q-item-label>
                    <q-item-label caption class="ellipsis">{{
                      templateCaption(template)
                    }}</q-item-label>
                  </q-item-section>
                  <q-item-section side class="text-right">
                    <div v-if="template.payload.amount" class="money text-grey-9">
                      {{ money(template.payload.amount) }}
                    </div>
                    <div class="text-caption text-grey-6">{{ template.payload.ccy }}</div>
                  </q-item-section>
                  <q-item-section v-if="template.id" side>
                    <q-btn
                      flat
                      dense
                      round
                      size="sm"
                      icon="delete"
                      color="negative"
                      class="app-template-delete"
                      @click.stop="destroyTemplate(template)"
                    >
                      <q-tooltip :delay="500" :offset="[0, 6]">Delete this template</q-tooltip>
                    </q-btn>
                  </q-item-section>
                </q-item>
              </template>

              <q-item v-if="!templateGroups.length">
                <q-item-section class="text-grey text-center q-py-md">
                  {{ templates.length ? 'No template matches' : 'No templates saved yet' }}
                </q-item-section>
              </q-item>
            </q-list>
          </div>
        </q-menu>
      </q-btn>
    </template>

    <template #subheader>
      <q-chip
        v-if="loadedTemplate && !target"
        dense
        removable
        icon="bookmark"
        class="app-tint app-tint--muted q-mx-none q-mb-none"
        :label="`From template: ${loadedTemplate.name}`"
        @remove="loadedTemplate = null"
      />
    </template>

    <template #actions-start>
      <!-- A recurring rule is read as a template and nothing is written back to it: no update,
           and no copy either, since a saved copy of a subscription is a second figure to keep
           and the rule it was copied from is the one the scan keeps up to date. -->
      <q-btn
        v-if="!derived"
        flat
        no-caps
        padding="sm md"
        color="grey-8"
        icon="bookmark_add"
        label="Save as template"
        :disable="!canTemplate"
        @click="saveTemplate"
      />

      <!-- id, and not merely the template: a recurring rule is read as a template and has no
           row to update, so without this the button PUTs to a route with no id in it. -->
      <q-btn
        v-if="loadedTemplate && loadedTemplate.id && !target"
        flat
        no-caps
        padding="sm md"
        color="grey-8"
        icon="save"
        :label="`Update ${loadedTemplate.name}`"
        :disable="!form.isDirty"
        @click="updateTemplate"
      />
    </template>

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
        v-model="form.account_id"
        :options="accountOptionList"
        class="col-12 col-sm-6"
        label="Account"
        outlined
        emit-value
        map-options
        :disable="locked('account_id')"
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

      <!-- Buttons rather than a list: an account allows two to four types, all worth seeing. -->
      <q-field
        class="col-12 col-sm-6 app-segment"
        borderless
        :disable="!form.account_id || locked('type')"
        :error="!!form.errors.type"
        :error-message="form.errors.type"
        :hint="typeButtons.length ? '' : 'Pick an account first'"
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
        class="col-12 col-sm-5 app-form-amount"
        label="Amount"
        outlined
        type="number"
        step="0.01"
        min="0"
        :prefix="amountPrefix"
        :disable="derivesAmount || locked('amount')"
        :hint="derivesAmount ? 'Derived from quantity and price' : ''"
        :error="!!form.errors.amount"
        :error-message="form.errors.amount"
      />

      <q-select
        v-model="form.ccy"
        :options="currencyOptions"
        class="col-5 col-sm-3"
        label="Currency"
        outlined
        emit-value
        map-options
        :disable="locked('ccy') || currencyLocked"
        :hint="
          currencyLocked && chosenAccount ? 'Only a card charge may be in another currency' : ''
        "
        :error="!!form.errors.ccy"
        :error-message="form.errors.ccy"
      />

      <!-- The mask goes on q-date only; on q-input it breaks. FormContractTest pins this. -->
      <q-input
        v-model="form.date"
        class="col-7 col-sm-4"
        label="Date"
        outlined
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

      <!--
        A text box with a menu of past descriptions, not a select. With use-input the field's
        text is a search string rather than the value, and only Enter commits it, so editing
        a description chosen from the list and then saving kept the choice: the box showed
        what had been typed and the row got the old value, with nothing on screen saying so.
        Typing a new one and saving was worse, for the same reason -- no save, no error, no
        row. Here the box is the value, and the menu only ever writes into it.

        The date below is the same shape, and for the same reason a field needs a calendar
        that a select cannot offer.

        no-focus is what lets the menu open without costing the box its caret. A QMenu takes
        focus as it opens, so the field that opened it lost the keystrokes that were meant to
        narrow it: the click showed the hints, and the typing after it went to the menu, and
        a second click was needed to get the caret back before anything could be written. The
        date control's menu does not have it, and should not: a calendar is meant to take over
        while it is open.
      -->
      <q-input
        ref="descriptionInput"
        v-model="form.description"
        class="col-12"
        label="Description"
        outlined
        autocomplete="off"
        :error="!!form.errors.description"
        :error-message="form.errors.description"
        @focus="hintsOpen = true"
      >
        <template #prepend>
          <q-icon name="notes" color="grey-6" />
        </template>
        <template #append>
          <q-btn flat dense icon="history" rounded @click="hintsOpen = !hintsOpen">
            <q-menu
              v-model="hintsOpen"
              no-focus
              :offset="[10, 15]"
              anchor="bottom right"
              self="top right"
              class="app-desc-hints"
            >
              <q-list dense>
                <q-item
                  v-for="hint in shownDescriptions"
                  :key="hint"
                  v-close-popup
                  clickable
                  @click="useDescription(hint)"
                >
                  <q-item-section>{{ hint }}</q-item-section>
                </q-item>
                <q-item v-if="!shownDescriptions.length">
                  <q-item-section class="text-grey">Nothing used before</q-item-section>
                </q-item>
              </q-list>
            </q-menu>
          </q-btn>
        </template>
      </q-input>

      <q-select
        v-model="form.category_id"
        :options="shownCategories"
        class="col-12 col-sm-7"
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

      <q-field
        class="col-12 col-sm-5 app-segment"
        borderless
        :disable="locked('status')"
        hint="A pending charge does not count toward what the card owes"
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

      <!-- The fields only some types have, set apart so the everyday form stays short. -->
      <div v-if="hasExtras" class="col-12">
        <div class="app-form-panel">
          <div class="row items-center text-caption text-weight-medium text-grey-8 q-mb-sm">
            <q-icon :name="extrasIcon" size="xs" class="q-mr-xs" />
            {{ extrasTitle }}
          </div>
          <div class="row q-col-gutter-md">
            <q-input
              v-if="needsCardAmount"
              v-model="form.meta_data.card_amount"
              class="col-6"
              label="Amount in the card's currency"
              outlined
              bg-color="white"
              type="number"
              step="0.01"
              :disable="locked('meta_data.card_amount')"
              :hint="`What the card owes for this, in ${chosenAccount?.ccy}`"
              :error="!!form.errors['meta_data.card_amount']"
              :error-message="form.errors['meta_data.card_amount']"
            />

            <template v-if="showsSymbol">
              <q-select
                v-if="isDividend"
                v-model="form.meta_data.brokerage_account_id"
                :options="brokerageOptions"
                class="col-6"
                label="Brokerage"
                outlined
                bg-color="white"
                emit-value
                map-options
                :error="!!form.errors['meta_data.brokerage_account_id']"
                :error-message="form.errors['meta_data.brokerage_account_id']"
              >
                <template #no-option>
                  <q-item>
                    <q-item-section class="text-grey">
                      No brokerage settles into this account
                    </q-item-section>
                  </q-item>
                </template>
              </q-select>

              <!-- No fill-input: QSelect already draws the value, so it would show twice. -->
              <q-select
                v-if="isDividend"
                v-model="form.meta_data.symbol"
                :options="shownSymbols"
                class="col-6"
                label="Symbol"
                outlined
                bg-color="white"
                emit-value
                map-options
                autocomplete="off"
                use-input
                fill-input
                input-debounce="0"
                new-value-mode="add-unique"
                :clearable="false"
                :error="!!form.errors['meta_data.symbol']"
                :error-message="form.errors['meta_data.symbol']"
                @filter="filterSymbols"
              >
                <template #no-option>
                  <q-item>
                    <q-item-section class="text-grey">
                      Not held here; Enter adds it
                    </q-item-section>
                  </q-item>
                </template>
              </q-select>

              <q-input
                v-else
                v-model="form.meta_data.symbol"
                class="col-6"
                label="Symbol"
                outlined
                bg-color="white"
                :error="!!form.errors['meta_data.symbol']"
                :error-message="form.errors['meta_data.symbol']"
              />

              <!-- false-value null, or unticking stores false. -->
              <q-toggle
                v-if="derivesAmount"
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
                outlined
                bg-color="white"
                type="number"
                step="0.00000001"
                :error="!!form.errors['meta_data.quantity']"
                :error-message="form.errors['meta_data.quantity']"
              />
              <q-input
                v-model="form.meta_data.unit_price"
                class="col-4"
                label="Unit price"
                outlined
                bg-color="white"
                type="number"
                step="0.0001"
                :error="!!form.errors['meta_data.unit_price']"
                :error-message="form.errors['meta_data.unit_price']"
              />
              <q-input
                v-model="form.meta_data.fees"
                class="col-4"
                label="Fees"
                outlined
                bg-color="white"
                type="number"
                step="0.0001"
                :error="!!form.errors['meta_data.fees']"
                :error-message="form.errors['meta_data.fees']"
              />
            </template>
          </div>
        </div>
      </div>
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

const shownCategories = ref([])

const filterCategories = filterInto(shownCategories, categoryOptions, (category, needle) =>
  category.label.toLowerCase().includes(needle),
)

const derivesAmount = computed(() => (usePage().props.derivesAmountTypes ?? []).includes(form.type))

const showsSymbol = computed(() => (usePage().props.symbolTypes ?? []).includes(form.type))

const isDividend = computed(() => showsSymbol.value && !derivesAmount.value)

const chosenAccount = computed(() => accountOptions.value.find(a => a.value === form.account_id))

const heldSymbols = computed(() => usePage().props.heldSymbols ?? {})

const brokerageOptions = computed(
  () => (usePage().props.dividendBrokerages ?? {})[form.account_id] ?? [],
)

const symbolOptions = computed(() =>
  (heldSymbols.value[form.meta_data.brokerage_account_id] ?? []).map(symbol => ({
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

// A label for a type the map has not met yet, rather than a blank button.
const typeButtons = computed(() =>
  typeOptions.value.map(type => ({ label: typeLabels[type] ?? type, value: type })),
)

const statusButtons = computed(() =>
  statusOptions.value.map(status => ({
    label: status.charAt(0).toUpperCase() + status.slice(1),
    value: status,
  })),
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

const hasExtras = computed(() => needsCardAmount.value || showsSymbol.value || derivesAmount.value)

const extrasTitle = computed(() => {
  if (derivesAmount.value) return 'The trade'
  if (isDividend.value) return 'Where the dividend is from'

  return "The card's currency"
})

const extrasIcon = computed(() =>
  derivesAmount.value ? 'candlestick_chart' : isDividend.value ? 'savings' : 'currency_exchange',
)

const title = computed(() => {
  return target.value ? 'Edit transaction' : 'Create new transaction'
})

const templates = computed(() => usePage().props.templates ?? [])

const templateSearch = ref('')

// QList has no groups, so the account names are headers between runs of templates. The
// recurring rules come first in each account's run: they are the ones with a current figure
// behind them, and a saved template of the same subscription tends to be the stale one.
const templateGroups = computed(() => {
  const needle = (templateSearch.value ?? '').toLowerCase()
  const groups = new Map()

  for (const template of [...templates.value].sort(
    (a, b) => Number(b.derived) - Number(a.derived),
  )) {
    const account = template.account_name ?? ''

    if (needle && !`${template.name} ${account}`.toLowerCase().includes(needle)) continue

    if (!groups.has(account)) groups.set(account, [])
    groups.get(account).push(template)
  }

  return [...groups].map(([account, list]) => ({
    account,
    icon:
      accountTypeIcons[accountOptions.value.find(a => a.value === list[0].account_id)?.type] ??
      'account_balance_wallet',
    templates: list,
  }))
})

const money = useMoney()

// The category and the type, what a name alone does not say.
const templateCaption = template =>
  [
    categoryOptions.value.find(category => category.value === template.category_id)?.label,
    typeLabels[template.payload.type] ?? template.payload.type,
  ]
    .filter(Boolean)
    .join(' · ')

const shownSymbols = ref([])

// The past descriptions the menu offers, narrowed by what is in the box. A computed, not
// filterInto's handler: a QSelect asks to be told what matched, and a text input has no such
// question to ask of anybody.
const shownDescriptions = computed(() => {
  const needle = (form.description ?? '').trim().toLowerCase()

  return needle === ''
    ? descriptionHints.value
    : descriptionHints.value.filter(description => description.toLowerCase().includes(needle))
})

const hintsOpen = ref(false)

const descriptionInput = ref(null)

// The menu writes the value through here rather than in the template, so there is one place
// a description is set from a hint. FormContractTest requires every form. reference in the
// template to be a binding it can parse, and an assignment inside a click handler is not one.
//
// Focus goes back to the box afterwards: the menu is dismissed by the click that filled it,
// and without this the caret lands nowhere and the next character is typed to no field.
const useDescription = description => {
  form.description = description
  nextTick(() => descriptionInput.value?.focus())
}

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

// Not cleared by a Reset: Update is disabled on a clean form, so it cannot misfire.
const loadedTemplate = ref(null)

// Back to the blank defaults on close: a template becomes the form's defaults, so the next
// Add would otherwise open filled from it, with no chip saying so.
const closeForm = () => {
  if (loadedTemplate.value && !target.value) form.defaults(useCloneForm(schema))

  loadedTemplate.value = null
  resetEdit()
}

// Checked here because the prompt closes before the request runs, so a server refusal
// would land nowhere visible.
const canTemplate = computed(() => Boolean(form.account_id && form.type))

// What the form is filled from, and whether that thing can be written to. A recurring rule
// is read as a template, so it has no update, no delete and nothing to save a copy of.
const derived = computed(() => Boolean(loadedTemplate.value?.derived))

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

// The rule's day in the month the date is in, or for a yearly rule its month and day in the
// date's year. Past a short month's end it is the last day, as the server schedules it: an
// unclamped 31st in September would be 1 October, a month the user never picked.
const onScheduleDay = (date, schedule) => {
  if (!schedule?.day || !/^\d{4}-\d{2}-\d{2}$/.test(date ?? '')) return date

  const year = Number(date.slice(0, 4))
  const month = schedule.frequency === 'yearly' ? schedule.month : Number(date.slice(5, 7))
  const last = new Date(Date.UTC(year, month, 0)).getUTCDate()
  const pad = n => String(n).padStart(2, '0')

  return `${year}-${pad(month)}-${pad(Math.min(schedule.day, last))}`
}

const applyTemplate = template => {
  if (!template) return

  form.defaults({
    ...schema,
    ...template.payload,
    amount: twoPlaces(template.payload.amount),

    account_id: template.account_id,
    category_id: template.category_id,

    // A saved template holds no date, so the day already chosen is kept rather than reset to
    // today. A recurring rule's has its day, so the date moves to it within that month.
    date: onScheduleDay(form.date || schema.date, template.schedule),

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

    if (!derivesAmount.value) form.meta_data.no_cash = null
  },
)

// A dividend's brokerage must settle into the chosen bank, and a lone one is picked for you.
watch([isDividend, brokerageOptions], ([dividend, options]) => {
  const chosen = form.meta_data.brokerage_account_id

  if (!dividend) {
    if (chosen !== null && chosen !== undefined) form.meta_data.brokerage_account_id = null

    return
  }

  if (!options.some(option => option.value === chosen)) {
    form.meta_data.brokerage_account_id = options.length === 1 ? options[0].value : null
  }
})

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
