<template>
  <q-dialog
    v-model="dialog"
    transition-show="jump-down"
    transition-hide="jump-up"
    :no-backdrop-dismiss="form.isDirty"
    :no-esc-dismiss="form.isDirty"
    @hide="$emit('hide-form')"
  >
    <q-card flat class="card-form-dialog">
      <q-card-section>
        <div class="row justify-between items-center">
          <div class="text-h6 text-grey-9 text-weight-bold">
            {{ title }}
          </div>
          <q-btn flat round color="grey-6" icon="close" @click="dialog = false" />
        </div>
      </q-card-section>

      <q-card-section class="scroll" :class="{ 'card-form-height': fullHeight }">
        <slot />
      </q-card-section>

      <q-card-section v-if="$page.props.message_csrf">
        <div class="app-note app-note--warning">
          {{ $page.props.message_csrf }}
        </div>
      </q-card-section>

      <q-separator v-if="separator" inset />

      <q-card-actions class="q-pa-md">
        <slot name="actions">
          <div class="col-12">
            <div class="row">
              <q-space />
              <q-btn
                v-if="form.isDirty"
                class="q-ml-auto q-mr-md"
                color="grey-6 text-weight-bold"
                padding="sm md"
                flat
                no-caps
                icon="undo"
                label="Reset"
                @click="(form.reset(), form.clearErrors())"
              />
              <q-btn
                type="submit"
                class="text-weight-bold"
                padding="sm md"
                color="primary"
                unelevated
                no-caps
                icon="done"
                label="Submit"
                :loading="form.processing"
                :form="$page.props.meta.form"
              />
            </div>
          </div>
        </slot>
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
const props = defineProps({
  name: {
    type: String,
    default: 'form-dialog',
  },
  title: {
    type: String,
    default: 'form',
  },
  fullHeight: {
    type: Boolean,
    default: false,
  },
  separator: {
    type: Boolean,
    default: false,
  },
})

defineEmits(['hide-form'])

const form = inject('form')

const dialog = ref(false)

eventBus.formDialog.on((name, payload = { hide: false }) => {
  if (name === props.name) {
    dialog.value = payload.hide ? false : true
  }
})
</script>
