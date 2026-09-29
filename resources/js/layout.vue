<template>
  <q-layout view="hHh LpR fFf">
    <q-header bordered class="bg-white text-grey-9">
      <q-toolbar class="q-px-md">
        <q-btn dense flat round icon="menu" color="grey-8" @click="show = !show" />
        <q-avatar size="32px" color="primary" text-color="white" icon="savings" class="q-ml-sm" />
        <q-toolbar-title class="text-weight-bold text-primary">Budget</q-toolbar-title>
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

  // What is recorded, then what is read off it.
  return [
    { items: [item('Home', 'index', 'dashboard', '/')] },
    {
      heading: 'Records',
      items: [
        item('Transactions', 'transaction', 'paid', '/transactions'),
        item('Recurring', 'recurring', 'event_repeat', '/recurring'),
        item('Accounts', 'account', 'account_balance', '/accounts'),
        item('Positions', 'position', 'show_chart', '/positions'),
        item('Categories', 'category', 'category', '/categories'),
      ],
    },
    {
      heading: 'Reports',
      items: [
        item('Net worth', 'net-worth', 'account_balance_wallet', '/net-worth'),
        item('Cash flow', 'cash-flow', 'insights', '/cash-flow'),
        item('Forecast', 'forecast', 'query_stats', '/forecast'),
      ],
    },
  ]
})
</script>
