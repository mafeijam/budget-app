<template>
  <q-btn icon="edit" flat color="green-7" dense class="q-mr-sm" @click="setEdit(cell.row)">
    <q-tooltip :delay="500" :offset="[0, 6]">edit</q-tooltip>
  </q-btn>
  <q-btn
    icon="delete"
    flat
    color="pink-7"
    dense
    :loading="loading === cell.row.id"
    @click="destroy(cell.row.id)"
  >
    <q-tooltip :delay="500" :offset="[0, 6]">delete</q-tooltip>
  </q-btn>
</template>

<script setup>
const props = defineProps({
  cell: {
    type: Object,
    default: Object,
  },
})

const pagination = inject('pagination')

const { loading, destroy } = useDestroy(pagination)
const { setEdit } = useEdit()
</script>
