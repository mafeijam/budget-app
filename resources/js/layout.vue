<template>
  <q-layout view="hHh LpR fFf">
    <q-header bordered class="bg-white text-grey-9">
      <q-toolbar class="q-px-md">
        <q-btn dense flat round icon="menu" color="grey-8" @click="show = !show" />
        <AppLogo :size="32" class="q-ml-sm" />
        <q-toolbar-title class="app-wordmark"
          >Ledger<span class="app-wordmark__stop">.</span></q-toolbar-title
        >
        <!-- Every form, from any page: it opens over the page and leaves you on it. -->
        <AddMenu />
      </q-toolbar>
    </q-header>

    <q-drawer v-model="show" :width="220" bordered class="bg-white" show-if-above>
      <q-list padding class="q-px-sm text-grey-8">
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
              <q-icon :name="menu.icon" />
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
const page = usePage()

const show = ref(false)

const menus = computed(() => {
  const item = (label, component, icon, path) => ({
    label,
    icon,
    active: page.component === component,
    to: () => router.visit(path),
  })

  // What is recorded day to day, what is read off it, and the lists both are filed under,
  // which are set up once and visited rarely -- so last, out of the way of the daily two.
  // Positions is a report: it is worked out from trades, and nothing is entered on it.
  return [
    { items: [item('Home', 'index', 'dashboard', '/')] },
    {
      heading: 'Records',
      items: [
        item('Transactions', 'transaction', 'paid', '/transactions'),
        item('Recurring', 'recurring', 'event_repeat', '/recurring'),
      ],
    },
    {
      heading: 'Reports',
      items: [
        item('Positions', 'position', 'show_chart', '/positions'),
        item('Dividends', 'dividend', 'savings', '/dividends'),
        item('Net worth', 'net-worth', 'account_balance_wallet', '/net-worth'),
        item('Cash flow', 'cash-flow', 'insights', '/cash-flow'),
        item('Forecast', 'forecast', 'query_stats', '/forecast'),
        item('Year in review', 'review', 'auto_stories', '/review'),
      ],
    },
    {
      heading: 'Settings',
      items: [
        item('Accounts', 'account', 'account_balance', '/accounts'),
        item('Categories', 'category', 'category', '/categories'),
      ],
    },
  ]
})
</script>
