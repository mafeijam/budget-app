<template>
  <q-card-section class="column q-gutter-sm">
    <div class="text-subtitle1 text-weight-medium text-grey-9">Sign in with your phone</div>
    <div>Run this on the server, then open the link it prints on this device:</div>
    <code class="bg-grey-2 q-pa-sm rounded-borders">php artisan login:enrol</code>
  </q-card-section>
</template>

<script setup>
import plain from '../layout-plain.vue'

defineOptions({ layout: plain })
</script>
