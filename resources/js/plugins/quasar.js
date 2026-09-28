import { Quasar, LoadingBar, Notify, Dialog } from 'quasar'

import '@quasar/extras/material-icons/material-icons.css'
// Quasar's stylesheet names Roboto as the first family in every font stack it sets
// (quasar.css), and the whole type scale is measured against it -- the 48px table row,
// the 14px button, the 12px header. Nothing was loading it, so the app was getting
// Roboto's metrics with whatever glyphs the machine happened to fall back to, which is
// why the fallback chain and not the intended face is what the spacing was tuned around.
import '@quasar/extras/roboto-font/roboto-font.css'
import 'quasar/dist/quasar.css'

let timeout = null

/*
  Started here rather than by Inertia, because skipHijack below keeps Quasar off the
  router -- which also means Inertia's own showProgress flag does nothing unless it is
  read here. A request answered by its own control's spinner passes showProgress: false
  and this honours it: the button names what is being waited on, and two indicators are
  the same answer twice.

  The button's :loading is also what disables it, which is why the spinner is the one that
  stays. Drop it to keep the bar and a second press on Fetch prices fires a second fetch,
  with no disabled button in the way.
*/
router.on('start', event => {
  if (event.detail.visit.showProgress === false) return

  timeout = setTimeout(() => LoadingBar.start(), 300)
})

// Stopped by the request that started it: a quiet one finishing alongside a navigating
// one would switch off a bar the second is still using.
router.on('finish', event => {
  if (event.detail.visit.showProgress === false) return

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
      /*
        The app's colours, named once. They were palette entries picked per component
        instead -- indigo-1/blue-9 on the add button, green-1/green-9 on the three submit
        buttons, green-7 on the edit icon, pink-7 on the delete icon, teal on the toast --
        so "the primary action" had a different colour depending on which form you were in,
        and six greens had to be kept apart by eye.

        Primary is the only interactive colour, and negative is the only destructive one, so
        a role is now a name rather than a shade. Quasar turns each into a --q- CSS variable,
        which is what lets text-positive and text-negative in the tables follow the same
        values without a single component naming a hex.
      */
      brand: {
        primary: '#2563eb',
        secondary: '#475569',
        accent: '#7c3aed',
        dark: '#0f172a',
        positive: '#059669',
        negative: '#dc2626',
        info: '#0284c7',
        warning: '#b45309',
      },
      config: {
        loadingBar: {
          color: 'primary',
          size: '3px',
          skipHijack: true,
        },
      },
    })
  },
}
