<template>
  <!-- A no-wrap row: two bare sibling q-btns wrap onto two lines in a narrow cell. -->
  <div class="row items-center justify-end no-wrap app-table-actions">
    <q-btn icon="edit" flat color="grey-7" dense class="q-mr-sm" @click="setEdit(cell.row)">
      <q-tooltip :delay="500" :offset="[0, 6]">Edit</q-tooltip>
    </q-btn>
    <q-btn
      icon="delete"
      flat
      color="negative"
      dense
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
