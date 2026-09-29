import { Quasar, Notify, Dialog } from 'quasar'

import '@quasar/extras/material-icons/material-icons.css'
// Quasar's stylesheet names Roboto as the first family in every font stack it sets
// (quasar.css), and the whole type scale is measured against it -- the 48px table row,
// the 14px button, the 12px header. Nothing was loading it, so the app was getting
// Roboto's metrics with whatever glyphs the machine happened to fall back to, which is
// why the fallback chain and not the intended face is what the spacing was tuned around.
import '@quasar/extras/roboto-font/roboto-font.css'
import 'quasar/dist/quasar.css'

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
      plugins: { Notify, Dialog },
      /*
        The app's colours, named once. They were palette entries picked per component
        instead -- indigo-1/blue-9 on the add button, green-1/green-9 on the three submit
        buttons, green-7 on the edit icon, pink-7 on the delete icon, teal on the toast --
        so "the primary action" had a different colour depending on which form you were in,
        and six greens had to be kept apart by eye.

        Primary is the only interactive colour, and negative is the only destructive one, so
        a role is now a name rather than a shade. Quasar turns each into a --q- CSS variable,
        which is what lets text-positive and text-negative in the tables follow the same
        values without a single component naming a hex -- or the progress bar in app.js,
        which names var(--q-primary) rather than copy the value out of this block.
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
    })
  },
}
