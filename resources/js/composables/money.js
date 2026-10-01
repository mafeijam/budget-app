// Money arrives as a decimal string and is formatted as one. Number() would turn it
// into a float, which is exactly what AMOUNT_SCALE and BigDecimal keep out on the
// server: at eight integer digits a float still looks right, and a wider balance would
// quietly print a different number. So the digits are rounded as digits.
//
// Half away from zero on the first dropped digit, which is all half-up ever reads.
// Anything that is not a plain decimal string is returned as it came, rather than
// guessed at.
export function useMoney(places = 2) {
  return value => {
    if (value === null || value === undefined || value === '') return ''

    const match = String(value).match(/^(-?)(\d+)(?:\.(\d+))?$/)

    if (!match) return String(value)

    const [, sign, whole, fraction = ''] = match
    const kept = BigInt(whole + fraction.padEnd(places + 1, '0').slice(0, places + 1))
    const rounded = (kept + 5n) / 10n

    const digits = rounded.toString().padStart(places + 1, '0')
    const grouped = digits.slice(0, -places).replace(/\B(?=(\d{3})+(?!\d))/g, ',')

    // No sign on a figure that rounds to zero: "-0.00" reads as a debt of nothing.
    return `${sign && rounded !== 0n ? '-' : ''}${grouped}.${digits.slice(-places)}`
  }
}

// The column's four places cut to two, but only when the two dropped are zeros, so a figure
// that really has four is never rounded away. For an amount input, and for the figures a
// trade's detail chip quotes.
export function twoPlaces(value) {
  if (typeof value !== 'string') return value

  const match = value.match(/^(-?\d+\.\d{2})(\d*)$/)

  return match && /^0*$/.test(match[2]) ? match[1] : value
}

// A quantity without the zeros its eight places carry: 3000, not 3000.00000000. The eight
// are there so a fractional holding has somewhere to live, and a sentence has no use for
// them, which is why they are trimmed here rather than never written.
//
// Trimmed on the string, since a quantity is a decimal and never a float. The test for a
// decimal point is what keeps "10" from losing its own trailing zero and arriving as "1".
export const plainQuantity = value => {
  const string = String(value)
  const trimmed = string.includes('.') ? string.replace(/\.?0+$/, '') : string

  return trimmed === '' ? '0' : trimmed
}

// Whether a figure is worth a clause, which is not the same as being present: a fee of
// "0.0000" is a string, so a plain truth test puts "fees 0.00" on a trade that paid none.
export const received = value => /[1-9]/.test(String(value))

// Adding money on the browser, at AMOUNT_SCALE, because a card's rows have to be totalled
// and a float total of a few hundred thousand is a total that is wrong in the cents. The
// same reason useMoney() formats by digits rather than by Number().
const scaled = value => {
  const [, sign, whole, fraction = ''] = String(value ?? '0').match(/^(-?)(\d*)\.?(\d*)$/) ?? []
  const units = BigInt((whole || '0') + fraction.padEnd(4, '0').slice(0, 4))

  return sign ? -units : units
}

const fromUnits = units => {
  const digits = (units < 0n ? -units : units).toString().padStart(5, '0')

  return `${units < 0n ? '-' : ''}${digits.slice(0, -4)}.${digits.slice(-4)}`
}

export const plus = (a, b) => fromUnits(scaled(a) + scaled(b))

export const minus = (a, b) => fromUnits(scaled(a) - scaled(b))

// A card's rows largest first, on the figure the row shows, so the biggest is where the eye
// starts. Compared as money rather than as strings, which would put 9,000.00 above 180,000.00,
// and not as floats, which is what the rest of this file exists to avoid. Stable, so rows of
// one figure keep the order they arrived in.
export const byAmountDescending = (a, b) => {
  const [x, y] = [scaled(a.total), scaled(b.total)]

  return x === y ? 0 : x > y ? -1 : 1
}

/**
 * The rows of a card under their currency, so a card holding money in two of them says so
 * rather than leaving the reader to notice which rows carry a second figure. The base
 * currency first, since it is the currency the card's own figure is in, then the others in
 * the order they arrive.
 *
 * A group says one thing about money: what its rows add up to *in the currency it names*.
 * `own` is that figure, `total` is the same money worth the card's currency, and the two are
 * never the same number where a rate was applied -- so a heading takes `own` and a row's
 * bold figure takes `total`, and neither has to explain itself.
 *
 * Every group is headed once the card holds more than one currency, a group of one included:
 * the single USD row among HKD ones is the row that most needs saying what it is. Its heading
 * carries that one row's own money and the row beneath carries its worth in the card's
 * currency, so nothing is printed twice.
 *
 * @param list<{ccy: string, total: string, own: string, converted: bool}> items
 * @param string base
 */
export function groupedByCurrency(items, base) {
  const groups = []
  const byCcy = new Map()

  for (const item of items) {
    const existing = byCcy.get(item.ccy)

    if (existing) {
      existing.items.push(item)
      existing.total = plus(existing.total, item.total)
      existing.own = plus(existing.own, item.own)
      continue
    }

    const group = {
      ccy: item.ccy,
      base: item.ccy === base,
      total: item.total,
      own: item.own,
      items: [item],
    }

    byCcy.set(item.ccy, group)
    groups.push(group)
  }

  const sorted = groups.sort((a, b) => (a.base ? -1 : b.base ? 1 : 0))

  for (const group of sorted) {
    group.headed = sorted.length > 1

    // Whether the heading carries a figure. A run of one has no sum to state: its own money
    // is on the row beneath it, and a heading that repeats it puts the same figure on screen
    // twice a few pixels apart. The run in the card's own currency is the exception, since
    // its figure is the one the card's figure is reconciled with.
    group.ownShown = group.base || group.items.length > 1

    // Whether `total` is a sum in the card's currency at all. A run in another currency with
    // no rate has no base figure, so its total is its own money under the wrong column's
    // name, and a column of figures meant to add up cannot print one.
    group.inBase = group.base || group.items.every(item => item.converted)
  }

  return { groups: sorted }
}
