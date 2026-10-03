<template>
  <!-- A select for the phone's filter sheet. Not a q-select: on a phone that opens Quasar's own
       dialog, grey, sized to 100vh -- which is taller than what a phone browser shows with its
       bars out, so the last options sat under them -- and with nothing marking what is chosen. -->
  <!-- The click on a wrapper: q-field takes its own and does not pass it on. -->
  <div role="button" tabindex="0" @click="open = true" @keydown.enter="open = true">
    <q-field
      :model-value="modelValue"
      :label="label"
      outlined
      stack-label
      class="app-picker-field cursor-pointer"
    >
      <template #control>
        <div class="ellipsis text-grey-9">{{ summary || 'Any' }}</div>
      </template>
      <template #append>
        <q-icon
          v-if="modelValue.length"
          name="cancel"
          class="cursor-pointer"
          @click.stop="$emit('update:modelValue', [])"
        />
        <q-icon name="expand_more" />
      </template>
    </q-field>
  </div>

  <q-dialog v-model="open" position="bottom">
    <q-card class="app-picker-sheet column no-wrap">
      <q-card-section class="row items-center no-wrap q-py-sm">
        <div class="text-subtitle1 text-weight-bold text-grey-9">{{ label }}</div>
        <q-space />
        <q-btn
          v-if="modelValue.length"
          flat
          no-caps
          color="grey-7"
          label="Clear"
          @click="$emit('update:modelValue', [])"
        />
        <q-btn v-close-popup flat no-caps color="primary" label="Done" />
      </q-card-section>

      <q-separator class="app-picker-sheet__rule" />

      <q-list class="app-picker-sheet__list col">
        <template v-for="group in groups" :key="group.title">
          <q-item-label v-if="group.title" header class="app-picker-sheet__head">
            {{ group.title }}
          </q-item-label>
          <q-item
            v-for="option in group.options"
            :key="option.value"
            v-ripple
            clickable
            class="app-picker-sheet__item"
            :class="{ 'app-picker-sheet__item--on': chosen.has(option.value) }"
            @click="toggle(option.value)"
          >
            <q-item-section>
              <q-item-label class="text-weight-medium">{{ option.label }}</q-item-label>
            </q-item-section>
            <q-item-section side>
              <q-icon
                :name="chosen.has(option.value) ? 'check_circle' : 'radio_button_unchecked'"
                :color="chosen.has(option.value) ? 'primary' : 'grey-5'"
                size="22px"
              />
            </q-item-section>
          </q-item>
        </template>
      </q-list>
    </q-card>
  </q-dialog>
</template>

<script setup>
const props = defineProps({
  modelValue: { type: Array, default: Array },
  // {label, value}, and a `type` to group under where the options have one (accounts).
  options: { type: Array, default: Array },
  label: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

const open = ref(false)

const chosen = computed(() => new Set(props.modelValue))

const toggle = value =>
  emit(
    'update:modelValue',
    chosen.value.has(value)
      ? props.modelValue.filter(item => item !== value)
      : [...props.modelValue, value],
  )

const typeTitles = { cash: 'Cash', card: 'Cards', security: 'Securities' }

// Under a heading per account type, in the order the types first appear; ungrouped otherwise.
const groups = computed(() => {
  if (!props.options.some(option => option.type)) return [{ title: '', options: props.options }]

  const byType = new Map()

  for (const option of props.options) {
    if (!byType.has(option.type)) byType.set(option.type, [])
    byType.get(option.type).push(option)
  }

  return [...byType].map(([type, options]) => ({ title: typeTitles[type] ?? type, options }))
})

const summary = computed(() =>
  props.modelValue
    .map(value => props.options.find(option => option.value === value)?.label ?? value)
    .join(', '),
)
</script>
