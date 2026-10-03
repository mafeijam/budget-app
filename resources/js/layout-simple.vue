<template>
  <!-- The phone's: no drawer, since the pages it leads to are the ones a phone cannot use. Its
       two pages are a tab bar at the foot, where a thumb is, with adding between them. -->
  <q-layout view="hHh lpR fFf">
    <q-header class="app-header">
      <q-toolbar class="q-px-md">
        <AppLogo :size="28" />
        <q-space />
        <SignOutBtn />
      </q-toolbar>
    </q-header>

    <q-page-container>
      <q-page class="app-simple q-pa-md text-grey-9">
        <slot />
      </q-page>
    </q-page-container>

    <q-footer class="app-tabbar">
      <nav class="app-tabbar__row">
        <Link href="/" class="app-tabbar__tab" :class="activeOn('simple')" aria-label="Home">
          <img :src="house" alt="" />
        </Link>

        <div class="app-tabbar__add">
          <AddMenu transaction-only />
        </div>

        <Link
          href="/transactions"
          class="app-tabbar__tab"
          :class="activeOn('simple-transaction')"
          aria-label="Transactions"
        >
          <img :src="receipt" alt="" />
        </Link>
      </nav>
    </q-footer>
  </q-layout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'

// The drawer's emoji for the same two pages, as images for the reason layout.vue gives.
import house from '../images/emoji/house.svg'
import receipt from '../images/emoji/receipt.svg'

const page = usePage()

const activeOn = component => ({ 'app-tabbar__tab--active': page.component === component })
</script>
