<template>
  <q-layout view="hHh LpR fFf">
    <q-header class="shadow-1 bg-blue-10">
      <q-toolbar>
        <q-btn dense flat round icon="menu" @click="show = !show" />
        <q-toolbar-title>App</q-toolbar-title>
        <q-space />
        <q-btn dense flat round icon="add">
          <q-menu :offset="[0, 6]">
            <q-card style="width: 300px" class="shadow-1">
              <q-card-section>menu</q-card-section>
            </q-card>
          </q-menu>
        </q-btn>
      </q-toolbar>
    </q-header>

    <q-drawer v-model="show" :width="200" bordered class="bg-grey-1" show-if-above>
      <q-list v-for="menu in menus" :key="menu.label" class="text-grey-7" @click="menu.to">
        <q-item
          v-ripple
          clickable
          :active="menu.active"
          active-class="text-weight-bold text-blue-10"
        >
          <q-item-section avatar>
            <q-icon :name="menu.icon" />
          </q-item-section>
          <q-item-section>{{ menu.label }}</q-item-section>
        </q-item>
      </q-list>
    </q-drawer>

    <q-page-container>
      <q-page class="q-pa-md text-grey-8">
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
      label: 'Categories',
      active: active('category'),
      icon: 'category',
      to: () => to('/categories'),
    },
  ]
})
</script>
