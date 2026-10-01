// The props a form reads: its own page's, or, opened over another page from the Add menu,
// what /forms/{form} returned for it (FormContextController). The two are the same
// formProps() on the server, so a form reads them one way and cannot tell which it has.
// FormContextHost provides the second; anywhere without one, it is the page.
export function useFormContext() {
  return inject('formContext', null) ?? usePage().props
}
