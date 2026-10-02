<template>
  <!-- Controls inside the chart's toolbar, not a toolbar of their own, laid out as the net
       worth page lays out its window: reset, then the value stepped a month either side and
       opened as a month grid from the middle. -->
  <div class="row items-center no-wrap">
    <q-btn
      flat
      dense
      round
      size="sm"
      icon="restart_alt"
      color="grey-8"
      :disable="last === latest"
      @click="$emit('choose', latest)"
    >
      <q-tooltip :delay="500" :offset="[0, 6]">Back to the last {{ months }} months</q-tooltip>
    </q-btn>

    <q-separator vertical inset class="q-mx-sm" />

    <q-btn
      flat
      dense
      round
      size="sm"
      icon="chevron_left"
      color="grey-8"
      :disable="last <= earliest"
      @click="$emit('choose', shiftMonth(last, -1))"
    >
      <q-tooltip :delay="500" :offset="[0, 6]">{{ rangeEnding(shiftMonth(last, -1)) }}</q-tooltip>
    </q-btn>
    <!-- The grid picks the window's last month, as the range reads "up to" it and the reset is
         the window ending this month; the twelve before it follow. -->
    <q-btn
      flat
      dense
      no-caps
      class="app-toolbar__pick app-toolbar__period q-px-sm"
      icon-right="expand_more"
      @click="open"
    >
      <span
        class="text-body2 text-weight-bold"
        :class="last === latest ? 'text-grey-9' : 'text-primary'"
      >
        {{ rangeEnding(last) }}
      </span>
      <q-menu :offset="[0, 8]">
        <q-date
          :key="opens"
          :model-value="browsing ?? last"
          :navigation-min-year-month="earliest"
          :navigation-max-year-month="latest"
          default-view="Months"
          years-in-month-view
          emit-immediately
          mask="YYYY-MM"
          minimal
          color="primary"
          @update:model-value="chooseMonth"
        />
      </q-menu>
    </q-btn>
    <q-btn
      flat
      dense
      round
      size="sm"
      icon="chevron_right"
      color="grey-8"
      :disable="last >= latest"
      @click="$emit('choose', shiftMonth(last, 1))"
    >
      <q-tooltip :delay="500" :offset="[0, 6]">{{ rangeEnding(shiftMonth(last, 1)) }}</q-tooltip>
    </q-btn>
  </div>
</template>

<script setup>
const props = defineProps({
  // Each a month, YYYY-MM: the window's last, and the furthest back and forward it can end.
  last: { type: String, default: '' },
  earliest: { type: String, default: '' },
  latest: { type: String, default: '' },
  months: { type: Number, default: 12 },
  label: { type: Function, required: true },
  shiftMonth: { type: Function, required: true },
})

const emit = defineEmits(['choose'])

const rangeEnding = month =>
  `${props.label(props.shiftMonth(month, 1 - props.months))} – ${props.label(month)}`

// Remounted on every open and every emit: q-date reads its view once at mount and drops to a
// day grid after a month is picked, which flashed days on a picker that never offers any.
// The month on screen is kept across the remount, or a year arrow would snap back to the
// window's own year.
const opens = ref(0)
const browsing = ref(null)

const open = () => {
  opens.value++
  browsing.value = null
}

// The year arrows and the year grid emit too, carrying the month already selected, so only a
// month picked is a choice -- the net worth picker's rule, for the same reason.
const chooseMonth = (month, reason) => {
  browsing.value = month
  opens.value++

  if (reason === 'month' && month) emit('choose', month)
}
</script>
