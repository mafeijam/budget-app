<template>
  <q-layout view="hHh LpR fFf">
    <q-header class="app-header">
      <q-toolbar class="q-px-md">
        <q-btn dense flat round icon="menu" @click="show = !show" />
        <AppLogo :size="32" class="q-ml-sm" />
        <q-toolbar-title class="app-wordmark"
          >Ledger<span class="app-wordmark__stop">.</span></q-toolbar-title
        >
        <!-- Every form, from any page: it opens over the page and leaves you on it. -->
        <AddMenu />
        <SignOutBtn class="q-ml-sm" />
      </q-toolbar>
    </q-header>

    <q-drawer v-model="show" :width="220" class="app-drawer" show-if-above>
      <q-list padding class="q-px-sm">
        <template v-for="group in menus" :key="group.heading ?? 'top'">
          <q-item-label v-if="group.heading" header class="app-nav-heading">
            {{ group.heading }}
          </q-item-label>
          <q-item
            v-for="menu in group.items"
            :key="menu.label"
            v-ripple
            clickable
            :active="menu.active"
            class="rounded-borders q-mb-xs"
            active-class="app-nav-active"
            @click="menu.to"
          >
            <q-item-section avatar>
              <img :src="menu.emoji" class="app-nav-emoji" alt="" />
            </q-item-section>
            <q-item-section>{{ menu.label }}</q-item-section>
          </q-item>
        </template>
      </q-list>
    </q-drawer>

    <q-page-container>
      <q-page class="app-page text-grey-8">
        <slot />
      </q-page>
    </q-page-container>
  </q-layout>
</template>

<script setup>
// Noto's emoji as images rather than text: a machine without an emoji font draws an empty box,
// and @fontsource's Noto Color Emoji renders nothing even in a browser that has one.
import bank from '../images/emoji/bank.svg'
import chartIncreasing from '../images/emoji/chart-increasing.svg'
import crystalBall from '../images/emoji/crystal-ball.svg'
import house from '../images/emoji/house.svg'
import tag from '../images/emoji/tag.svg'
import moneyBag from '../images/emoji/money-bag.svg'
import openBook from '../images/emoji/open-book.svg'
import purse from '../images/emoji/purse.svg'
import receipt from '../images/emoji/receipt.svg'
import repeat from '../images/emoji/repeat.svg'
import waterWave from '../images/emoji/water-wave.svg'

const page = usePage()

const show = ref(false)

const menus = computed(() => {
  const item = (label, component, emoji, path) => ({
    label,
    emoji,
    active: page.component === component,
    to: () => router.visit(path),
  })

  // What is recorded day to day, what is read off it, and the lists both are filed under,
  // which are set up once and visited rarely -- so last, out of the way of the daily two.
  // Positions is a report: it is worked out from trades, and nothing is entered on it.
  return [
    {
      items: [item('Home', 'index', house, '/')],
    },
    {
      heading: 'Records',
      items: [
        item('Transactions', 'transaction', receipt, '/transactions'),
        item('Recurring', 'recurring', repeat, '/recurring'),
      ],
    },
    {
      heading: 'Reports',
      items: [
        item('Positions', 'position', chartIncreasing, '/positions'),
        item('Dividends', 'dividend', moneyBag, '/dividends'),
        item('Net worth', 'net-worth', purse, '/net-worth'),
        item('Cash flow', 'cash-flow', waterWave, '/cash-flow'),
        item('Forecast', 'forecast', crystalBall, '/forecast'),
        item('Year in review', 'review', openBook, '/review'),
      ],
    },
    {
      heading: 'Settings',
      items: [
        item('Accounts', 'account', bank, '/accounts'),
        item('Categories', 'category', tag, '/categories'),
      ],
    },
  ]
})
</script>
