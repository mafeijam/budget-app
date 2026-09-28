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
    :disable="!!refusal"
    @click="destroy(cell.row)"
  >
    <!--
      The server's reason for refusing, sent rather than composed here, so the two
      cannot disagree. Disabled rather than hidden: the row is still there and the
      button still says what stopped it, which a missing button does not.

      Quasar's QBtn sets no native disabled attribute, only aria-disabled and a
      click handler that stops the event, so the tooltip still opens over a
      disabled button -- which is what makes this worth saying, because the obvious
      way to do it is hide the button and leave the user no explanation at all.
    -->
    <q-tooltip :delay="500" :offset="[0, 6]">{{ refusal || 'delete' }}</q-tooltip>
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

// Why the server would refuse this row, or null when it would not. Present for a
// charge in a settled card statement, which is the one delete here that has an
// answer beyond "gone forever".
//
// The refusal is repeated in destroy(). A disabled button is a stale page and a
// direct request away from being wrong, and it is the server's side that is the
// actual rule.
const refusal = computed(() => usePage().props.refusals?.[props.cell.row.id] ?? null)

const { loading, destroy } = useDestroy(pagination)
const { setEdit } = useEdit()
</script>
