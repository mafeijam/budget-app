<template>
  <q-btn dense flat round icon="logout" color="grey-8" aria-label="Sign out" @click="signOut">
    <q-tooltip>Sign out</q-tooltip>
  </q-btn>
</template>

<script setup>
const page = usePage()
const $q = useQuasar()

// Only the enrolled phone asks: signing out there ends the key, and getting it back takes the
// server. Everywhere else signing out costs a scan.
const signOut = () => {
  if (!page.props.keyPhone) return router.post('/logout')

  $q.dialog({
    title: 'Sign out of your key phone?',
    message:
      'This phone is your key. Signing out means running php artisan login:enrol on the server to get it back.',
    cancel: { flat: true, noCaps: true, color: 'grey-8', label: 'Cancel' },
    ok: { unelevated: true, noCaps: true, color: 'negative', label: 'Sign out' },
    focus: 'cancel',
  }).onOk(() => router.post('/logout'))
}
</script>
