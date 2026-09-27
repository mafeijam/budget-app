import { Dialog } from 'quasar'

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

  // The request lives inside onOk and nowhere else, which is what makes this safe
  // rather than merely careful. Every other way out of a Quasar dialog -- the cancel
  // button, a click on the backdrop, the escape key, even a route change -- arrives
  // at onCancel, so there is exactly one path that deletes and no exit that can
  // delete by accident.
  //
  // focus: 'cancel' because Quasar's default is the other one: the ok button is the
  // one carrying data-autofocus, so a user reaching for the keyboard and pressing
  // enter without reading would delete the row. The focus belongs on the button that
  // does nothing.
  //
  // Not persistent, so escape and a stray click outside dismiss it -- both of which
  // are a cancel, the direction in which nothing is deleted.
  //
  // html stays off, which is the whole reason it is worth saying: the message quotes
  // a description the user typed, and html: true renders that as markup. Quasar
  // names it as an XSS route and here it would be one.
  function destroy(row) {
    Dialog.create({
      title: `delete ${things[meta.path] ?? 'row'}`,
      message: `[${labelOf(row)}] will be deleted permanently.`,
      // flat written out on cancel rather than left to Quasar, because the flat
      // default applies only when the option is a string, and the lowercase label
      // needs it to be an object -- otherwise both buttons come out raised and the
      // one that destroys is no heavier than the one that does not.
      ok: { label: 'delete', color: 'negative', unelevated: true, noCaps: true },
      cancel: { label: 'cancel', color: 'grey-7', flat: true, noCaps: true },
      focus: 'cancel',
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
