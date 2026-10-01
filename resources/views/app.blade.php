<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />
    <title>Ledger</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml" />
    <link rel="icon" href="/favicon.ico" sizes="32x32" />
    @vite('resources/js/app.js')
    {{-- {{ Vite::useHotFile(storage_path('vite.hot'))->withEntryPoints(['resources/js/app.js']) }} --}}
    @inertiaHead
  </head>
  <body>
    @inertia
  </body>
</html>
