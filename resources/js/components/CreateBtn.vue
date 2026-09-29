<template>
  <q-btn
    class="text-weight-bold app-btn"
    unelevated
    no-caps
    icon="add"
    label="Add"
    @click="$eventBus.formDialog.emit($page.props.meta.form)"
  />
</template>

<script setup></script>
