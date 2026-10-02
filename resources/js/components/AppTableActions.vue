<template>
  <!-- A no-wrap row: bare sibling q-btns wrap onto two lines in a narrow cell. -->
  <div class="row items-center justify-end no-wrap app-table-actions">
    <!--
      Only on a row waiting to be posted, which no account, category or recurring rule
      is: canPost() is null for a row with no status, so this column is unchanged on
      every other page rather than carrying a button that cannot mean anything there.
    -->
    <q-btn
      v-if="postTo"
      icon="done"
      flat
      round
      size="sm"
      color="grey-7"
      dense
      class="app-table-actions__post"
      :loading="loading === cell.row.id"
      :disable="statusLocked"
      @click="post(cell.row)"
    >
      <!-- QBtn sets no native disabled attribute, so the tooltip still opens when disabled. -->
      <q-tooltip :delay="500" :offset="[0, 6]">{{
        statusLocked ? lock.message : 'Post'
      }}</q-tooltip>
    </q-btn>
    <q-btn
      icon="edit"
      flat
      round
      size="sm"
      color="grey-7"
      dense
      @click="edit ? edit(cell.row) : setEdit(cell.row)"
    >
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
      :loading="deleting === cell.row.id"
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
  // A row edited somewhere other than the page's form: a transfer's half opens its dialog.
  edit: { type: Function, default: null },
})

const pagination = inject('pagination')

const refusal = computed(() => usePage().props.refusals?.[props.cell.row.id] ?? null)

// The edit form's own lock. A settled statement's figures are fixed, status among them,
// so the shortcut is disabled with the same words rather than offering a save that is
// refused on save -- which, from a list of a hundred rows, would look like it had done
// nothing.
const lock = computed(() => usePage().props.editLocks?.[props.cell.row.id] ?? null)
const statusLocked = computed(() => lock.value?.fields.includes('status') ?? false)

const { loading, post, canPost } = usePost(pagination)
const postTo = computed(() => canPost(props.cell.row))

const { loading: deleting, destroy } = useDestroy(pagination)
const { setEdit } = useEdit()
</script>

<style scoped>
.app-table-actions__post:not(.disabled):hover {
  color: var(--q-positive) !important;
}

.app-table-actions__delete:not(.disabled):hover {
  color: var(--q-negative) !important;
}
</style>
