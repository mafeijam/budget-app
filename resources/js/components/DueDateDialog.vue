<template>
  <q-dialog v-model="open" persistent>
    <q-card flat class="card-form-dialog app-dialog--narrow">
      <q-card-section class="row items-start no-wrap">
        <q-icon name="edit_calendar" size="sm" color="grey-6" class="q-mr-sm q-mt-xs" />
        <div>
          <div class="text-h6 text-grey-9 text-weight-bold">{{ group.card?.name }} statement</div>
          <div v-if="period" class="text-caption text-grey-7">
            <template v-if="covers">Covers {{ covers }} · </template>
            {{ count(period.charge_count, 'charge') }} · owes {{ money(period.owed) }}
            {{ group.card.ccy }}
          </div>
        </div>
        <q-space />
        <q-btn flat round dense color="grey-6" icon="close" @click="open = false" />
      </q-card-section>

      <q-card-section v-if="period" class="q-pt-none">
        <!-- The one figure: the day it falls due, following the field as it is changed. -->
        <div class="text-caption text-grey-7">Due</div>
        <div class="row items-baseline q-gutter-x-sm q-mb-lg">
          <div class="text-h4 text-weight-bold text-grey-9">{{ formatDate(stated || was) }}</div>
          <div v-if="changed" class="text-caption text-grey-7">
            was <span class="text-strike">{{ formatDate(was) }}</span>
          </div>
        </div>

        <!-- Duplicated, not extracted, like SettleDialog's: see FormContractTest. -->
        <q-input
          v-model="stated"
          label="Due on the statement"
          filled
          bottom-slots
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

        <div class="row no-wrap text-caption text-grey-7 q-mt-xs">
          <q-icon name="subdirectory_arrow_right" size="xs" class="q-mr-xs" />
          <div v-if="changed">
            Moves every charge and payment in this statement to {{ formatDate(stated) }}. The amount
            does not change, and later statements still follow the card's terms.
          </div>
          <div v-else>
            Counted from the card's statement day and term. Change it to the date the bank printed.
          </div>
        </div>

        <!-- Tinted notes are for what stops the change, not for describing it. -->
        <div v-if="period.pending_count" class="app-note app-note--warning row no-wrap q-mt-md">
          <q-icon name="schedule" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
          <div>
            {{ count(period.pending_count, 'row') }} in this statement
            {{ period.pending_count === 1 ? 'is' : 'are' }} not yet posted, so it has not been
            issued. Post or remove {{ period.pending_count === 1 ? 'it' : 'them' }} first.
          </div>
        </div>

        <div v-if="error" class="app-note app-note--negative row no-wrap q-mt-md">
          <q-icon name="error_outline" size="xs" class="app-note__icon q-mr-sm q-mt-xs" />
          <div>{{ error }}</div>
        </div>
      </q-card-section>

      <q-separator v-if="period" />

      <q-card-actions class="q-pa-md">
        <q-space />
        <q-btn
          class="text-weight-bold q-mr-sm"
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
          icon="event"
          :label="saving ? 'Saving' : changed ? `Move to ${formatDate(stated)}` : 'Save due date'"
          :loading="saving"
          :disable="!saveable"
          @click="confirm"
        />
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

const count = (n, noun) => `${n} ${noun}${n === 1 ? '' : 's'}`

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
