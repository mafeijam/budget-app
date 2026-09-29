<template>
  <q-dialog v-model="open" persistent>
    <q-card flat class="card-form-dialog">
      <q-card-section>
        <div class="row justify-between items-center">
          <div class="text-h6 text-grey-9 text-weight-bold">Statement issued</div>
          <q-btn flat round color="grey-6" icon="close" @click="open = false" />
        </div>
      </q-card-section>

      <q-separator inset />

      <q-card-section v-if="period" class="q-gutter-sm">
        <div class="text-subtitle2 text-weight-medium">
          {{ group.card.name }} · statement due {{ formatDate(period.due_date) }}
        </div>

        <q-markup-table dense flat class="q-my-sm">
          <tbody>
            <tr>
              <td>Covers</td>
              <td class="text-right">{{ covers }}</td>
            </tr>
            <tr>
              <td>Charges</td>
              <td class="text-right">
                {{ period.charge_count }} · {{ money(period.charged) }} {{ group.card.ccy }}
              </td>
            </tr>
            <tr class="text-weight-medium">
              <td>Owes</td>
              <td class="text-right">{{ money(period.owed) }} {{ group.card.ccy }}</td>
            </tr>
          </tbody>
        </q-markup-table>

        <!-- Duplicated, not extracted, like SettleDialog's: see FormContractTest. -->
        <q-input
          v-model="stated"
          class="q-mb-sm"
          label="Due on the statement"
          filled
          :hint="hint"
          :error="!!fieldError"
          :error-message="fieldError"
        >
          <template #append>
            <q-btn flat dense icon="event" rounded>
              <q-menu ref="dateMenu" :offset="[10, 15]" anchor="bottom right" self="top right">
                <q-date
                  :model-value="stated"
                  mask="YYYY-MM-DD"
                  minimal
                  color="primary"
                  @update:model-value="pickDate"
                />
              </q-menu>
            </q-btn>
          </template>
        </q-input>

        <div v-if="changed" class="app-note">
          Every charge and payment in this statement moves to
          {{ formatDate(stated) }}. The amount does not change.
        </div>

        <div v-if="error" class="app-note app-note--negative">
          {{ error }}
        </div>
      </q-card-section>

      <q-separator v-if="period" inset />

      <q-card-actions class="q-pa-md">
        <div class="col-12">
          <div class="row">
            <q-space />
            <q-btn
              class="text-weight-bold q-mr-md"
              color="grey-6"
              padding="sm md"
              flat
              no-caps
              label="Cancel"
              @click="open = false"
            />
            <q-btn
              class="text-weight-bold app-btn app-btn--positive"
              padding="sm md"
              unelevated
              no-caps
              :label="saving ? 'Saving' : 'Save due date'"
              :loading="saving"
              :disable="!saveable"
              @click="confirm"
            />
          </div>
        </div>
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
const props = defineProps({
  group: { type: Object, default: Object },
  period: { type: Object, default: null },
})

const formatDate = useCalendarDay()

const open = ref(false)
const saving = ref(false)
const error = ref(null)
const fieldError = ref(null)

const stated = ref('')
const was = ref('')

const changed = computed(() => stated.value !== '' && stated.value !== was.value)

const covers = computed(() => {
  const from = props.period?.first_charge_date
  const to = props.period?.last_charge_date

  if (!from) return ''

  return from === to ? formatDate(from) : `${formatDate(from)} – ${formatDate(to)}`
})

const hint = computed(() =>
  changed.value
    ? `Was ${formatDate(was.value)}, counted from the card's terms`
    : "What the card's statement day and term make of it",
)

const money = useMoney()

const dateMenu = ref(null)

const pickDate = value => {
  stated.value = typeof value === 'string' ? value : ''
  dateMenu.value?.hide()
}

const saveable = computed(() => changed.value && !props.period?.pending_count && !saving.value)

const confirm = () => {
  saving.value = true

  router.post(
    `/accounts/${props.group.card.id}/due-date`,
    {
      due_date: was.value,
      new_due_date: stated.value,
    },
    {
      preserveScroll: true,
      preserveState: true,
      // Save's own spinner -- see plugins/quasar.js.
      showProgress: false,
      onError: errors => {
        fieldError.value = errors.new_due_date ?? null
        error.value = errors.due_date ?? "That statement's due date could not be changed."
      },
      onSuccess: () => {
        notifySuccess()

        open.value = false
        error.value = null
        fieldError.value = null
      },
      onFinish: () => (saving.value = false),
    },
  )
}

watch(
  () => props.period,
  () => {
    error.value = null
    fieldError.value = null
  },
)

// The period is passed in: the props it fills have not updated yet in this tick.
defineExpose({
  show: period => {
    was.value = period?.due_date ?? ''
    stated.value = was.value
    error.value = null
    fieldError.value = null
    open.value = true
  },
})
</script>
