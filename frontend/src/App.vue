<script setup>
import { can, isAdministrator } from './security/abilities'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { auth } from './auth'

const router = useRouter()

async function onLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <header v-if="auth.user" class="topbar">
    <strong>Gestion des commerces</strong>
    <nav>
      <RouterLink v-if="can('commerces.read')" to="/commerces">Commerces</RouterLink>
      <RouterLink v-if="can('commerces.write')" to="/commerces/nouveau">Saisir des commerces</RouterLink>
      <RouterLink v-if="can('factures.read')" to="/factures">Factures et paiements</RouterLink>
      <RouterLink v-if="auth.user?.roles?.includes('ROLE_ADMIN')" to="/annulations">Annulations</RouterLink>
      <RouterLink v-if="can('finance.read')" to="/tableau-de-bord">Tableau de bord</RouterLink>
      <RouterLink v-if="can('recouvrement.read')" to="/recouvrement">Recouvrement</RouterLink>
          <RouterLink v-if="auth.user?.roles?.includes('ROLE_ADMIN')" to="/utilisateurs">Utilisateurs</RouterLink>
          <RouterLink v-if="auth.user?.roles?.includes('ROLE_ADMIN')" to="/roles-permissions">Rôles &amp; permissions</RouterLink>
      <RouterLink v-if="isAdministrator()" to="/roles-attributions">Attributions des rôles</RouterLink>
    </nav>
    <span class="spacer"></span>
    <span class="muted hide-sm">{{ auth.user.email }}</span>
    <button class="btn ghost" @click="onLogout">Déconnexion</button>
  </header>
  <main class="container">
    <RouterView />
  </main>
</template>
