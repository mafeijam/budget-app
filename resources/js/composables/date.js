const page = usePage()

const formatter = new Intl.DateTimeFormat('en-CA', {
  // hour: '2-digit',
  // minute: '2-digit',
  // second: '2-digit',
  // year: 'numeric',
  // month: '2-digit',
  // day: '2-digit',
  hour12: false,
  dateStyle: 'short',
  timeStyle: 'medium',
  timeZone: page.props?.tz,
})

export function useHongKongTime() {
  return function (dateString) {
    const date = new Date(dateString)
    return formatter.format(date)
  }
}
