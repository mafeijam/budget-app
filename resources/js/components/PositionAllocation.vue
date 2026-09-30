<template>
  <div v-if="slices.length">
    <div class="app-allocation" role="img" :aria-label="label">
      <div
        v-for="(slice, i) in slices"
        :key="slice.key"
        class="app-allocation__slice"
        :style="{ flexGrow: slice.value, background: slice.colour }"
        @mouseenter="hovered = i"
        @mouseleave="hovered = null"
      >
        <q-tooltip :offset="[0, 8]">
          <div class="text-weight-bold">{{ slice.label }}</div>
          <div v-if="slice.name">{{ slice.name }}</div>
          <div class="money">{{ base }} {{ money(slice.amount) }} · {{ percent(slice.share) }}</div>
          <div v-if="slice.members" class="text-caption">{{ slice.members }}</div>
        </q-tooltip>
      </div>
    </div>

    <!-- Every slice named with its share, since three of the hues are under 3:1 on white. -->
    <div class="row q-gutter-x-md q-gutter-y-xs q-mt-sm text-caption">
      <div
        v-for="(slice, i) in slices"
        :key="slice.key"
        class="row items-center no-wrap app-allocation__key"
        :class="{ 'app-allocation__key--dim': hovered !== null && hovered !== i }"
      >
        <span class="cash-flow-chart__swatch" :style="{ background: slice.colour }" />
        <span class="text-grey-9 text-weight-medium q-mr-xs">{{ slice.label }}</span>
        <span class="text-grey-7">{{ percent(slice.share) }}</span>
      </div>
    </div>

    <div v-if="left.length" class="text-caption text-grey-6 q-mt-xs">
      Not in the bar, for want of a price or a rate: {{ left.join(', ') }}.
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  // Open positions: {key, label, name, base} with base the market value in the base
  // currency, a decimal string, or null when it has no price or rate.
  holdings: { type: Array, default: () => [] },
  base: { type: String, default: 'HKD' },
})

const money = useMoney()

const hovered = ref(null)

// The dataviz reference palette's categorical slots in their fixed order, validated for
// the light surface; slot 8 is spent on nothing, since the ninth holding onwards fold
// into Others, in a neutral that is no holding's hue.
const palette = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7']
const others = '#94a3b8'

// Below this share a slice is too thin to point at, so it joins Others.
const smallest = 0.03

const priced = computed(() => props.holdings.filter(h => h.base !== null && Number(h.base) > 0))
const left = computed(() => props.holdings.filter(h => h.base === null).map(h => h.label))

// Numbers for the widths and the shares only; each amount shown is the server's string,
// Others' being the one sum, which is for a tooltip and marked by its share beside it.
const slices = computed(() => {
  const total = priced.value.reduce((sum, h) => sum + Number(h.base), 0)

  if (total <= 0) return []

  const ranked = [...priced.value].sort((a, b) => Number(b.base) - Number(a.base))
  const named = ranked.filter((h, i) => i < palette.length && Number(h.base) / total >= smallest)
  const rest = ranked.slice(named.length)

  const list = named.map((h, i) => ({
    key: h.key,
    label: h.label,
    name: h.name,
    value: Number(h.base),
    amount: h.base,
    share: Number(h.base) / total,
    colour: palette[i],
  }))

  if (rest.length) {
    const sum = rest.reduce((s, h) => s + Number(h.base), 0)

    list.push({
      key: 'others',
      label: `Others (${rest.length})`,
      value: sum,
      amount: sum.toFixed(2),
      share: sum / total,
      colour: others,
      members: rest.map(h => (h.name ? `${h.label} ${h.name}` : h.label)).join(', '),
    })
  }

  return list
})

const percent = share => `${(share * 100).toFixed(share < 0.1 ? 1 : 0)}%`

const label = computed(
  () =>
    `Share of market value: ${slices.value.map(s => `${s.label} ${percent(s.share)}`).join(', ')}`,
)
</script>
