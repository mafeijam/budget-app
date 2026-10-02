const kinds = {
  statement: { icon: 'credit_card', label: 'Card statement', tone: 'out' },
  recurring: { icon: 'event_repeat', label: 'Recurring', tone: 'neutral' },
  pending: { icon: 'pending_actions', label: 'Pending', tone: 'warn' },
  scheduled: { icon: 'schedule', label: 'Scheduled', tone: 'neutral' },
  'expected dividend': { icon: 'savings', label: 'Expected dividend', tone: 'in' },
  'expected bonus': { icon: 'card_giftcard', label: 'Expected bonus', tone: 'in' },
  'expected double pay': { icon: 'payments', label: 'Expected double pay', tone: 'in' },
}

const monthFormat = new Intl.DateTimeFormat('en', {
  month: 'short',
  timeZone: 'UTC',
})

// The home page's Coming up and the forecast's Next 30 days list the same events, so they
// are grouped and labelled in one place: a kind added to the forecast would otherwise show
// as a plain event on one page and a named one on the other.
//
// By calendar day, from the date string alone: a `date` column has no time to be shifted.
export function useUpcomingByDay() {
  return events => {
    const days = new Map()

    events.forEach((event, i) => {
      const kind = kinds[event.kind] ?? { icon: 'event', label: event.kind, tone: 'neutral' }
      const day = days.get(event.date) ?? { date: event.date, events: [] }
      const [year, month, num] = event.date.split('-').map(Number)
      const parts = monthFormat.formatToParts(new Date(Date.UTC(year, month - 1, num)))

      day.num = num
      day.month = parts.find(p => p.type === 'month').value
      day.events.push({
        ...event,
        key: i,
        icon: kind.icon,
        tone: kind.tone,
        kindLabel: kind.label,
        // The date is the group's heading, so a statement need not say it again.
        title:
          event.kind === 'statement'
            ? event.description.replace(/ due \S+$/, '')
            : event.description,
      })
      days.set(event.date, day)
    })

    return [...days.values()]
  }
}
