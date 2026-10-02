<template>
  <q-card-section v-if="!isKey" class="column q-gutter-sm">
    <div class="text-subtitle1 text-weight-medium text-grey-9">This phone is not a key</div>
    <div>Only a phone set up as a key can approve a sign-in.</div>
  </q-card-section>

  <q-card-section v-else-if="!request" class="column q-gutter-sm">
    <div class="text-subtitle1 text-weight-medium text-grey-9">This code has expired</div>
    <div>Scan the new code on the sign-in page.</div>
  </q-card-section>

  <q-card-section v-else-if="request.status === 'approved'" class="column q-gutter-sm">
    <div class="text-subtitle1 text-weight-medium text-positive">Approved</div>
    <div>{{ request.device }} is signing in.</div>
  </q-card-section>

  <q-card-section v-else-if="request.status === 'denied'" class="column q-gutter-sm">
    <div class="text-subtitle1 text-weight-medium text-grey-9">Denied</div>
    <div>{{ request.device }} was not signed in.</div>
  </q-card-section>

  <q-card-section v-else class="column q-gutter-md">
    <div class="text-subtitle1 text-weight-medium text-grey-9">Sign in {{ request.device }}?</div>
    <div>
      <div v-if="request.ip" class="text-grey-7 q-mb-xs">From {{ request.ip }}</div>
      <div>
        Code
        <span class="text-h5 text-weight-bold text-grey-9 money q-ml-xs">{{ request.code }}</span>
      </div>
    </div>
    <div class="text-caption text-grey-7">
      Approve only if the computer in front of you shows the same code.
    </div>
    <div class="row no-wrap">
      <q-btn
        outline
        color="grey-8"
        label="Deny"
        class="col"
        :disable="sending"
        @click="answer(false)"
      />
      <q-btn
        unelevated
        color="primary"
        label="Approve"
        class="col q-ml-sm"
        :loading="sending"
        @click="answer(true)"
      />
    </div>
  </q-card-section>
</template>

<script setup>
import plain from '../layout-plain.vue'

defineOptions({ layout: plain })

const props = defineProps({
  token: { type: String, required: true },
  isKey: { type: Boolean, default: false },
  request: { type: Object, default: null },
})

const sending = ref(false)

const answer = approve =>
  router.post(
    `/approve/${props.token}`,
    { approve },
    {
      onStart: () => (sending.value = true),
      onFinish: () => (sending.value = false),
    },
  )
</script>
