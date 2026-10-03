// The app bar's height, read off the bar rather than written down, for a header pinned just
// under it. The layout is hHh with no reveal, so the bar is fixed and always on screen, and its
// height is the whole offset -- an earlier attempt assumed it scrolled away.
export function useBarHeight() {
  const height = ref(0)
  const bar = ref(null)

  onMounted(() => {
    bar.value = document.querySelector('.q-layout .q-header')
  })

  useResizeObserver(bar, () => {
    height.value = bar.value?.offsetHeight ?? 0
  })

  return height
}
