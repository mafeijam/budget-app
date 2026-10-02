<template>
  <!-- The signed-out pages: no menu, since every link in it would only bounce to sign-in. -->
  <q-layout view="hHh lpR fFf">
    <q-page-container>
      <q-page class="flex flex-center bg-grey-2 q-pa-md text-grey-8">
        <q-card flat bordered style="width: 100%; max-width: 360px">
          <q-card-section class="row items-center no-wrap q-gutter-sm">
            <AppLogo :size="32" />
            <div class="app-wordmark text-h6">Ledger<span class="app-wordmark__stop">.</span></div>
          </q-card-section>
          <q-separator />
          <slot />
        </q-card>
      </q-page>
    </q-page-container>
  </q-layout>
</template>
