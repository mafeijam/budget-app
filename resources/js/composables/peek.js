// A popup of the rows behind a figure, read when it is asked for and shown only once they are
// in. Opened first, it jumped in empty and filled in under the reader, and a second tile's
// popup showed the first's rows until its own arrived. `meta` is whatever the page needs to
// say what was asked: it is set with the rows, never before them.
export function usePeek() {
  const state = reactive({ open: false, busy: false, data: null, meta: null })

  const show = async (path, params, meta = null) => {
    if (state.busy) return

    state.busy = true

    try {
      const response = await fetch(`${path}?${new URLSearchParams(params)}`, {
        headers: { Accept: 'application/json' },
      })

      if (!response.ok) throw new Error(String(response.status))

      Object.assign(state, { data: await response.json(), meta, open: true })
    } catch {
      notifyFailure('The transactions could not be loaded.')
    } finally {
      state.busy = false
    }
  }

  return Object.assign(state, { show })
}
