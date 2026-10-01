<template>
  <q-dialog
    :model-value="modelValue"
    transition-show="jump-down"
    transition-hide="jump-up"
    @update:model-value="value => emit('update:modelValue', value)"
  >
    <q-card class="app-peek">
      <q-card-section class="row items-start no-wrap q-pb-sm">
        <div class="col">
          <div class="row items-center no-wrap">
            <span v-if="colour" class="app-peek__swatch q-mr-sm" :style="{ background: colour }" />
            <div
              class="text-subtitle1 text-weight-medium"
              :class="{ 'text-italic text-grey-7': muted }"
            >
              {{ title }}
            </div>
          </div>
          <div class="text-caption text-grey-7">{{ subtitle }}</div>
        </div>
        <q-btn v-close-popup flat round dense icon="close" color="grey-7" class="app-peek__close" />
      </q-card-section>

      <q-card-section class="q-pt-none">
        <div class="row items-baseline no-wrap">
          <div class="money app-peek__total">{{ money(total) }}</div>
          <div class="text-caption text-grey-7 q-ml-sm">
            {{ unit }} · {{ count }} {{ noun }}{{ count === 1 ? '' : 's' }}
          </div>
        </div>
        <div v-if="notice" class="app-peek__note text-caption q-mt-xs">{{ notice }}</div>
      </q-card-section>

      <div class="app-peek__list">
        <div v-for="row in rows" :key="row.id" class="app-peek__row">
          <div class="ellipsis text-grey-9">
            {{ row.label }}
            <q-badge v-if="row.tag" class="app-tint app-tint--muted q-ml-xs" :label="row.tag" />
          </div>
          <div class="text-right money">
            <div class="text-weight-medium text-grey-9">{{ money(row.amount) }}</div>
            <div v-if="row.native" class="text-caption text-grey-6">{{ row.native }}</div>
            <div v-if="row.missing" class="text-caption text-negative">{{ row.missing }}</div>
          </div>
        </div>
        <div v-if="more" class="text-caption text-grey-6 q-pa-md">{{ more }}</div>
      </div>

      <q-separator />
      <q-card-actions align="right">
        <q-btn
          flat
          no-caps
          color="primary"
          class="app-peek__open"
          icon="open_in_new"
          label="Open in Transactions"
          @click="emit('open')"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
// A list of what is behind a figure, in the order it is given: a title, the total, and a
// name and an amount to a line. Shown only once its rows are in -- see usePeek().
defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: '' },
  muted: { type: Boolean, default: false },
  subtitle: { type: String, default: '' },
  colour: { type: String, default: null },
  total: { type: String, default: '0' },
  unit: { type: String, default: '' },
  count: { type: Number, default: 0 },
  noun: { type: String, default: 'transaction' },
  notice: { type: String, default: '' },
  more: { type: String, default: '' },
  // { id, label, amount, tag?, native?, missing? }
  rows: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:modelValue', 'open'])

const money = useMoney()
</script>
