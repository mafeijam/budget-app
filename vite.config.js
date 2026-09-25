import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import autoImport from 'unplugin-auto-import/vite'
import components from 'unplugin-vue-components/vite'
import { quasar, transformAssetUrls } from '@quasar/vite-plugin'

export default defineConfig({
  server: {
    host: '192.168.50.52',
    // port: 6060,
    hmr: {
      host: '192.168.50.52',
      // port: 6060,
    },
  },
  plugins: [
    laravel({
      input: ['resources/js/app.js'],
      refresh: true,
    }),
    vue({
      template: {
        transformAssetUrls,
      },
    }),
    autoImport({
      imports: [
        'vue',
        '@vueuse/core',
        {
          quasar: ['useQuasar'],
        },
        {
          '@inertiajs/vue3': ['router', 'usePage', 'useForm'],
        },
      ],
      dirs: ['resources/js/composables'],
      eslintrc: {
        enabled: true,
      },
      dts: true,
    }),
    components({
      dirs: ['resources/js/components'],
      directoryAsNamespace: true,
      collapseSamePrefixes: true,
      dts: true,
    }),
    quasar(),
  ],
})
