const page = usePage()

const timeZone = page.props?.tz

// Two formatters, because a timestamp and a calendar day are different facts and
// one of them is not a timestamp at all.
//
// A `date` column holds no time. Formatting one with a time invents a deadline:
// `new Date('2026-02-09')` is UTC midnight, which in Asia/Hong_Kong is 08:00 on
// the 9th, and a statement rendered as "2026-02-09, 08:00:00" looks like it is
// payable at eight in the morning. transactions.due_date says outright that it is
// a calendar day and that a time would imply a settlement deadline which does not
// exist, so the day is formatted on its own here.
//
// Same time zone for both, so a date does not shift across a day boundary
// relative to the timestamp printed beside it.
const dayFormatter = new Intl.DateTimeFormat('en-CA', {
  dateStyle: 'short',
  timeZone,
})

const timeFormatter = new Intl.DateTimeFormat('en-CA', {
  dateStyle: 'short',
  timeStyle: 'medium',
  hour12: false,
  timeZone,
})

// For a `date` column: the day, and nothing else.
export function useCalendarDay() {
  return function (dateString) {
    if (!dateString) return ''

    return dayFormatter.format(new Date(dateString))
  }
}

// For a `timestamp` column, where the time is part of the fact being reported.
export function useHongKongTime() {
  return function (dateString) {
    return timeFormatter.format(new Date(dateString))
  }
}
