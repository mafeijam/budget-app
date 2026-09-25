<template>
  <FormDialog :name="$page.props.meta.form" :title="title" @hide-form="resetEdit">
    <q-form :id="$page.props.meta.form" class="row q-col-gutter-md" @submit="submit(target)">
      <q-input
        v-model="form.name"
        class="col-12"
        label="Name"
        filled
        :error="!!form.errors.name"
        :error-message="form.errors.name"
        autofocus
      />
      <q-select
        v-model="form.account_id"
        :options="options.accounts"
        class="col-6"
        label="Account"
        filled
        :error="!!form.errors.account"
        :error-message="form.errors.account"
      />
    </q-form>
  </FormDialog>
</template>

<script setup>
defineProps({
  options: { type: Object, default: Object },
})

const pagination = inject('pagination')

const { schema, form } = useFormEmpty()

const { target, resetEdit } = useEdit(form)
const submit = useSubmit(form, pagination)

const title = computed(() => {
  return target.value ? 'edit transaction' : 'create new transaction'
})

useWatchTarget(target, schema, form)

provide('form', form)
</script>
