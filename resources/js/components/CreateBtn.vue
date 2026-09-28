<template>
  <q-btn
    icon="add"
    class="text-weight-bold"
    color="primary"
    unelevated
    no-caps
    label="Add"
    @click="$eventBus.formDialog.emit($page.props.meta.form)"
  />
</template>

<script setup></script>
