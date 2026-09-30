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

// For an amount input: the column's four places cut to two, but only when the two dropped
// are zeros, so a figure that really has four (a derived trade) is never rounded away.
export function twoPlaces(value) {
  if (typeof value !== 'string') return value

  const match = value.match(/^(-?\d+\.\d{2})(\d*)$/)

  return match && /^0*$/.test(match[2]) ? match[1] : value
}

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
 * A group is headed only where a heading does work: the card must hold more than one
 * currency, and the group must have more than one row. A group of one has nothing to be
 * separated from and its total is the row's own figure, already on screen.
 *
 * The base total is the sum of the figures already on the rows, all of which are in the base
 * currency -- a USD account's bold figure is its HKD worth and the dollars sit beside it as
 * the native. So it is what adds up to the card's own figure, and it leads the heading for
 * that reason. The native beside it answers the other question, how much there is in
 * dollars, and only where every row of the group has one: a partial native total is not a
 * subtotal of anything.
 *
 * @param list<{ccy: string, total: string, nativeTotal: ?string}> items
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
      existing.native = item.nativeTotal ? plus(existing.native, item.nativeTotal) : null
      existing.allNative = existing.allNative && item.nativeTotal !== null
      continue
    }

    const group = {
      ccy: item.ccy,
      total: item.total,
      native: item.nativeTotal,
      allNative: item.nativeTotal !== null,
      items: [item],
    }
    byCcy.set(item.ccy, group)
    groups.push(group)
  }

  const sorted = groups.sort((a, b) => (a.ccy === base ? -1 : b.ccy === base ? 1 : 0))

  for (const group of sorted) {
    group.headed = sorted.length > 1 && group.items.length > 1
    group.native = group.allNative ? group.native : null
  }

  return sorted
}
