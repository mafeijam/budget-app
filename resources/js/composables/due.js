// How close a statement's due date is, as a badge's props. The count is the server's
// days_until_due, from Hong Kong's today rather than the browser's -- see
// CardStatement::toArray() -- so this only words it.
//
// A class rather than a color, because these are the brand's negative and warning tints
// and Quasar's ramp has no entry for a brand colour: see the tint variables in app.css.
export function useDueBadge() {
  return period => {
    const days = period.days_until_due

    if (days < 0) {
      return {
        class: 'app-tint app-tint--negative',
        label: `${-days} day${days === -1 ? '' : 's'} overdue`,
      }
    }

    if (days === 0) return { class: 'app-tint app-tint--warning', label: 'due today' }

    return { class: 'app-tint app-tint--muted', label: `in ${days} day${days === 1 ? '' : 's'}` }
  }
}
