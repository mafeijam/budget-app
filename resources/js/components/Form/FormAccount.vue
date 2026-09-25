<template>
  <FormDialog :name="$page.props.meta.form" :title="title" @hide-form="resetEdit">
    <q-form :id="$page.props.meta.form" class="row q-col-gutter-md" @submit="submit(target)">
      <q-input
        v-model="form.name"
        class="col-6"
        label="Name"
        filled
        :error="!!form.errors.name"
        :error-message="form.errors.name"
        autofocus
      />
      <q-input
        v-model="form.ccy"
        class="col-6"
        label="CCY"
        filled
        :error="!!form.errors.ccy"
        :error-message="form.errors.ccy"
      />
      <q-select
        v-model="form.type"
        :options="['cash', 'card', 'security']"
        class="col-6"
        label="Type"
        filled
        :error="!!form.errors.type"
        :error-message="form.errors.type"
      />
      <q-select
        v-model="form.status"
        :options="['active', 'inactive']"
        class="col-6"
        label="Status"
        filled
        :error="!!form.errors.status"
        :error-message="form.errors.status"
      />

      <template v-if="form.type === 'card'">
        <q-input
          v-model="form.meta_data.due"
          class="col-6"
          label="Due date"
          filled
          type="number"
          :error="!!form.errors['meta_data.due']"
          :error-message="form.errors['meta_data.due']"
        />
      </template>
    </q-form>
  </FormDialog>
</template>

<script setup>
const pagination = inject('pagination')

const { schema, form } = useFormEmpty()
const { target, resetEdit } = useEdit(form)
const submit = useSubmit(form, pagination)

const title = computed(() => {
  return target.value ? 'edit account' : 'create new account'
})

useWatchTarget(target, schema, form)

provide('form', form)
</script>
