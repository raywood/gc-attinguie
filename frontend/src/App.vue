<script setup>
import GcToastHost from './components/GcToastHost.vue'
import { computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { can, isAdministrator } from './security/abilities'
import { auth } from './auth'
import GcIcon from './components/GcIcon.vue'

const router = useRouter()
const route = useRoute()
const menuOpen = ref(false)
const headerSearch = ref('')
const isAdmin = computed(() => isAdministrator())
const pageTitle = computed(() => {
  if (route.path.startsWith('/catalogue/activites')) return 'Activités commerciales'
  if (route.path.startsWith('/catalogue/tarifs')) return 'Grille tarifaire'
  if (route.path.startsWith('/tableau-de-bord')) return 'Tableau de bord'
  if (route.path.startsWith('/commerces')) return 'Gestion des commerces'
  if (route.path.startsWith('/factures')) return 'Factures et paiements'
  if (route.path.startsWith('/recouvrement')) return 'Recouvrement'
  if (route.path.startsWith('/utilisateurs')) return 'Utilisateurs'
  if (route.path.startsWith('/roles')) return 'Rôles et permissions'
  if (route.path.startsWith('/mon-profil')) return 'Mon profil'
  if (route.path.startsWith('/annulations')) return 'Annulations'
  return 'GC-ATTINGUIÉ'
})
const userLabel = computed(() => auth.user?.email || 'Compte utilisateur')
const userInitial = computed(() => userLabel.value.charAt(0).toUpperCase())

watch(() => route.fullPath, () => { menuOpen.value = false })

function searchBusinesses() {
  if (!can('commerces.read')) return
  router.push({ name: 'businesses', query: headerSearch.value.trim() ? { q: headerSearch.value.trim() } : {} })
}

async function onLogout() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <div v-if="auth.user" class="gc-shell">
    <div v-if="menuOpen" class="gc-mobile-overlay" @click="menuOpen = false" aria-hidden="true"></div>
    <aside class="gc-sidebar" :class="{ 'is-open': menuOpen }" aria-label="Navigation principale">
      <div class="gc-side-brand">
        <img src="/gc-attinguie-logo.png" alt="Emblème traditionnel du Dandou" class="gc-side-emblem" />
        <strong>GC-ATTINGUIÉ</strong>
        <span>Gestion communale des commerces</span>
      </div>
      <nav class="gc-side-nav">
        <RouterLink v-if="can('finance.read')" to="/tableau-de-bord" class="gc-side-link">
          <GcIcon name="dashboard"/> <span>Tableau de bord</span>
        </RouterLink>
        <RouterLink v-if="can('commerces.read')" to="/commerces" class="gc-side-link" :class="{ 'router-link-active': route.path.startsWith('/commerces') }">
          <GcIcon name="shop"/> <span>Commerces</span>
        </RouterLink>
        <RouterLink v-if="can('factures.read')" to="/factures" class="gc-side-link">
          <GcIcon name="file"/> <span>Factures et paiements</span>
        </RouterLink>
        <RouterLink v-if="can('recouvrement.read')" to="/recouvrement" class="gc-side-link">
          <GcIcon name="coins"/> <span>Recouvrement</span>
        </RouterLink>
        <RouterLink v-if="isAdmin" to="/annulations" class="gc-side-link">
          <GcIcon name="alert"/> <span>Annulations</span>
        </RouterLink>
        <div class="gc-side-divider"></div>
        <div class="gc-side-section">Administration</div>
        <RouterLink v-if="isAdmin" to="/catalogue/activites" class="gc-side-link">
          <GcIcon name="shop"/> <span>Activités commerciales</span>
        </RouterLink>
        <RouterLink v-if="isAdmin" to="/catalogue/tarifs" class="gc-side-link">
          <GcIcon name="coins"/> <span>Grille tarifaire</span>
        </RouterLink>
        <RouterLink v-if="isAdmin" to="/utilisateurs" class="gc-side-link">
          <GcIcon name="users"/> <span>Utilisateurs</span>
        </RouterLink>
        <RouterLink v-if="isAdmin" to="/roles-permissions" class="gc-side-link">
          <GcIcon name="shield"/> <span>Rôles et permissions</span>
        </RouterLink>
        <RouterLink v-if="isAdmin" to="/roles-attributions" class="gc-side-link">
          <GcIcon name="settings"/> <span>Attribution des rôles</span>
        </RouterLink>
        <RouterLink to="/mon-profil" class="gc-side-link">
          <GcIcon name="users"/> <span>Mon profil</span>
        </RouterLink>
      </nav>
      <div class="gc-side-bottom">
        <span class="gc-side-flag"><i></i><i></i><i></i></span>
        <small>Commune d’Attinguié · Côte d’Ivoire</small>
      </div>
    </aside>

    <div class="gc-app-column">
      <header class="gc-topbar">
        <div class="gc-topbar-title">
          <button type="button" class="gc-mobile-menu" aria-label="Ouvrir le menu" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen"><GcIcon :name="menuOpen ? 'close' : 'menu'"/></button>
          <div><strong>{{ pageTitle }}</strong><div class="gc-title-underline"></div></div>
        </div>
        <form v-if="can('commerces.read')" class="gc-top-search" role="search" @submit.prevent="searchBusinesses">
          <label class="visually-hidden" for="gc-global-search">Rechercher un commerce</label>
          <GcIcon name="search" :size="19" />
          <input id="gc-global-search" v-model="headerSearch" type="search" placeholder="Rechercher un commerce…" />
        </form>
        <div class="gc-account">
          <RouterLink to="/mon-profil" class="gc-user-chip" :title="userLabel">
            <span class="gc-user-avatar">{{ userInitial }}</span>
            <span class="gc-user-name">{{ userLabel }}</span>
          </RouterLink>
          <button type="button" class="gc-logout" title="Se déconnecter" aria-label="Se déconnecter" @click="onLogout"><GcIcon name="logout"/></button>
        </div>
      </header>
      <main id="main-content" class="gc-main"><RouterView /></main>
    </div>
  </div>
  <main v-else class="gc-public-main"><RouterView /></main>
  <GcToastHost />
</template>
