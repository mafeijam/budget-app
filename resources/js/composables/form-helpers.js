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

  function destroy(id) {
    router.delete(`${meta.path}/${id}`, {
      preserveScroll: true,
      preserveState: true,
      onBefore: () => (loading.value = id),
      onSuccess: resp => {
        notifySuccess()
        syncPagination(pagination, resp)
      },
      onFinish: () => (loading.value = null),
    })
  }

  return {
    loading,
    destroy,
  }
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
