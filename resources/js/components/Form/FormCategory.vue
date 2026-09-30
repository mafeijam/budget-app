<template>
  <FormDialog :name="$page.props.meta.form" :title="title" @hide-form="resetEdit">
    <q-form :id="$page.props.meta.form" class="row q-col-gutter-md" @submit="submit(target)">
      <q-input
        v-model="form.name"
        class="col-12"
        label="Name"
        outlined
        :error="!!form.errors.name"
        :error-message="form.errors.name"
        autofocus
      >
        <template #prepend>
          <q-icon name="label" color="grey-6" />
        </template>
      </q-input>
    </q-form>
  </FormDialog>
</template>

<script setup>
const pagination = inject('pagination')

const { schema, form } = useFormEmpty()

const { target, resetEdit } = useEdit(form)
const submit = useSubmit(form, pagination)

const title = computed(() => {
  return target.value ? 'Edit category' : 'Create new category'
})

useWatchTarget(target, schema, form)

provide('form', form)
</script>
