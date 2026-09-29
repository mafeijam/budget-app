<template>
  <!-- A no-wrap row: two bare sibling q-btns wrap onto two lines in a narrow cell. -->
  <div class="row items-center justify-end no-wrap app-table-actions">
    <q-btn icon="edit" flat round size="sm" color="grey-7" dense @click="setEdit(cell.row)">
      <q-tooltip :delay="500" :offset="[0, 6]">Edit</q-tooltip>
    </q-btn>
    <!-- Grey until hovered: a red icon on every row outshouts the figures. -->
    <q-btn
      icon="delete"
      flat
      round
      size="sm"
      color="grey-7"
      dense
      class="app-table-actions__delete"
      :loading="loading === cell.row.id"
      :disable="!!refusal"
      @click="destroy(cell.row)"
    >
      <!-- QBtn sets no native disabled attribute, so the tooltip still opens when disabled. -->
      <q-tooltip :delay="500" :offset="[0, 6]">{{ refusal || 'Delete' }}</q-tooltip>
    </q-btn>
  </div>
</template>

<script setup>
const props = defineProps({
  cell: {
    type: Object,
    default: Object,
  },
})

const pagination = inject('pagination')

const refusal = computed(() => usePage().props.refusals?.[props.cell.row.id] ?? null)

const { loading, destroy } = useDestroy(pagination)
const { setEdit } = useEdit()
</script>

<style scoped>
.app-table-actions__delete:not(.disabled):hover {
  color: var(--q-negative) !important;
}
</style>
