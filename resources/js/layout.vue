<template>
  <q-layout view="hHh LpR fFf">
    <q-header bordered class="bg-white text-grey-9">
      <q-toolbar class="q-px-md">
        <q-btn dense flat round icon="menu" color="grey-8" @click="show = !show" />
        <q-avatar size="32px" color="blue-10" text-color="white" icon="savings" class="q-ml-sm" />
        <q-toolbar-title class="text-weight-bold text-blue-10">Budget</q-toolbar-title>
      </q-toolbar>
    </q-header>

    <q-drawer v-model="show" :width="220" bordered class="bg-white" show-if-above>
      <q-list padding class="q-px-sm text-grey-8">
        <q-item
          v-for="menu in menus"
          :key="menu.label"
          v-ripple
          clickable
          :active="menu.active"
          class="rounded-borders q-mb-xs"
          active-class="bg-blue-1 text-blue-10 text-weight-bold"
          @click="menu.to"
        >
          <q-item-section avatar>
            <q-icon :name="menu.icon" />
          </q-item-section>
          <q-item-section>{{ menu.label }}</q-item-section>
        </q-item>
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
  const active = name => page.component === name
  const to = path => router.visit(path)
  return [
    {
      label: 'Home',
      active: active('index'),
      icon: 'dashboard',
      to: () => to('/'),
    },
    {
      label: 'Accounts',
      active: active('account'),
      icon: 'account_balance',
      to: () => to('/accounts'),
    },
    {
      label: 'Transactions',
      active: active('transaction'),
      icon: 'paid',
      to: () => to('/transactions'),
    },
    {
      label: 'Positions',
      active: active('position'),
      icon: 'show_chart',
      to: () => to('/positions'),
    },
    {
      label: 'Categories',
      active: active('category'),
      icon: 'category',
      to: () => to('/categories'),
    },
  ]
})
</script>
