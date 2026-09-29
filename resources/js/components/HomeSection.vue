<template>
  <div>
    <div class="row items-baseline q-mb-sm">
      <div class="text-subtitle1 text-weight-medium">{{ title }}</div>
      <div
        v-if="total !== null && items.length"
        class="text-caption text-weight-medium money q-ml-sm"
        :class="negative ? 'text-negative' : 'text-grey-7'"
      >
        {{ base }} {{ money(total) }}
      </div>
      <q-space />
      <q-btn
        v-if="hidden"
        flat
        dense
        no-caps
        color="grey-7"
        class="text-caption q-px-sm"
        :icon-right="showEmpty ? 'expand_less' : 'expand_more'"
        :label="showEmpty ? 'Hide empty' : `${hidden} empty`"
        @click="showEmpty = !showEmpty"
      />
    </div>

    <div v-if="items.length" class="row q-col-gutter-md">
      <div v-for="item in shown" :key="item.key" class="col-12 col-sm-6 col-md-4 col-lg-3">
        <q-card
          flat
          bordered
          class="full-height app-home-link"
          :class="{ 'app-home-card--overdue': item.overdue }"
          @click="item.open"
        >
          <q-card-section class="q-py-sm">
            <div class="row items-center no-wrap">
              <q-icon :name="item.icon" size="xs" color="grey-6" class="q-mr-sm" />
              <div class="text-body2 text-weight-medium ellipsis">{{ item.name }}</div>
              <q-space />
              <q-badge outline color="grey-7" :label="item.ccy" />
            </div>
            <div v-if="item.badge" class="row items-center text-caption text-grey-7 q-mt-xs">
              {{ item.badge.prefix }}
              <q-badge :label="item.badge.label" :class="item.badge.class" class="q-ml-sm" />
            </div>
            <div class="text-h6 text-weight-bold money q-mt-xs" :class="item.valueClass">
              {{ money(item.value) }}
            </div>
            <div
              v-for="line in item.lines"
              :key="line.text"
              class="text-caption"
              :class="line.class ?? 'text-grey-7'"
            >
              {{ line.text }}
            </div>
          </q-card-section>
        </q-card>
      </div>
    </div>
    <div v-else class="text-grey-6">{{ empty }}</div>
  </div>
</template>

<script setup>
const props = defineProps({
  title: { type: String, required: true },
  // Every item's figure summed in the base currency, beside the title.
  total: { type: String, default: null },
  base: { type: String, default: 'HKD' },
  items: { type: Array, default: Array },
  negative: { type: Boolean, default: false },
  empty: { type: String, default: '' },
})

const money = useMoney()

// Folded away rather than dropped, so an account opened and not used yet is one click off.
const showEmpty = ref(false)

const hidden = computed(() => props.items.filter(item => item.empty).length)

const shown = computed(() => props.items.filter(item => showEmpty.value || !item.empty))
</script>
