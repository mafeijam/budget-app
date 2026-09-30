<template>
  <!-- The app's tinted button, as Fetch prices is, named for what it adds. -->
  <q-btn
    class="app-create-btn app-btn text-weight-bold"
    unelevated
    no-caps
    icon="add"
    :label="label"
    @click="$eventBus.formDialog.emit($page.props.meta.form)"
  />
</template>

<script setup>
defineProps({
  // What it adds, so the button says so: "New account", not a bare "Add".
  label: { type: String, default: 'Add' },
})
</script>
