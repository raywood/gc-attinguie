<script setup>
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
      <RouterLink to="/commerces">Commerces</RouterLink>
      <RouterLink to="/commerces/nouveau">Saisir des commerces</RouterLink>
      <RouterLink v-if="auth.user?.roles?.includes('ROLE_ADMIN')" to="/factures">Factures et paiements</RouterLink>
      <RouterLink v-if="auth.user?.roles?.includes('ROLE_ADMIN')" to="/annulations">Annulations</RouterLink>
      <RouterLink v-if="auth.user?.roles?.includes('ROLE_ADMIN')" to="/tableau-de-bord">Tableau de bord</RouterLink>
    </nav>
    <span class="spacer"></span>
    <span class="muted hide-sm">{{ auth.user.email }}</span>
    <button class="btn ghost" @click="onLogout">Déconnexion</button>
  </header>
  <main class="container">
    <RouterView />
  </main>
</template>
