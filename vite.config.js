import { defineConfig, loadEnv } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import autoImport from 'unplugin-auto-import/vite'
import components from 'unplugin-vue-components/vite'
import { quasar, transformAssetUrls } from '@quasar/vite-plugin'

export default defineConfig(({ mode }) => {
  // Load every variable, not just the VITE_ prefixed ones: this is consumed by
  // the dev server config below and never reaches the browser bundle.
  const env = loadEnv(mode, process.cwd(), '')

  // Previously hardcoded to a single machine's LAN address (192.168.50.52),
  // which broke the dev server for anyone else and silently stopped working
  // when the host's IP changed. Set DEV_HOST to bind to a LAN address so you
  // can load the dev server from a phone; leave it unset to bind to localhost
  // only, which is the right default.
  const host = env.DEV_HOST || undefined

  return {
    server: {
      ...(host ? { host } : {}),
      hmr: {
        ...(host ? { host } : {}),
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
  }
})
