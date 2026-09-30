// A narrowed copy of a list, and the handler that narrows it.
//
// QSelect filters nothing by itself: its filter() returns on the first line unless a
// @filter listener is attached, so the listener is the whole of the narrowing and it has
// to own a list to narrow -- which is why there is a "shown" list beside each source
// rather than the filter writing back into the source.
//
// Narrowing reads the source and never the shown list, or the list would narrow from its
// own narrowed state: type "b", then "l", and the second pass would search only what
// matched "b" -- so an entry containing "blue" but not "b" is unreachable, and widening
// the search again cannot bring anything back.
//
// Empty restores the whole list, which is what happens when the input is cleared or the
// field is reset, and is the only way back from a narrow one.
//
// `matches` is the only thing two fields disagree about: an entry is a string in one and
// an object carrying its label in another. Trimmed and lower-cased on both sides, so a
// trailing space typed by accident does not silently empty the list.
export const filterInto = (shown, source, matches) => {
  // Immediate, and that is the seeding as well as the reset: the field is worth opening
  // before anything has been typed, since a person who cannot remember what they are
  // looking for is exactly the person who needs to see what is on file. A page visit that
  // keeps the component alive sends a new list -- a template saved or deleted, say -- and
  // without this the shown list would keep showing the one before it.
  watch(
    source,
    () => {
      shown.value = [...source.value]
    },
    { immediate: true },
  )

  return (val, update) =>
    update(() => {
      const needle = val.trim().toLowerCase()

      shown.value =
        needle === '' ? [...source.value] : source.value.filter(entry => matches(entry, needle))
    })
}

// What a category filter carries when it is narrowed to the rows with no category. It has to
// be something other than null, because null is what an untouched select holds and a filter
// that meant both would be no filter at all -- and the cash flow page's uncategorised block
// links here with this, since its category has no id to send.
//
// Mirrors TransactionController::NO_CATEGORY, which is where the query turns it into a
// whereNull. Two names for one value, because it crosses the wire.
export const NO_CATEGORY = 'none'
