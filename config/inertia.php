<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | This application is a pure client-side SPA: resources/js/app.js calls
    | createInertiaApp() with no SSR bundle and there is no bootstrap/ssr
    | directory. SSR therefore defaults to disabled. Set INERTIA_SSR_ENABLED
    | only if an SSR bundle is actually introduced.
    |
    | Note that the Inertia 3 stub flips this default to true. Taking the stub
    | would point every request at an SSR gateway that has nothing behind it.
    |
    | Inertia 3 also ships ssr.runtime, ssr.hot_url, ssr.timeout,
    | ssr.ensure_runtime_exists and ssr.throw_on_error. They are deliberately
    | not copied across: each is read only from the SSR code paths, which do
    | not run while enabled is false, and each read carries its own inline
    | default. Laravel merges config one level deep, so omitting them here
    | costs nothing and keeps this block honest about what is actually used.
    |
    */

    'ssr' => [
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', false),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
        'ensure_bundle_exists' => (bool) env('INERTIA_SSR_ENSURE_BUNDLE_EXISTS', true),

        // 'bundle' => base_path('bootstrap/ssr/ssr.mjs'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Page Resolution
    |--------------------------------------------------------------------------
    |
    | Inertia 3 folds these three settings into a single `pages` key, replacing
    | the flat ensure_pages_exist / page_paths / page_extensions of Inertia 2.
    | The values are unchanged: pages live in resources/js/pages, lower case,
    | which is what the import.meta.glob() call in resources/js/app.js matches.
    |
    | ensure_pages_exist stays false so a missing page surfaces as a normal 500
    | in development rather than a hard failure in production.
    |
    | The use_script_element_for_initial_page option is gone in Inertia 3 --
    | the key no longer exists anywhere in the package. The initial page is
    | always delivered the same way now, so the INERTIA_USE_SCRIPT_ELEMENT_
    | FOR_INITIAL_PAGE variable it read has no replacement to configure.
    |
    */

    'pages' => [
        'ensure_pages_exist' => false,

        'paths' => [
            resource_path('js/pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | Inertia 2 let testing.page_paths and testing.page_extensions override
    | where assertInertia() looks for a component on disk. Inertia 3 removed
    | both; the assertion reads pages.paths and pages.extensions above, so
    | there is now a single source of truth and the two cannot drift apart.
    |
    | This flag stays on: a test that renders Inertia::render('category')
    | should fail loudly if resources/js/pages/category.vue has been renamed
    | or moved.
    |
    */

    'testing' => [
        'ensure_pages_exist' => true,
    ],

];
