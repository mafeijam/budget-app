<template>
  <q-btn
    icon="add"
    class="text-weight-bold"
    color="indigo-1"
    text-color="blue-9"
    unelevated
    label="add"
    @click="$eventBus.formDialog.emit($page.props.meta.form)"
  />
</template>

<script setup></script>
