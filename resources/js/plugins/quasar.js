import { Quasar, LoadingBar, Notify, Dialog } from 'quasar'

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
      // Quasar's documentation says to list plugins in quasar.config.js, and there is
      // no quasar.config.js here: this is a Vite app assembling Quasar by hand, so the
      // list is this object. Worth saying, because the failure is silent -- a plugin
      // that is not installed leaves its components rendering nothing and its
      // imperative API undefined, rather than complaining at build time.
      //
      // And note this is about plugins, not components. The components really are
      // global, which is why no template imports one; a plugin is an object with state
      // behind it, and Dialog.create() is a method on it rather than a component.
      plugins: { LoadingBar, Notify, Dialog },
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
