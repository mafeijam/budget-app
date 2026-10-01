// The transactions list's Totals panel, open or closed, kept in a cookie so the server works
// the figures out only while it is open: they are a grouped query over every matching row.
// One state for the toolbar's toggle and the panel under the table. Opening it asks for the
// figures; closing it needs nothing from the server.
const TOTALS_COOKIE = 'transactions_totals'
const chosen = ref(null)

export function useShowTotals() {
  const page = usePage()

  return computed({
    get: () => chosen.value ?? page.props.showTotals === true,
    set: on => {
      writeCookie(TOTALS_COOKIE, on ? '1' : '0')
      chosen.value = on

      if (on && page.props.showTotals !== true) {
        router.reload({ only: ['totals', 'baseTotals', 'unconverted', 'showTotals'] })
      }
    },
  })
}
