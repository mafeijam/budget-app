<template>
  <!-- Split, and the one filled button in the app: the left half adds a transaction, which
       is nearly every add, so the common one is a click rather than two; the right half has
       the rest. -->
  <div class="app-add row no-wrap items-stretch">
    <q-btn
      unelevated
      no-caps
      color="primary"
      class="app-add__main text-weight-bold"
      icon="add"
      label="Transaction"
      :loading="loading === 'transaction'"
      @click="open(forms[0])"
    />
    <q-btn
      unelevated
      color="primary"
      class="app-add__more"
      icon="expand_more"
      :loading="loading !== null && loading !== 'transaction'"
      aria-label="Add something else"
    >
      <q-menu
        anchor="bottom right"
        self="top right"
        :offset="[0, 8]"
        class="app-add-menu"
        transition-show="jump-down"
        transition-hide="jump-up"
      >
        <div v-for="group in groups" :key="group.title" class="app-add-menu__group">
          <div class="app-add-menu__title">{{ group.title }}</div>
          <q-item
            v-for="form in group.items"
            :key="form.key"
            v-close-popup
            clickable
            class="app-add-menu__item"
            @click="open(form)"
          >
            <q-item-section avatar>
              <span class="app-add-menu__icon" :class="`app-add-menu__icon--${form.tone}`">
                <q-icon :name="form.icon" size="18px" />
              </span>
            </q-item-section>
            <q-item-section>
              <q-item-label class="text-weight-medium text-grey-9">{{ form.label }}</q-item-label>
              <q-item-label caption>{{ form.caption }}</q-item-label>
            </q-item-section>
          </q-item>
        </div>
      </q-menu>
    </q-btn>
  </div>

  <!-- Keyed per opening, so a form starts from its own empty values every time. -->
  <FormContextHost v-if="active && active.component" :key="active.id" :context="active.context">
    <component :is="active.component" :options="active.context.options" />
  </FormContextHost>

  <!-- Not a form dialog: two rows written as one, with a dialog of its own. -->
  <TransferDialog
    v-if="active && active.key === 'transfer'"
    ref="transferDialog"
    :key="active.id"
    :accounts="active.context.accounts"
    :today="active.context.today"
  />
</template>

<script setup>
// Imported, not auto-registered: the component resolver finds tags in templates, and these
// are handed to <component :is>.
import FormTransaction from './Form/FormTransaction.vue'
import FormAccount from './Form/FormAccount.vue'
import FormCategory from './Form/FormCategory.vue'
import FormRecurring from './Form/FormRecurring.vue'

const forms = [
  {
    key: 'transaction',
    name: 'transaction-form',
    label: 'Transaction',
    caption: 'Money in or out of one account',
    icon: 'receipt_long',
    tone: 'primary',
    component: FormTransaction,
  },
  {
    key: 'transfer',
    label: 'Transfer',
    caption: 'Between accounts or currencies',
    icon: 'sync_alt',
    tone: 'info',
  },
  {
    key: 'recurring',
    name: 'recurring-form',
    label: 'Recurring rule',
    caption: 'A bill or income that repeats',
    icon: 'event_repeat',
    tone: 'positive',
    component: FormRecurring,
  },
  {
    key: 'account',
    name: 'account-form',
    label: 'Account',
    caption: 'A bank account, card or brokerage',
    icon: 'account_balance',
    tone: 'muted',
    component: FormAccount,
  },
  {
    key: 'category',
    name: 'category-form',
    label: 'Category',
    caption: 'A heading to file spending under',
    icon: 'sell',
    tone: 'muted',
    component: FormCategory,
  },
]

// What happens to money, then what it is filed against.
const groups = [
  { title: 'Record', items: forms.slice(0, 3) },
  { title: 'Set up', items: forms.slice(3) },
]

const transferDialog = ref(null)

const page = usePage()
const active = shallowRef(null)
const loading = ref(null)
let openings = 0

// Asked for each time it opens rather than kept: a form's options are the accounts and
// categories as they are now, and today's date is its default.
const contextOf = async key => {
  const response = await fetch(`/forms/${key}`, { headers: { Accept: 'application/json' } })

  if (!response.ok) throw new Error(`The ${key} form did not load (${response.status})`)

  return response.json()
}

const open = async form => {
  // On the form's own page, its own dialog: a second one would answer the same name.
  if (form.name && page.props.meta?.form === form.name) {
    eventBus.formDialog.emit(form.name)

    return
  }

  loading.value = form.key

  try {
    const context = reactive(await contextOf(form.key))
    context.refresh = async () => Object.assign(context, await contextOf(form.key))

    active.value = { ...form, context, id: ++openings }
    await nextTick()

    if (form.key === 'transfer') transferDialog.value.show()
    else eventBus.formDialog.emit(form.name)
  } catch (error) {
    notifyFailure(error.message)
  } finally {
    loading.value = null
  }
}

// Gone on a visit to another page, where that page may carry the same form; kept through a
// save, which lands back on the page it was opened over.
let path = window.location.pathname

const stopListening = router.on('navigate', event => {
  const next = new URL(event.detail.page.url, window.location.origin).pathname

  if (next !== path) active.value = null

  path = next
})

onUnmounted(stopListening)
</script>
