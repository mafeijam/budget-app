import { Quasar, Notify, Dialog } from 'quasar'

import '@quasar/extras/material-icons/material-icons.css'
// No Roboto, though quasar.css names it: app.css sets the body in Space Grotesk, and body is
// the only place Quasar's stylesheet sets a family.
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

        Nested in config because that is the only place Quasar looks, and it does not say
        so: it reads opts.config.brand and ignores a brand key beside it, so the block
        misplaced there is dropped with nothing thrown and every colour left as the
        defaults compiled into quasar.css, which is a green at 2.6:1 on white.
      */
      config: {
        brand: {
          primary: '#2b59ff',
          secondary: '#475569',
          accent: '#7c3aed',
          dark: '#0f172a',
          positive: '#047857',
          negative: '#e11d48',
          info: '#0284c7',
          warning: '#b45309',
        },
      },
    })
  },
}
