<template>
  <slot />
</template>

<script setup>
// A form opened over a page that is not its own: it reads this context rather than the
// page's props (useFormContext()), and it has no list to page through.
const props = defineProps({
  context: { type: Object, required: true },
})

provide('formContext', props.context)
provide('pagination', null)
</script>
