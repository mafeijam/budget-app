<template>
  <q-card-section v-if="valid" class="column q-gutter-md">
    <div class="text-subtitle1 text-weight-medium text-grey-9">Make this phone a key</div>
    <div>
      This phone stays signed in, and signs in other devices by scanning the code on their sign-in
      page.
    </div>
    <q-btn
      unelevated
      color="primary"
      icon="key"
      label="Make this phone a key"
      :loading="sending"
      @click="enrol"
    />
  </q-card-section>

  <q-card-section v-else class="column q-gutter-sm">
    <div class="text-subtitle1 text-weight-medium text-grey-9">This link has expired</div>
    <div>A link works once, for {{ minutes }} minutes. Run this on the server for a new one:</div>
    <code class="bg-grey-2 q-pa-sm rounded-borders">php artisan login:enrol</code>
  </q-card-section>
</template>

<script setup>
import plain from '../layout-plain.vue'

defineOptions({ layout: plain })

const props = defineProps({
  token: { type: String, required: true },
  valid: { type: Boolean, default: false },
  minutes: { type: Number, required: true },
})

const sending = ref(false)

const enrol = () =>
  router.post(
    `/enrol/${props.token}`,
    {},
    {
      onStart: () => (sending.value = true),
      onFinish: () => (sending.value = false),
    },
  )
</script>
