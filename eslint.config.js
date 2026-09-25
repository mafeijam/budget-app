import eslintConfigPrettier from 'eslint-config-prettier/flat'
import prettierRecommended from 'eslint-plugin-prettier/recommended'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'
import autoImport from './.eslintrc-auto-import.json' with { type: 'json' }

/**
 * Flat config, replacing the old .eslintrc.
 *
 * Order is load-bearing. eslint-plugin-vue ships 118 rules in flat/recommended,
 * nine of which are formatting rules that directly contradict Prettier
 * (vue/html-indent, vue/html-self-closing, vue/max-attributes-per-line,
 * vue/attributes-order and friends). eslint-config-prettier/flat switches all
 * 358 conflicting core rules off, so it has to come AFTER the Vue preset.
 *
 * The block at the bottom then deliberately re-enables one of them:
 * vue/html-self-closing is a project rule with non-default options, exactly as
 * it was in the .eslintrc. Being last, it wins.
 */
export default [
  {
    // Without this, `eslint .` walks node_modules (4,194 files) and
    // public/build, which is why the pre-upgrade lint run never finished.
    // node_modules is ignored by default in flat config; the rest are not.
    ignores: [
      'node_modules/',
      'public/build/',
      'public/hot',
      'storage/',
      'vendor/',
      'bootstrap/cache/',
    ],
  },

  ...pluginVue.configs['flat/recommended'],

  eslintConfigPrettier,
  prettierRecommended,

  {
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: {
        // Browser globals are not part of flat/recommended. The old .eslintrc
        // never declared an env either, so this is new, and correct for a
        // client-side SPA.
        ...globals.browser,

        // unplugin-auto-import writes these (vue, @vueuse/core, useQuasar,
        // router/usePage/useForm plus every local composable) to
        // .eslintrc-auto-import.json, and vite.config.js still has
        // `eslintrc: { enabled: true }`. The legacy config consumed that file
        // via `extends`; flat config has no extends for a bare globals map, so
        // it is spread in here instead.
        ...autoImport.globals,
      },
    },

    rules: {
      'vue/multi-word-component-names': 'off',
      'vue/html-self-closing': [
        'error',
        {
          html: {
            void: 'always',
          },
        },
      ],
    },
  },
]
