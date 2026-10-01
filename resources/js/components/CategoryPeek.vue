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
            <span class="app-peek__swatch q-mr-sm" :style="{ background: colour }" />
            <div
              class="text-subtitle1 text-weight-medium"
              :class="{ 'text-italic text-grey-7': !name }"
            >
              {{ name ?? 'No category' }}
            </div>
          </div>
          <div class="text-caption text-grey-7">{{ period }} · spending</div>
        </div>
        <q-btn v-close-popup flat round dense icon="close" color="grey-7" class="app-peek__close" />
      </q-card-section>

      <template v-if="result">
        <q-card-section class="q-pt-none">
          <div class="row items-baseline no-wrap">
            <div class="money app-peek__total">{{ money(result.total) }}</div>
            <div class="text-caption text-grey-7 q-ml-sm">
              {{ result.ccy }} · {{ result.count }} transaction{{ result.count === 1 ? '' : 's' }}
            </div>
          </div>
          <div v-if="result.unconverted" class="app-peek__note text-caption q-mt-xs">
            {{ result.unconverted }} without a rate on their day
            {{ result.unconverted === 1 ? 'is' : 'are' }} left out of the total.
          </div>
        </q-card-section>

        <!-- Largest first. -->
        <div class="app-peek__list">
          <div v-for="row in rows" :key="row.id" class="app-peek__row">
            <div class="ellipsis text-grey-9">
              {{ row.description }}
              <q-badge
                v-if="row.one_off"
                class="app-tint app-tint--muted q-ml-xs"
                label="one-off"
              />
            </div>
            <div class="text-right money">
              <div class="text-weight-medium text-grey-9">{{ money(row.base ?? row.amount) }}</div>
              <div v-if="row.ccy !== result.ccy" class="text-caption text-grey-6">
                {{ row.ccy }} {{ money(row.amount) }}
              </div>
              <div v-if="row.base === null" class="text-caption text-negative">no rate</div>
            </div>
          </div>
          <div v-if="result.count > result.rows.length" class="text-caption text-grey-6 q-pa-md">
            The largest {{ result.rows.length }} of {{ result.count }}: the total is of all of them.
          </div>
        </div>
      </template>

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
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  // The rows of the tile asked about, read before the dialog is shown: see the page's peek().
  result: { type: Object, default: null },
  name: { type: String, default: null },
  colour: { type: String, default: '#94a3b8' },
  period: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'open'])

const money = useMoney()

// The figure a row is listed by: its base amount, or its own where there is no conversion.
const figure = row => Number(row.base ?? row.amount)

const rows = computed(() => [...(props.result?.rows ?? [])].sort((a, b) => figure(b) - figure(a)))
</script>
