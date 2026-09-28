import { Dialog } from 'quasar'

// Imported, not auto-registered: the component resolver finds tags in templates, and
// this one is handed to Dialog.create() as an object.
import DeleteDialog from '../components/DeleteDialog.vue'

const editing = ref(new Map())

export function useEdit(form) {
  const meta = usePage().props.meta
  const target = computed(() => editing.value.get(meta.form))

  function setEdit(val) {
    editing.value.set(meta.form, val)
    eventBus.formDialog.emit(meta.form)
  }

  function resetEdit() {
    editing.value.delete(meta.form)
    form?.reset()
    form?.clearErrors()
  }

  return {
    setEdit,
    resetEdit,
    editing,
    target,
  }
}

export function useSubmit(form, pagination) {
  const meta = usePage().props.meta

  return function (target) {
    const [method, targetUrl] = target ? ['put', `${meta.path}/${target.id}`] : ['post', meta.path]

    form[method](targetUrl, {
      preserveScroll: true,
      onBefore: () => form.clearErrors(),
      onSuccess: resp => {
        notifySuccess()
        syncPagination(pagination, resp)
        eventBus.formDialog.emit(meta.form, { hide: true })
        form.reset()
        form.clearErrors()
      },
    })
  }
}

export function useDestroy(pagination) {
  const loading = ref(null)
  const meta = usePage().props.meta

  // A delete is one click away from a row somebody was reading, and there is nothing
  // to undo it with: no trash, no history, no restore. So it is confirmed -- and the
  // confirmation names the row, because "are you sure?" over an unnamed row is a
  // reflex rather than a decision.
  //
  // What each page deletes, in the singular. A map rather than anything worked out
  // from the path, because the path is a URL and turning a plural segment into an
  // English noun is a guess that breaks the first time a page is named irregularly.
  const things = {
    '/transactions': 'transaction',
    '/accounts': 'account',
    '/categories': 'category',
  }

  // row.description for a transaction, row.name for an account or a category, and the
  // id for anything that has neither -- so the dialog can never end up quoting
  // nothing at all, which would be the one message worth not showing.
  const labelOf = row => row.description || row.name || row.id

  // The other half of a card settlement, named the way the table names a row: what it
  // is, whose it is, when, and how much. Every field is one the user can see on the
  // row they clicked, so the two can be compared rather than taken on trust.
  //
  // A wider description than labelOf gives, and deliberately: the three tables carry
  // different columns and a category has no date or amount at all, but the counterpart
  // of a settlement row is always a transaction and always has all four. Only a paired
  // delete reaches this, which is the only time a second row exists to name.
  const describe = row =>
    `[${labelOf(row)}] on ${row.account_name}, ${row.date}, ${row.amount} ${row.ccy}`

  // The request lives inside onOk and nowhere else, which is what makes this safe
  // rather than merely careful. Every other way out of a Quasar dialog -- the cancel
  // button, a click on the backdrop, the escape key, even a route change -- arrives
  // at onCancel, so there is exactly one path that deletes and no exit that can
  // delete by accident.
  //
  // DeleteDialog keeps the three things Quasar's default dialog was configured for:
  // focus on cancel, dismissal as a cancel, and the message as text rather than markup.
  function destroy(row) {
    // What deleting this row would also delete, from the page props. Null for
    // everything but the two halves of a card settlement, which are the only rows in
    // this app that are really one row: a settlement is a payment on the card and a
    // transfer out of the bank, written together and deleted together, so removing
    // one has to remove the other or the card says it was paid while the bank says
    // the money is still there.
    //
    // Read at click time rather than once, because the prop is rebuilt per visit and
    // a dialog opened after a delete has to reflect the list it was opened from.
    const other = usePage().props.linked?.[row.id]

    Dialog.create({
      component: DeleteDialog,
      componentProps: {
        // Naming the settlement rather than the row when there is one: the thing being
        // undone is a statement, and the row clicked is only the half of it the list
        // happened to show.
        //
        // A trade's pair is the trade and its cash; a dividend's is the dividend and its
        // cash. The server says which by kind rather than this restating which types are
        // trades -- a dividend is a deposit and not a trade, so a list written here would
        // call it a trade and send the user to a picker holding only buys and sells.
        title: other
          ? other.kind === 'trade'
            ? 'delete trade'
            : other.kind === 'dividend'
              ? 'delete dividend'
              : 'delete card settlement'
          : `delete ${things[meta.path] ?? 'row'}`,
        message: other
          ? other.kind === 'trade'
            ? `${describe(other)} is this trade's cash side. Both rows will be deleted permanently.`
            : `${describe(other)} is the other half of this settlement. Both rows will be deleted permanently.`
          : `[${labelOf(row)}] will be deleted permanently.`,
      },
    }).onOk(() => {
      router.delete(`${meta.path}/${row.id}`, {
        preserveScroll: true,
        preserveState: true,
        onBefore: () => (loading.value = row.id),
        onSuccess: resp => {
          notifySuccess()
          syncPagination(pagination, resp)
        },
        onFinish: () => (loading.value = null),
      })
    })
  }

  return { loading, destroy }
}

export function useFormEmpty() {
  const page = usePage()
  const schema = useCloneForm(page.props.formEmpty)
  const form = useForm(schema)
  return {
    schema,
    form,
  }
}

export function useCloneForm(object) {
  return JSON.parse(JSON.stringify(object))
}

export function useWatchTarget(target, schema, form) {
  // Only the target/schema reset lives here. What has to happen when a *field*
  // changes is form-specific -- a securities account carries a settlement link and
  // a transaction carries a bag of type-specific fields -- so those watchers sit
  // with the form that owns the fields rather than in a shared helper that has to
  // know about all of them.
  watch(target, val => {
    form.defaults(val || schema)
    form.reset()
  })
}
