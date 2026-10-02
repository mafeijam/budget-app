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
        <div class="row items-center no-wrap q-gutter-x-sm">
          <div class="text-h6 text-grey-9 text-weight-bold">
            {{ title }}
          </div>
          <q-space />
          <slot v-if="!narrow" name="header" />
          <q-btn flat round color="grey-6" icon="close" @click="dialog = false" />
        </div>
        <!-- Its own row on a phone, where beside the title it squeezed the title onto two. -->
        <div v-if="narrow && $slots.header" class="q-mt-xs">
          <slot name="header" />
        </div>
        <slot name="subheader" />
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
            <div class="row items-center">
              <slot name="actions-start" />
              <q-space />
              <!-- The icon alone on a phone: with its word it no longer fit beside Save as
                   template and Submit, so the first keystroke pushed Submit onto a new row. -->
              <q-btn
                v-if="form.isDirty"
                class="q-ml-auto"
                :class="narrow ? 'q-mr-xs' : 'q-mr-sm'"
                color="grey-6 text-weight-bold"
                padding="sm md"
                flat
                no-caps
                icon="restart_alt"
                :label="narrow ? undefined : 'Reset'"
                aria-label="Reset"
                @click="(form.reset(), form.clearErrors())"
              />
              <!-- A row of its own on a phone, the width of a thumb's reach, under the two
                   above it so the Reset appearing moves nothing. -->
              <q-btn
                type="submit"
                class="text-weight-bold app-btn app-btn--positive"
                :class="{ 'full-width q-mt-md text-subtitle1': narrow }"
                :padding="narrow ? '12px md' : 'sm md'"
                unelevated
                no-caps
                icon="check"
                label="Submit"
                :loading="form.processing"
                :form="ctx.meta.form"
              />
            </div>
          </div>
        </slot>
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
// Its own page's props, or the Add menu's for it: see useFormContext().
const ctx = useFormContext()

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

const $q = useQuasar()
const narrow = computed(() => $q.screen.lt.sm)

eventBus.formDialog.on((name, payload = { hide: false }) => {
  if (name === props.name) {
    dialog.value = payload.hide ? false : true
  }
})
</script>
