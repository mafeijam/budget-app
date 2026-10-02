<template>
  <q-card-section v-if="!enrolled">
    <div class="text-subtitle1 text-weight-medium text-grey-9">No key phone is set up yet.</div>
  </q-card-section>

  <q-card-section v-else class="column items-center q-gutter-sm text-center">
    <div class="text-subtitle1 text-weight-medium text-grey-9">Scan with your phone</div>
    <div>Open your phone's camera, scan the code, and approve this sign-in.</div>
    <img :src="qr" alt="Sign-in QR code" width="240" height="240" />
    <div>
      Check your phone shows <span class="text-weight-bold text-grey-9 money">{{ code }}</span>
    </div>
    <div v-if="status === 'approved'" class="text-positive">Approved, signing in…</div>
    <div v-else class="text-caption text-grey-6">A new code in {{ secondsLeft }}s</div>
    <div v-if="denied" class="app-note app-note--negative full-width">
      Your phone denied the last sign-in. This is a new code.
    </div>
  </q-card-section>
</template>

<script setup>
import { usePoll } from '@inertiajs/vue3'
import plain from '../layout-plain.vue'

defineOptions({ layout: plain })

const props = defineProps({
  enrolled: { type: Boolean, default: false },
  qr: { type: String, default: '' },
  code: { type: String, default: '' },
  status: { type: String, default: 'pending' },
  expiresIn: { type: Number, default: 0 },
  denied: { type: Boolean, default: false },
})

// Each reload brings the server's own count; the clock here only ticks it down between them.
const now = useTimestamp({ interval: 1000 })
const received = ref(Date.now())
watch(
  () => props.expiresIn,
  () => (received.value = Date.now()),
)
const secondsLeft = computed(() =>
  Math.max(0, props.expiresIn - Math.floor((now.value - received.value) / 1000)),
)

const { stop } = usePoll(2000, { showProgress: false }, { autoStart: props.enrolled })

watch(
  () => props.status,
  status => {
    if (status !== 'approved') return
    stop()
    router.post('/login')
  },
  { immediate: true },
)
</script>
