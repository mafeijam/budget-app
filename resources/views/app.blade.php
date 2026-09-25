<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />
    <title>Quasar + Inertia</title>
    @vite('resources/js/app.js')
    {{-- {{ Vite::useHotFile(storage_path('vite.hot'))->withEntryPoints(['resources/js/app.js']) }} --}}
    @inertiaHead
  </head>
  <body>
    @inertia
  </body>
</html>
