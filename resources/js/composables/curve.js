// Samples per gap between two points, enough for a polyline to read as a smooth curve.
const SAMPLES = 16

// A monotone cubic through every point (Fritsch-Carlson), sampled into [x, value] pairs for
// a polyline. Smooth, but never overshooting between two points, so a line cannot bulge
// past a real figure. The points are evenly spaced from `x0`, `step` apart.
export function monotoneCurve(values, x0, step) {
  const n = values.length

  if (n < 2) return values.map((value, i) => [x0 + i * step, value])

  const slopes = values.slice(1).map((value, i) => value - values[i])
  const tangents = values.map((_, i) => {
    if (i === 0) return slopes[0]
    if (i === n - 1) return slopes[n - 2]

    return slopes[i - 1] * slopes[i] <= 0 ? 0 : (slopes[i - 1] + slopes[i]) / 2
  })

  for (let i = 0; i < n - 1; i++) {
    if (slopes[i] === 0) {
      tangents[i] = tangents[i + 1] = 0

      continue
    }

    const a = tangents[i] / slopes[i]
    const b = tangents[i + 1] / slopes[i]
    const h = a * a + b * b

    if (h > 9) {
      tangents[i] = (3 * a * slopes[i]) / Math.sqrt(h)
      tangents[i + 1] = (3 * b * slopes[i]) / Math.sqrt(h)
    }
  }

  const sampled = [[x0, values[0]]]

  for (let i = 0; i < n - 1; i++) {
    for (let k = 1; k <= SAMPLES; k++) {
      const t = k / SAMPLES
      const t2 = t * t
      const t3 = t2 * t

      const value =
        (2 * t3 - 3 * t2 + 1) * values[i] +
        (t3 - 2 * t2 + t) * tangents[i] +
        (-2 * t3 + 3 * t2) * values[i + 1] +
        (t3 - t2) * tangents[i + 1]

      sampled.push([x0 + (i + t) * step, value])
    }
  }

  return sampled
}
