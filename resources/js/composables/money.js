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
