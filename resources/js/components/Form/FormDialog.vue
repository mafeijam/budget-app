<template>
  <!-- Full screen on a phone, where a dialog with a margin round it left the fields a strip
       to scroll and Submit somewhere below them. -->
  <q-dialog
    v-model="dialog"
    :maximized="narrow"
    :transition-show="narrow ? 'slide-up' : 'jump-down'"
    :transition-hide="narrow ? 'slide-down' : 'jump-up'"
    :no-backdrop-dismiss="form.isDirty"
    :no-esc-dismiss="form.isDirty"
    @hide="$emit('hide-form')"
  >
    <q-card flat class="card-form-dialog" :class="{ 'card-form-dialog--full': narrow }">
      <q-card-section :class="{ 'q-pt-sm q-pb-xs': narrow }">
        <div class="row items-center no-wrap q-gutter-x-sm">
          <div class="text-h6 text-grey-9 text-weight-bold">
            {{ title }}
          </div>
          <q-space />
          <slot v-if="!narrow" name="header" />
          <q-btn flat round color="grey-6" icon="close" @click="dialog = false" />
        </div>
        <!-- Its own row on a phone, where beside the title it squeezed the title onto two. -->
        <!-- Pulled left by the button's own padding, so its icon lines up under the title. -->
        <div v-if="narrow && $slots.header" class="card-form-dialog__header-row">
          <slot name="header" />
        </div>
        <slot name="subheader" />
      </q-card-section>

      <q-separator v-if="narrow" />

      <q-card-section
        class="scroll card-form-dialog__body"
        :class="{ 'card-form-height': fullHeight && !narrow }"
      >
        <slot />
      </q-card-section>

      <q-card-section v-if="$page.props.message_csrf">
        <div class="app-note app-note--warning">
          {{ $page.props.message_csrf }}
        </div>
      </q-card-section>

      <q-separator v-if="separator || narrow" :inset="!narrow" />

      <q-card-actions :class="narrow ? 'q-px-md q-pt-xs' : 'q-pa-md'">
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
                :class="{ 'full-width q-mt-sm text-subtitle1': narrow }"
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
