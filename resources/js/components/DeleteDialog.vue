<template>
  <!--
    Not persistent, so escape and a click outside both dismiss it -- each arrives at
    onDialogCancel, the direction in which nothing is deleted. The message is text
    interpolation, never v-html: it quotes a description the user typed.
  -->
  <q-dialog ref="dialogRef" @hide="onDialogHide">
    <q-card style="width: 460px; max-width: 90vw">
      <q-card-section class="row items-start no-wrap q-pb-sm">
        <q-avatar
          icon="delete_outline"
          color="negative"
          text-color="white"
          size="44px"
          class="q-mr-md"
        />
        <div>
          <div class="text-h6 text-weight-bold text-grey-9 first-letter-upper">{{ title }}</div>
          <div class="text-body2 text-grey-8 q-mt-xs">{{ message }}</div>
        </div>
      </q-card-section>

      <q-card-actions align="right" class="q-pa-md">
        <!--
          autofocus on cancel, because the dialog focuses the first autofocus element: a
          user reaching for the keyboard who presses enter without reading must land on
          the button that does nothing.
        -->
        <q-btn autofocus flat no-caps color="grey-8" label="Cancel" @click="onDialogCancel" />
        <q-btn
          unelevated
          no-caps
          class="text-weight-bold app-btn app-btn--negative"
          label="Delete"
          @click="onDialogOK"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
// Imported, unlike almost everything here: vite.config.js auto-imports useQuasar and
// not the Dialog plugin's own composable.
import { useDialogPluginComponent } from 'quasar'

defineProps({
  title: { type: String, default: '' },
  message: { type: String, default: '' },
})

defineEmits([...useDialogPluginComponent.emits])

const { dialogRef, onDialogHide, onDialogOK, onDialogCancel } = useDialogPluginComponent()
</script>

<style scoped>
.first-letter-upper::first-letter {
  text-transform: uppercase;
}
</style>
