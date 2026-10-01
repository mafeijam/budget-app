<template>
  <!-- Split: the button adds a transaction, which is nearly every add, and the arrow has the
       rest, so the common one is a click rather than two. -->
  <q-btn-dropdown
    split
    unelevated
    no-caps
    dense
    class="app-btn text-weight-bold"
    icon="add"
    label="Transaction"
    :loading="loading !== null"
    content-class="app-add-menu"
    @click="open(forms[0])"
  >
    <q-list dense style="min-width: 200px">
      <q-item
        v-for="form in forms.slice(1)"
        :key="form.key"
        v-close-popup
        clickable
        @click="open(form)"
      >
        <q-item-section avatar>
          <q-icon :name="form.icon" size="xs" color="grey-7" />
        </q-item-section>
        <q-item-section>{{ form.label }}</q-item-section>
      </q-item>
    </q-list>
  </q-btn-dropdown>

  <!-- Keyed per opening, so a form starts from its own empty values every time. -->
  <FormContextHost v-if="active" :key="active.id" :context="active.context">
    <component :is="active.component" :options="active.context.options" />
  </FormContextHost>
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
    icon: 'receipt_long',
    component: FormTransaction,
  },
  {
    key: 'recurring',
    name: 'recurring-form',
    label: 'Recurring rule',
    icon: 'event_repeat',
    component: FormRecurring,
  },
  {
    key: 'account',
    name: 'account-form',
    label: 'Account',
    icon: 'account_balance',
    component: FormAccount,
  },
  {
    key: 'category',
    name: 'category-form',
    label: 'Category',
    icon: 'sell',
    component: FormCategory,
  },
]

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
  if (page.props.meta?.form === form.name) {
    eventBus.formDialog.emit(form.name)

    return
  }

  loading.value = form.key

  try {
    const context = reactive(await contextOf(form.key))
    context.refresh = async () => Object.assign(context, await contextOf(form.key))

    active.value = { ...form, context, id: ++openings }
    await nextTick()
    eventBus.formDialog.emit(form.name)
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
