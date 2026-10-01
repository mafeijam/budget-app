<template>
  <!-- The L of Ledger as a chart's two axes, a line rising inside it. Drawn rather than a
       Material icon, so the app has a mark of its own; public/favicon.svg is the same. -->
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 64 64"
    role="img"
    aria-label="Ledger"
    class="app-logo"
  >
    <rect width="64" height="64" rx="16" fill="#0f172a" />
    <path
      d="M18 14V46H50"
      fill="none"
      stroke="#fff"
      stroke-width="7"
      stroke-linecap="round"
      stroke-linejoin="round"
    />
    <path
      d="M26 38L33 30L39 34L48 21"
      fill="none"
      stroke="#34d399"
      stroke-width="5"
      stroke-linecap="round"
      stroke-linejoin="round"
    />
  </svg>
</template>

<script setup>
defineProps({
  size: { type: [Number, String], default: 32 },
})
</script>
