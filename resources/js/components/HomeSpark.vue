<template>
  <!-- Stretched to the card's width: the stroke keeps its thickness however wide it is. -->
  <svg
    :viewBox="`0 0 ${width} ${height}`"
    preserveAspectRatio="none"
    class="app-home-spark"
    role="img"
    :aria-label="label"
  >
    <path :d="area" :fill="colour" fill-opacity="0.12" />
    <polyline
      :points="line"
      fill="none"
      :stroke="colour"
      stroke-width="1.75"
      stroke-linejoin="round"
      stroke-linecap="round"
      vector-effect="non-scaling-stroke"
    />
  </svg>
</template>

<script setup>
const props = defineProps({
  // Decimal strings, oldest first; numbers here only for the line's shape.
  values: { type: Array, default: Array },
  colour: { type: String, default: '#475569' },
  label: { type: String, default: '' },
})

const width = 300
const height = 40
const pad = 3

const numbers = computed(() => props.values.map(Number))

// Fitted to the figures, not from zero: the shape is the point, not the size.
const y = computed(() => {
  const max = Math.max(...numbers.value)
  const min = Math.min(...numbers.value)
  const span = max - min || Math.abs(max) || 1

  return value => pad + ((max - value) / span) * (height - pad * 2)
})

const sampled = computed(() => {
  const step = width / Math.max(numbers.value.length - 1, 1)

  return monotoneCurve(numbers.value, 0, step).map(([x, value]) => [x, y.value(value)])
})

const line = computed(() => sampled.value.map(([x, v]) => `${x},${v}`).join(' '))

const area = computed(() =>
  sampled.value.length
    ? `M0,${height} L${line.value.replaceAll(' ', ' L')} L${width},${height} Z`
    : '',
)
</script>
