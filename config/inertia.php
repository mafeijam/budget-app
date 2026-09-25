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
    | Inertia 2 resolves page components from these paths. This app keeps its
    | pages in resources/js/pages (lowercase "pages"), which is why the default
    | resources/js/Pages does not match -- see the import.meta.glob() call in
    | resources/js/app.js.
    |
    | ensure_pages_exist is left off so a missing page surfaces as a normal
    | 500 in development rather than a hard failure in production.
    |
    */

    'ensure_pages_exist' => false,

    'page_paths' => [
        resource_path('js/pages'),
    ],

    'page_extensions' => [
        'js',
        'jsx',
        'svelte',
        'ts',
        'tsx',
        'vue',
    ],

    'use_script_element_for_initial_page' => (bool) env('INERTIA_USE_SCRIPT_ELEMENT_FOR_INITIAL_PAGE', false),

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | Used by assertInertia() to locate the component on disk. Unlike the
    | runtime keys above, this one defaults to on: a test that renders
    | Inertia::render('category') should fail loudly if
    | resources/js/pages/category.vue has been renamed or moved.
    |
    */

    'testing' => [
        'ensure_pages_exist' => true,

        'page_paths' => [
            resource_path('js/pages'),
        ],

        'page_extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],
    ],

];
