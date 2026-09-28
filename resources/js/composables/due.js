// How close a statement's due date is, as a badge's props. The count is the server's
// days_until_due, from Hong Kong's today rather than the browser's -- see
// CardStatement::toArray() -- so this only words it.
export function useDueBadge() {
  return period => {
    const days = period.days_until_due

    if (days < 0) {
      return {
        color: 'red-1',
        textColor: 'red-9',
        label: `${-days} day${days === -1 ? '' : 's'} overdue`,
      }
    }

    if (days === 0) return { color: 'amber-2', textColor: 'amber-10', label: 'due today' }

    return { color: 'grey-2', textColor: 'grey-8', label: `in ${days} day${days === 1 ? '' : 's'}` }
  }
}
