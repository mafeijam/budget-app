// The props a form reads: its own page's, or, opened over another page from the Add menu,
// what /forms/{form} returned for it (FormContextController). The two are the same
// formProps() on the server, so a form reads them one way and cannot tell which it has.
// FormContextHost provides the second; anywhere without one, it is the page.
//
// Read through, not `usePage().props` itself: that is a computed, and every visit replaces
// the object behind it, so a form holding the one it was set up with never sees a reload --
// a template saved over the transactions page stayed out of the menu until a refresh.
export function useFormContext() {
  const provided = inject('formContext', null)

  if (provided) return provided

  const page = usePage()

  return new Proxy(
    {},
    {
      get: (_, key) => page.props?.[key],
      has: (_, key) => key in (page.props ?? {}),
    },
  )
}
