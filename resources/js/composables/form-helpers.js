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
  watch(target, val => {
    form.defaults(val || schema)
    form.reset()
  })

  watch(
    () => form.type,
    (val, oldVal) => {
      if (oldVal && form.isDirty) {
        form.meta_data = useCloneForm(schema.meta_data)
      }
    },
  )
}
