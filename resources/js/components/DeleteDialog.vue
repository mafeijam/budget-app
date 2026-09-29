<template>
  <!-- The message is interpolated, never v-html: it quotes a description the user typed. -->
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
        <!-- autofocus on Cancel, so an Enter pressed without reading deletes nothing. -->
        <q-btn autofocus flat no-caps color="grey-8" label="Cancel" @click="onDialogCancel" />
        <q-btn
          unelevated
          no-caps
          class="text-weight-bold app-btn app-btn--negative"
          icon="delete"
          label="Delete"
          @click="onDialogOK"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
// Imported: vite.config.js auto-imports useQuasar, not this.
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
