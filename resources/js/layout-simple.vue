<template>
  <!-- The phone's: no drawer, since the pages it leads to are the ones a phone cannot use, and
       the add button and sign out where a thumb reaches them. -->
  <q-layout view="hHh lpR fFf">
    <q-header bordered class="bg-white text-grey-9">
      <q-toolbar class="q-px-md">
        <AppLogo :size="28" />
        <q-space />
        <AddMenu transaction-only />
        <SignOutBtn class="q-ml-sm" />
      </q-toolbar>
    </q-header>

    <q-page-container>
      <q-page class="app-simple bg-grey-2 q-pa-sm text-grey-9">
        <slot />
      </q-page>
    </q-page-container>
  </q-layout>
</template>
