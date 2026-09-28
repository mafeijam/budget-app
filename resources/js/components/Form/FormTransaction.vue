<template>
  <FormDialog :name="$page.props.meta.form" :title="title" @hide-form="resetEdit">
    <q-form :id="$page.props.meta.form" class="row q-col-gutter-md" @submit="submit(target)">
      <!--
        Wrapped, because the gutter pads the column and a banner coloured to its edges
        would sit offset from every field below it.
      -->
      <div v-if="lock" class="col-12">
        <q-banner rounded dense class="app-tint app-tint--warning">
          <template #avatar>
            <q-icon name="lock" />
          </template>
          {{ lock.message }}
        </q-banner>
      </div>

      <!--
        Fill the form from a template, or save what it holds as one. The two are the
        same shortcut read in two directions, and both are here rather than on a page of
        their own because a template is only ever made by filling this form in and only
        ever used by filling it in again.

        A picker rather than a menu of templates, because a person reaching for one
        knows roughly what it is called and typing three letters beats scrolling to it.

        One row per template with the account captioned, rather than a heading per
        account. QSelect filters option by option, and a heading is an option, so the two
        cannot both work: searching would either keep a heading whose templates were all
        filtered out, or drop the heading and leave its templates under nothing. The
        caption is what the heading was saying, and it survives a search.

        Update replaces the values of the template the form was filled from, so a
        template that has drifted is corrected rather than deleted and retyped. It is
        offered only once the form has moved off what it was filled with, since
        overwriting a template with the values it already holds is a way of losing one
        for nothing.

        Tinted buttons rather than flat grey ones, which is the Add button's treatment
        and the reason for it: grey text is the colour a disabled control is painted in,
        so a grey label reads as unavailable however available it is, and these were
        grey at two shades before that was tried. The fill is a 13% wash of the brand
        colour, so they are quieter than the Submit button without borrowing its
        disabled grey to say so.

        app-btn and not app-btn--positive, though Update writes: Submit is the dialog's
        action and these are not, and a second button in the positive tint beside it
        would leave the eye with two answers to "what does this dialog commit".
      -->
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
        <!--
          Accounts under a heading per kind, and the kind beside each name in the
          transactions table's tint. The type decides what the picker then offers -- a
          brokerage has buy, sell and dividend where a bank has expense, income -- so a
          list of names alone makes the user pick an account and find out after.

          The currency captioned under the name, since it is the other half of what the
          account is and the form fixes it for everything but a charge on a card. The
          closed field keeps the bare name -- see below.

          Only in the list: the closed field keeps the bare name, since a badge in the
          value would read as a filter and there is nothing to filter. `?? {}` as the
          column uses, so an account type the map does not know shows plainly instead of
          failing the render.
        -->
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
        :disable="locked('ccy') || currencyLocked"
        :hint="
          currencyLocked && chosenAccount
            ? 'Only a charge on a card may be in another currency'
            : ''
        "
        :error="!!form.errors.ccy"
        :error-message="form.errors.ccy"
      />

      <!--
        The one field here a person types rather than picks, so it is the one that offers
        what they have typed before: every description used in the last two years, newest
        first, filtered as they type. Everything else on this form is chosen from a list
        the enum builds, which is why this is the only field with hints.

        A select rather than an input with an autocomplete attribute, because the browser's
        own list cannot be ordered, cannot be filtered against what the server holds, and
        cannot be styled to match the rest of this form -- and because the value must stay
        free text. add-unique is what makes that true: it lets a description never seen
        before through, which is the common case, and does not constrain the field to what
        is in the list. Without it this would be a picker that could only record the past.

        The filter is the handler below rather than anything QSelect does on its own:
        filter() returns immediately unless a @filter listener is attached, so without
        one the list does not narrow at all and the field looks like it is filtering
        while showing every description there is.

        The clear button is off because a person correcting a description wants to type
        over it, and because emptying the field is not a state this form can save -- the
        server requires a description.
      -->
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

      <!--
        A symbol for anything that writes a cash side: a buy, a sell, and a dividend on
        a brokerage. That last is a deposit, so without it here the form would offer a
        dividend with nowhere to say which holding paid it, and the save would be refused
        for a field that was never on screen.

        The two conditions are separate rather than one nested template because the symbol
        and the flag follow the wider rule while quantity, price and fees follow the
        narrower one -- a dividend is not a quantity of anything.
      -->
      <template v-if="writesCashSide">
        <q-input
          v-model="form.meta_data.symbol"
          class="col-6"
          label="Symbol"
          filled
          :error="!!form.errors['meta_data.symbol']"
          :error-message="form.errors['meta_data.symbol']"
        />

        <!--
          The money side is skipped rather than the row, so a dividend or a position
          back-dated from before the settlement account was tracked can be recorded
          without inventing a bank row for money that moved outside these accounts. The
          shares still count.

          false-value, because Quasar's off value is false and ticking then unticking
          would store one -- a second spelling of "not skipped" beside an absent key, and
          the one value TradeCash's own check has to be careful of, since Laravel reads
          filled(false) as true. Naming null as the off value leaves absence the only way
          to say no.
        -->
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
// Imported, not auto-registered: both are handed to Dialog.create() as objects rather
// than used as tags in this template, which is the case the resolver cannot see.
import { Dialog } from 'quasar'
import DeleteDialog from '../DeleteDialog.vue'

const props = defineProps({
  options: { type: Object, default: Object },
})

const pagination = inject('pagination')

// The transactions table's tints, repeated here rather than shared. Sharing them wants a
// composable, and an auto-imported const of this shape has twice come through the build
// as a name with no value behind it -- the option list and the table's column then both
// read undefined, silently, which is a worse way to lose a colour than repeating it.
const accountTypeBadges = {
  cash: { color: 'teal-1', textColor: 'teal-9' },
  card: { color: 'deep-purple-1', textColor: 'deep-purple-9' },
  security: { color: 'orange-1', textColor: 'orange-10' },
}

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

// Descriptions used recently, to suggest as the field is typed into. `?? []` as on every
// other list here, so a page that has not sent them shows an empty suggestion list
// rather than a stale hardcoded one.
const descriptionHints = computed(() => usePage().props.descriptionHints ?? [])

// The account types in the order the enum declares them, which is the order the groups
// are read in -- cash, card, security. Taken from the keys rather than sorted by name or
// by how many accounts each holds, so adding an account cannot move a heading.
const accountTypeOrder = computed(() => Object.keys(typeOptionsByAccountType.value))

// One flat list with the headings in it, because QSelect has no grouped options: it
// draws one item per entry in the array it is given, so a heading has to be an entry.
// Disabled, since that is what stops QSelect handing a value back for it, and carrying a
// value of its own so a heading can never be read as the account that is selected. A type
// with no accounts contributes no heading at all rather than an empty one.
const accountOptionList = computed(() =>
  accountTypeOrder.value.flatMap(type => {
    const accounts = accountOptions.value.filter(account => account.type === type)

    return accounts.length === 0
      ? []
      : [{ label: type, value: `type:${type}`, disable: true, heading: true }, ...accounts]
  }),
)

const categoryOptions = computed(() => props.options?.categories ?? [])

// The types the server derives an amount for: a trade, and only a trade. A client's
// amount for one of those is prohibited, so the field is disabled and cleared rather than
// hidden -- the shape of the form should not jump between types.
//
// From the prop rather than `['buy', 'sell']` written here, which was a fourth copy of
// TransactionType::derivesAmount() and the one most likely to drift from it.
const derivesAmount = computed(() => (usePage().props.derivesAmountTypes ?? []).includes(form.type))

// Whether this row writes a row in a brokerage's settlement account: a trade, or a
// dividend. Symbol and the cash-side flag are offered for those and nothing else.
//
// Keyed by account type, because needsCashSide() takes one -- a deposit qualifies on a
// brokerage and not on a bank, so the same type shows different fields depending on where
// it sits. Falls back to the derived list, so a page that has not sent the prop behaves as
// it did before the flag existed rather than showing nothing.
const cashSideTypesByAccount = computed(() => usePage().props.cashSideTypes ?? {})

const writesCashSide = computed(() => {
  const type = chosenAccount.value?.type
  const forAccount = type ? cashSideTypesByAccount.value[type] : null

  return (forAccount ?? usePage().props.derivesAmountTypes ?? []).includes(form.type)
})

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
  return target.value ? 'Edit transaction' : 'Create new transaction'
})

// ---------------------------------------------------------------------
// Templates
// ---------------------------------------------------------------------

const templates = computed(() => usePage().props.templates ?? [])

// One entry per template, with the name as the label. The label is also what the picker
// filters on, which is why the account is a caption on the row rather than a heading
// above it: a heading would be an option too, and would then be filtered as one.
const templateOptions = computed(() =>
  templates.value.map(template => ({ ...template, label: template.name })),
)

/**
 * The @filter handler for a list this form narrows as a field is typed into.
 *
 * QSelect filters nothing by itself. filter() returns on its first line unless a
 * @filter listener is attached, so the listener is the whole of the narrowing and it
 * has to own a list to narrow -- which is why there is a "shown" list beside each
 * source rather than the filter writing back into the source.
 *
 * Narrowing reads the source and never the shown list, or the list would narrow from
 * its own narrowed state: type "b", then "l", and the second pass would search only
 * what matched "b" -- so a description containing "blue" but not "b" is unreachable,
 * and widening the search again cannot bring anything back.
 *
 * Empty restores the whole list, which is what happens when the input is cleared or the
 * field is reset, and is the only way back from a narrow one.
 *
 * `matches` is the only thing the two fields disagree about: a description is a string,
 * a template an object carrying its name. Trimmed and lower-cased on both sides, so a
 * trailing space typed by accident does not silently empty the list.
 */
const filterInto = (shown, source, matches) => {
  // Immediate, and that is the seeding as well as the reset: the field is worth opening
  // before anything has been typed, since a person who cannot remember the description is
  // exactly the person who needs to see what they have called it before. A page visit that
  // keeps this component alive sends a new list -- a template saved or deleted, say -- and
  // without this the shown list would keep showing the one before it.
  watch(
    source,
    () => {
      shown.value = [...source.value]
    },
    { immediate: true },
  )

  return (val, update) =>
    update(() => {
      const needle = val.trim().toLowerCase()

      shown.value =
        needle === '' ? [...source.value] : source.value.filter(entry => matches(entry, needle))
    })
}

const shownDescriptions = ref([])

const shownTemplates = ref([])

const filterDescriptions = filterInto(shownDescriptions, descriptionHints, (description, needle) =>
  description.toLowerCase().includes(needle),
)

const filterTemplates = filterInto(shownTemplates, templateOptions, (template, needle) =>
  template.label.toLowerCase().includes(needle),
)

// What the picker currently holds, emptied the moment a template is applied. It is a
// choice rather than a selection on purpose: a picker left showing "Rent" would still
// say "Rent" the next time this form is opened for a different transaction, having
// filled nothing.
const templateChoice = ref(null)

// Which template the form was filled from, so Update knows what it is overwriting.
//
// Not cleared by a Reset, which does not go through anything this component owns, and
// deliberately so: the button that depends on it is disabled while the form is clean,
// which is exactly what a Reset leaves it, so a stale name here can never overwrite a
// template with an emptied form.
const loadedTemplate = ref(null)

// The two things a template cannot be without, and both of which the server requires:
// an account to file the transaction against, and a type, which is what decides what
// the form can then offer. Checked here because the dialog this opens settles the moment
// OK is pressed -- the request runs after it is gone -- so a refusal would land in an
// error bag belonging to a dialog that is no longer there, on a field this form has no
// control for. Better that the button is not offered than that the answer is invisible.
const canTemplate = computed(() => Boolean(form.account_id && form.type))

// The form as a template body. The whole form, not the keys a template keeps: that list
// belongs to the server, which is the only place a server-owned key can be left out by
// name, and restating it here would be a second copy free to drift from the first.
const templateBody = name => ({
  name,
  account_id: form.account_id,
  category_id: form.category_id,
  payload: { ...form },
})

// preserveState and preserveScroll together, and both matter: without the first Inertia
// re-renders the page and closes the dialog the user is still filling in, and without
// the second the list scrolls out from under them.
const saveTemplate = () => {
  Dialog.create({
    title: 'Save as template',
    message: 'A name for these values, so they can be filled in again.',
    prompt: {
      model: '',
      type: 'text',
      label: 'Name',
      outlined: true,
      // The column is 255 and the server appends a number to a name in use, trimming to
      // fit -- so the cap is what stops the dialog offering a name the request will
      // refuse, on a prompt that has already closed by then.
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

// Replace the loaded template with what the form holds now.
//
// defaults() and reset() rather than assigning field by field, which is how useWatchTarget
// fills the form from a row: one call, and the form's idea of its clean state moves with
// the values rather than leaving the button enabled for values that were never touched.
const applyTemplate = template => {
  // The picker also emits this when it is emptied, and a null has no template in it.
  if (!template) return

  form.defaults({
    ...schema,
    ...template.payload,

    // From the columns rather than the payload, which does not carry them -- one copy of
    // each, and no way for the two to disagree about which account a template is for.
    account_id: template.account_id,
    category_id: template.category_id,

    // Merged, not replaced. A template stores only the bag keys it keeps, so replacing
    // would leave due_date, paired_transaction_id and settled_by undefined rather than
    // null, and the form binds all three.
    meta_data: { ...schema.meta_data, ...template.payload.meta_data },
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
    // Cleared first: the button it hides names a template that is about to stop existing,
    // and the picker still holds it until the request comes back with it gone.
    if (loadedTemplate.value?.id === template.id) loadedTemplate.value = null

    router.delete(`/transaction-templates/${template.id}`, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => notifySuccess(),
    })
  })
}

// One currency per account, bar a charge on a card.
//
// A lock and nothing else: the value is not forced. Forcing it would rewrite the
// currency of a row that already disagrees -- opening a USD expense on an HKD bank to
// fix its description would silently save it as HKD, which is a worse thing to do than
// leave the row as the user recorded it. So a stored row keeps its own currency and is
// simply not editable here, and only a row being created has its currency set from the
// account, in the watcher below.
//
// A charge on a card is the one row that may differ, because it carries what it came to
// in the card's own currency: card_amount, which needsCardAmount asks for further down
// and CardStatement sums in place of the amount.
//
// The server still accepts any currency on any row, and deliberately -- see
// test_a_transaction_may_differ_from_its_account_currency. So this narrows what the form
// offers and nothing more. The rule that is enforced is guardTradeCurrency's, which
// refuses a trade in another currency because a brokerage settles into one bank.
const currencyLocked = computed(
  () => !(chosenAccount.value?.type === 'card' && form.type === 'charge'),
)

// Picking an account is the form filling in what that account implies -- its currency and
// the type it most likely wants -- and only while creating, where there is nothing to
// lose. Two resets are needed because the rules prohibit rather than ignore: changing the
// account invalidates the type, since the new account may not accept it, and changing the
// type invalidates the bag keys the new type prohibits.
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

    // Creating only, and the distinction is the whole of it. A new transaction has no
    // values to lose, and picking the type and the currency here saves two choices the
    // user did not mean to make. An edit is the opposite: the row's type, its trade
    // figures and its currency are all real, and correcting a transaction filed against
    // the wrong account would silently cost the symbol, the quantity and the price.
    //
    // What the new account will not take is the server's to say, by name, on a field
    // that is on screen: "A charge cannot be recorded on a cash account" leaves the user
    // choosing, where a substituted 'expense' and an empty bag leaves them with a
    // transaction they never wrote.
    if (target.value) return

    const account = chosenAccount.value

    // The type the new account most likely wants, and null where the enum has no
    // opinion -- a securities account accepts a buy, a sell and a dividend, and
    // pre-filling one hands the user a type they did not choose.
    form.type = account?.type ? (typeDefaults.value[account.type] ?? null) : null

    // The account's own currency, which is right almost every time and saves re-picking
    // it. A charge on a card may differ and this leaves it alone, because currencyLocked
    // below is false for exactly that case and the card-currency figure is what covers
    // the one where this default would not be the answer.
    if (account?.ccy && currencyLocked.value) form.ccy = account.ccy
  },
)

// Only the bag keys the new type actually refuses, rather than the whole bag.
//
// A trade's amount is derived from the figures in the bag, so a client-supplied one is
// prohibited -- that is a refusal. And no_cash is a claim about a trade's cash side,
// which nothing else has, so it is refused off a trade -- and refused on a *hidden*
// field, since the toggle only renders for a buy and a sell.
//
// Everything else in the bag is stale rather than refused: symbol, quantity, unit price
// and fees on a non-trade are all nullable and not prohibited, and a due date or a
// card-currency figure left behind is read by nothing. Wiping the lot is how a symbol
// typed for a buy is lost by changing the type, and it is why changing the account did
// too -- which the watcher above now declines to do while editing.
watch(
  () => form.type,
  (type, previousType) => {
    if (!previousType) return

    if (derivesAmount.value) form.amount = null

    // Off anything with no cash side at all, rather than off anything that is not a
    // trade: a dividend writes one too and may be back-dated the same way a position is.
    if (!writesCashSide.value) form.meta_data.no_cash = null
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
