import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import layout from './layout.vue'
import quasar from './plugins/quasar'
import globalHelper from './plugins/global-helper'

import '../css/app.css'

createInertiaApp({
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
