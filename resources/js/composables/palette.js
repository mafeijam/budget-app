// The categorical palette every chart draws its series in, in one place.
//
// It was hand-copied into the three charts that use it -- the positions allocation, the
// cash flow breakdown and the dividends month chart -- which is the way a second copy
// drifts: a colour added to one of them means something different in each, and a reader
// who has learned that blue is one holding finds a fourth thing drawn in it. Three files
// that must agree on what a colour means are three files that should not each hold the
// list.
//
// The dataviz reference palette's categorical slots in their fixed order, validated for
// the light surface. Ten, which is as many as a reader can hold apart in one stack: the
// last three are brown, cyan and magenta, chosen because each is further from all the
// others (Lab dE of 50, 45 and 42) than the closest pair already in the set is (33), so
// adding them does not bring any two nearer together.
export const palette = [
  '#2a78d6', // blue
  '#eb6834', // orange
  '#1baf7a', // green
  '#eda100', // amber
  '#e87ba4', // pink
  '#008300', // deep green
  '#4a3aa7', // indigo
  '#7a5c2e', // brown
  '#0d7d8c', // cyan
  '#c026a3', // magenta
]

// Past the palette, and for the tail that shares one block: a neutral that is no series'
// hue, so a colour always means one named thing and grey always means the rest. Farthest
// from the nearest named colour at dE 29, and the only one of the eleven that is not
// saturated.
export const neutral = '#94a3b8'

/**
 * The colour for a series in rank order, or the neutral past the end of the palette.
 *
 * `at` is the series' position by size, so the colour a thing is drawn in says how big
 * it is as well -- and two charts ranking the same list agree.
 */
export function seriesColour(at) {
  return at >= 0 && at < palette.length ? palette[at] : neutral
}
