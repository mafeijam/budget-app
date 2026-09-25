<template>
  <div class="column q-gutter-md">
    <pre>{{ categories }}</pre>

    <FormTransaction :options="options" />

    <AppTable :rows="[]" :columns="[]" title="Category">
      <template #top>
        <div class="row full-width">
          <div class="text-h6 text-weight-medium">Transaction</div>
          <q-space />
          <div>
            <CreateBtn />
          </div>
        </div>
      </template>

      <template #body-cell-action="cell">
        <q-td :props="cell">
          <AppTableActions :cell="cell" />
        </q-td>
      </template>
    </AppTable>
  </div>
</template>

<script setup>
defineProps({
  options: { type: Array, default: Array },
})
</script>
