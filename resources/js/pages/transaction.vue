<template>
  <div class="column no-wrap q-gutter-md">
    <FormTransaction :options="options" />

    <TransferDialog ref="transferDialog" />

    <CardStatements
      :groups="statements"
      :banks="cardBanks"
      :shown="shownStatement"
      @filter="filterStatement"
    />

    <AppTable :rows="data.data" :columns="columns" title="Transaction" dense>
      <template #top>
        <TransactionFilters ref="filterBar" title="Transactions">
          <template #actions>
            <q-btn
              unelevated
              no-caps
              class="app-create-btn app-btn text-weight-bold q-mr-sm"
              icon="sync_alt"
              label="Transfer"
              @click="transferDialog.show()"
            >
              <q-tooltip :delay="500" :offset="[0, 6]">
                Move money between two cash accounts, or exchange it into another currency
              </q-tooltip>
            </q-btn>
            <CreateBtn label="New transaction" />
          </template>
        </TransactionFilters>
      </template>

      <!-- The row in one cell: what it was, its kind, and where it is filed and held. -->
      <template #body-cell-description="cell">
        <q-td :props="cell">
          <div class="row items-center no-wrap">
            <span class="app-tx-icon q-mr-sm" :class="`app-tx-icon--${directionName(cell.row)}`">
              <!-- A transfer's half by what it is, not its type: a withdrawal's cart reads as spent. -->
              <q-icon
                :name="
                  transferOf(cell.row) ? 'sync_alt' : (typeIcons[cell.row.type] ?? 'help_outline')
                "
                size="16px"
              />
              <q-tooltip :delay="500" :offset="[0, 6]">{{
                transferOf(cell.row) ? `${cell.row.type}, one half of a transfer` : cell.row.type
              }}</q-tooltip>
            </span>
            <div class="app-tx-what">
              <div class="row items-center no-wrap">
                <span class="text-weight-medium text-grey-9 ellipsis">
                  {{ cell.row.description }}
                </span>
                <!-- Only the exception is marked: almost every row is posted. -->
                <q-badge
                  v-if="cell.row.status === 'pending'"
                  class="app-tint app-tint--warning q-ml-sm"
                  label="pending"
                />
                <q-badge
                  v-if="cell.row.meta_data?.one_off"
                  class="app-tint app-tint--info q-ml-sm"
                  label="one-off"
                >
                  <q-tooltip :delay="500" :offset="[0, 6]">
                    Left out of the forecast's typical figures
                  </q-tooltip>
                </q-badge>
              </div>
              <div class="text-caption text-grey-6 ellipsis">
                {{ [categoryName(cell.row), cell.row.account_name].filter(Boolean).join(' · ') }}
                <q-badge
                  v-bind="accountTypeBadges[cell.row.account_type] ?? {}"
                  class="q-ml-xs text-weight-regular"
                  :label="cell.row.account_type"
                />
              </div>
            </div>
          </div>
        </q-td>
      </template>

      <template #body-cell-amount="cell">
        <q-td :props="cell" class="money" :class="amountClass(cell.row)">
          <span class="text-weight-medium">{{ signed(cell.row) }}</span>
          <span class="text-caption text-grey-7 q-ml-xs">{{ cell.row.ccy }}</span>
        </q-td>
      </template>

      <template #body-cell-metaData="cell">
        <q-td :props="cell">
          <!-- Badges, not chips: a dense chip is 21px tall against a badge's 16px. -->
          <q-badge
            v-for="chip in metaChips(cell.row)"
            :key="chip.label"
            class="q-mr-xs"
            :class="chip.class"
            :label="chip.label"
          />
        </q-td>
      </template>

      <template #body-cell-action="cell">
        <q-td :props="cell">
          <AppTableActions :cell="cell" :edit="transferOf(cell.row) ? editTransfer : null" />
        </q-td>
      </template>
    </AppTable>

    <!-- Every filtered row, not just this page, one line per currency: nothing is
         converted, so HKD and USD never add up. Where a second currency is in the
         filter, a base row in front of them, which is the one total that does add up.
         Pending rows are in none of them, which the card says under the strips. -->
    <q-card v-if="showTotals && totals.length" flat bordered>
      <div
        v-for="strip in totalStrips"
        :key="strip.key"
        class="app-tx-totals"
        :class="{ 'app-tx-totals--trades': hasTrades }"
      >
        <div class="row items-center no-wrap q-gutter-x-sm">
          <q-icon name="functions" size="xs" color="grey-6" />
          <q-badge
            :outline="!strip.base"
            :color="strip.base ? 'primary' : 'grey-7'"
            :label="strip.ccy"
          />
          <span class="text-caption" :class="strip.base ? 'text-grey-8' : 'text-grey-6'">
            {{ strip.caption }}
          </span>
        </div>

        <!-- A column each, the same in every strip, so the figures stack. -->
        <div class="app-tx-totals__figure">
          <div class="app-tx-totals__label">In</div>
          <div class="money text-body2" :class="isZero(strip.in) ? 'text-grey-5' : 'text-positive'">
            {{ isZero(strip.in) ? '—' : `+${formatMoney(strip.in)}` }}
          </div>
        </div>
        <div class="app-tx-totals__figure">
          <div class="app-tx-totals__label">Out</div>
          <div
            class="money text-body2"
            :class="isZero(strip.out) ? 'text-grey-5' : 'text-negative'"
          >
            {{ isZero(strip.out) ? '—' : `−${formatMoney(strip.out)}` }}
          </div>
        </div>
        <div v-if="hasTrades" class="app-tx-totals__figure">
          <div class="app-tx-totals__label">Trades</div>
          <div
            class="money text-body2"
            :class="isZero(strip.trades) ? 'text-grey-5' : 'text-grey-9'"
          >
            {{ isZero(strip.trades) ? '—' : formatMoney(strip.trades) }}
          </div>
        </div>
        <div class="app-tx-totals__figure">
          <div class="app-tx-totals__label">Net</div>
          <div class="money text-body2 text-weight-bold" :class="netClass(strip.net)">
            {{ signedNet(strip.net) }}
          </div>
        </div>
      </div>

      <!-- The list above shows a pending row and this does not count it, so the card says
           why rather than leaving a count that looks like a row went missing. -->
      <div class="app-tx-totals__note q-px-md q-py-xs text-caption text-grey-7">
        Pending rows are left out: they do not count toward a balance.
      </div>

      <!-- "Some" is the word doing the work: a currency can be in the total through most
           of its rows and still have a few out of it, and naming it bare would read as the
           whole currency being left out, which overstates what is missing. Gated on the
           base row existing, since this annotates that row and there is nothing to
           annotate on a list of one currency. -->
      <div
        v-if="showTotals && baseTotals && unconverted.length"
        class="app-tx-totals__note q-px-md q-py-xs text-caption text-grey-7"
      >
        Some {{ unconverted.join(', ') }} rows have no {{ base }} figure, so the {{ base }} total
        leaves them out.
      </div>
    </q-card>
  </div>
</template>

<script setup>
const filterBar = ref(null)

const transferDialog = ref(null)

// The other half of a transfer, from the counterparts the server sends with the page.
const transferOf = row => {
  const other = usePage().props.linked?.[row.id]

  return other?.kind === 'transfer' ? other : null
}

const editTransfer = row => transferDialog.value.show(row, transferOf(row))

const totals = computed(() => usePage().props.totals ?? [])

// Null unless something in the list is not already in the base currency: a list that is
// all HKD gains nothing from a row that says the same figures in the same money.
const baseTotals = computed(() => usePage().props.baseTotals ?? null)

const unconverted = computed(() => usePage().props.unconverted ?? [])

/* Zero plural, because the caption is assembled from a count and a word. */
const rows = (count, word = 'row') => `${count} ${word}${count === 1 ? '' : 's'}`

/*
 * The base row in front of the per-currency ones, drawn by the same markup so the columns
 * line up. The two rows differ in what they claim and nothing else, and the caption is
 * what carries that: a currency row counts the rows in that one currency, and the base
 * row counts the rows that had a base figure at all, which is fewer whenever a currency
 * is left out. Stating the denominator is what makes the two comparable -- "every page" on
 * both would read as the same count and be neither.
 */
const totalStrips = computed(() => {
  const perCurrency = totals.value.map(total => ({
    ...total,
    key: `ccy-${total.ccy}`,
    base: false,
    caption: `${rows(total.count)}, every page`,
  }))

  if (!baseTotals.value) {
    return perCurrency
  }

  return [
    {
      ...baseTotals.value,
      key: 'base',
      base: true,
      ccy: props.base,
      caption: `${rows(baseTotals.value.count)} of ${rows(
        perCurrency.reduce((sum, strip) => sum + strip.count, 0),
      )}, every page`,
    },
    ...perCurrency,
  ]
})

// Off by default, and kept in a cookie: see useShowTotals().
const showTotals = useShowTotals()

// On the decimal string, not a float.
const isZero = value => /^-?0*(\.0*)?$/.test(String(value ?? '0'))

const signedNet = value =>
  String(value).startsWith('-') && !isZero(value)
    ? `−${formatMoney(String(value).slice(1))}`
    : `${isZero(value) ? '' : '+'}${formatMoney(value)}`

// A Trades column in every strip when any has trades, so the columns stay in line.
const hasTrades = computed(() => totalStrips.value.some(strip => !isZero(strip.trades)))

const netClass = value =>
  isZero(value) ? 'text-grey-9' : String(value).startsWith('-') ? 'text-negative' : 'text-positive'

// The statement the table is filtered to, so its row in the panel reads as selected.
const shownStatement = computed(() => {
  const filter = usePage().props.params?.filter ?? {}

  return filter.due_date ? { cardId: Number(filter.account_id), dueDate: filter.due_date } : null
})

// A second click on the statement already shown clears it.
const filterStatement = ({ cardId, dueDate }) =>
  shownStatement.value?.cardId === cardId && shownStatement.value?.dueDate === dueDate
    ? filterBar.value?.clear()
    : filterBar.value?.showStatement(cardId, dueDate)

const props = defineProps({
  ...hasTableProps,
  // Not in hasTableProps; without it the form's account select is silently empty.
  options: { type: Object, default: Object },
  statements: { type: Array, default: Array },
  cardBanks: { type: Object, default: () => ({}) },
  base: { type: String, default: 'HKD' },
})

const pagination = usePagination()
const formatMoney = useMoney()

// Quasar's ramp, not the brand: these hues only part card rows from bank rows.
const accountTypeBadges = {
  cash: { color: 'teal-1', textColor: 'teal-9' },
  card: { color: 'deep-purple-1', textColor: 'deep-purple-9' },
  security: { color: 'orange-1', textColor: 'orange-10' },
}

const typeIcons = {
  withdraw: 'shopping_cart',
  deposit: 'move_to_inbox',
  charge: 'credit_card',
  payment: 'task_alt',
  buy: 'trending_up',
  sell: 'trending_down',
  dividend: 'savings',
}

// A class, because Quasar's ramp has no entry for a brand colour.
const statusBadges = {
  posted: { class: 'app-tint app-tint--positive' },
  pending: { class: 'app-tint app-tint--warning' },
}

// From the server's movesBalanceOn(), since amount is always a positive magnitude.
const direction = row => usePage().props.directions?.[row.id] ?? 0

const signed = row => {
  const figure = formatMoney(row.amount)
  const sign = { 1: '+', '-1': '−' }[direction(row)] ?? ''

  return `${sign}${figure}`
}

const directionName = row => ({ 1: 'in', '-1': 'out' })[direction(row)] ?? 'none'

const categoryName = row =>
  props.options?.categories?.find(c => c.value === row.category_id)?.label ?? ''

const amountClass = row => {
  if (row.status === 'pending') return 'text-grey-6'

  return { 1: 'text-positive', '-1': 'text-negative' }[direction(row)] ?? 'text-grey-9'
}

const metaChips = row => {
  const meta = row.meta_data ?? {}
  const chips = []
  const plain = label => chips.push({ label, class: 'app-tint app-tint--muted' })

  if (meta.due_date) plain(`Due ${meta.due_date}`)
  if (meta.settled_by) chips.push({ label: 'Paid', class: 'app-tint app-tint--positive' })

  if (meta.card_amount) {
    const figure = formatMoney(meta.card_amount)

    plain(
      row.account_ccy
        ? `${figure} ${row.account_ccy} on the card`
        : `${figure} in the card's currency`,
    )
  }

  if (meta.paired_transaction_id) {
    const other = usePage().props.linked?.[row.id]

    chips.push({
      label: !other
        ? 'Paired, other half gone'
        : other.kind === 'transfer'
          ? `${row.type === 'withdraw' ? 'To' : 'From'} ${other.account_name}`
          : `Settles with ${other.account_name}`,
      class: 'app-tint app-tint--info',
    })

    // The bank's half: which statement it paid, from the card's half.
    if (!meta.due_date && other?.kind === 'settlement' && other.due_date) {
      plain(`Pays statement ${other.due_date}`)
    }
  }

  // The loan a row drew or repaid, as loans:tag named it: the interest is owed with the
  // principal, so a drawdown states it and every repayment row pays the loan down whole.
  if (meta.loan) {
    chips.push({
      label: meta.loan_repaid ? `Repays ${meta.loan}` : `Loan: ${meta.loan}`,
      class: 'app-tint app-tint--info',
    })

    if (meta.loan_borrowed) {
      plain(
        `${formatMoney(meta.loan_borrowed)} borrowed + ${formatMoney(meta.loan_interest)} interest, ${formatMoney(meta.loan_repaid_before)} repaid before the records`,
      )
    } else if (meta.loan_interest) {
      plain(`+ ${formatMoney(meta.loan_interest)} interest owed`)
    }
  }

  if (meta.symbol) {
    if (meta.quantity) {
      const fees = received(meta.fees) ? `, fees ${twoPlaces(meta.fees)}` : ''

      plain(`${meta.symbol} ${plainQuantity(meta.quantity)} @ ${twoPlaces(meta.unit_price)}${fees}`)
    } else {
      const brokerage = usePage().props.filterOptions?.accounts?.find(
        account => account.value === meta.brokerage_account_id,
      )

      plain(brokerage ? `${meta.symbol} from ${brokerage.label}` : meta.symbol)
    }
  }

  const known = [
    'due_date',
    'settled_by',
    'card_amount',
    'paired_transaction_id',
    'symbol',
    'quantity',
    'unit_price',
    'fees',
    'no_cash',
    'brokerage_account_id',
    'loan',
    'loan_repaid',
    'loan_borrowed',
    'loan_interest',
    'loan_repaid_before',
    // A badge beside the description instead, where the row is named.
    'one_off',
  ]

  Object.entries(meta)
    .filter(([key, value]) => value !== null && !known.includes(key))
    .forEach(([key, value]) => plain(`${key}: ${value}`))

  return chips
}

const columns = reactive([
  {
    name: 'date',
    width: '110px',
    label: 'Date',
    field: 'date',
    align: 'left',
    sortable: true,
  },
  {
    name: 'description',
    width: '420px',
    label: 'Description',
    field: 'description',
    align: 'left',
    sortable: true,
  },
  {
    name: 'amount',
    width: '160px',
    label: 'Amount',
    field: 'amount',
    align: 'right',
    sortable: true,
  },
  {
    name: 'metaData',
    width: '300px',
    label: 'Details',
    field: row =>
      metaChips(row)
        .map(chip => chip.label)
        .join(', '),
    align: 'left',
    sortable: false,
  },
  {
    name: 'action',
    width: '100px',
    label: 'Action',
    align: 'right',
  },
])

provide('pagination', pagination)
</script>
