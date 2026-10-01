import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import layout from './layout.vue'
import quasar from './plugins/quasar'
import globalHelper from './plugins/global-helper'

// The wordmark's face, the one weight it is set in and only its Latin letters: the rest of
// the app is Roboto.
import '@fontsource/unbounded/latin-700.css'
import '../css/app.css'

createInertiaApp({
  /*
    The one loading indicator, and Inertia's own. It installs this bar by default, so
    passing nothing here is not the same as passing no bar: plugins/quasar.js also drove
    Quasar's LoadingBar over the top of it, and a visit slower than the delay drew two,
    in two colours, for one request. A call that shows its own control's spinner passes
    showProgress: false, which is honoured natively on this path and by nothing else.

    The colour is a CSS var because Quasar writes --q-primary onto the body element at
    install and this stylesheet is injected into the head, where the var still inherits
    down to #nprogress; a hex would be a second copy of the brand primary to keep in step.
    The delay is the one plugins/quasar.js had, so a fast visit does not flash the bar.
  */
  progress: { color: 'var(--q-primary)', delay: 300 },
  resolve: name => {
    const pages = import.meta.glob('./pages/**/*.vue', { eager: true })
    const page = pages[`./pages/${name}.vue`]
    page.default.layout ??= layout
    return page
  },
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .use(quasar)
      .use(globalHelper)
      .mount(el)
  },
})
