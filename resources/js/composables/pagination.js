export const syncPagination = (pagination, resp) => {
  pagination = toRef(pagination)
  pagination.value.sortBy = resp.props.params.sort
  pagination.value.descending = resp.props.params.dir === 'desc'
  pagination.value.page = resp.props.data.meta.current_page
  pagination.value.rowsPerPage = resp.props.data.meta.per_page
  pagination.value.rowsNumber = resp.props.data.meta.total
}

export const usePagination = () => {
  const page = usePage()
  return ref({
    sortBy: page.props.params.sort,
    descending: page.props.params.dir === 'desc',
    page: page.props.data.meta.current_page,
    rowsPerPage: page.props.data.meta.per_page,
    rowsNumber: page.props.data.meta.total,
  })
}
