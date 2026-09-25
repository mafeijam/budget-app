import { Quasar, LoadingBar, Notify } from 'quasar'

import '@quasar/extras/material-icons/material-icons.css'
import 'quasar/dist/quasar.css'

let timeout = null

router.on('start', () => {
  timeout = setTimeout(() => LoadingBar.start(), 300)
})

router.on('finish', () => {
  clearTimeout(timeout)
  LoadingBar.stop()
})

export default {
  install(app) {
    app.use(Quasar, {
      plugins: { LoadingBar, Notify },
      config: {
        loadingBar: {
          color: 'amber',
          size: '3px',
          skipHijack: true,
        },
      },
    })
  },
}
