<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { auth } from '../auth'
import { ApiError } from '../api'
import GcIcon from '../components/GcIcon.vue'

const route = useRoute()
const router = useRouter()
const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)
const passwordVisible = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value.trim(), password.value)
    // gc-manager-link-v1 : destination propre au gérant.
    const merchantOnly = auth.user?.roles?.includes('ROLE_GERANT') &&
      !['ROLE_ADMIN', 'ROLE_AGENT', 'ROLE_REGISSEUR', 'ROLE_COMPTABLE'].some(r => auth.user?.roles?.includes(r))
    const target = String(route.query.redirect || '')
    await router.push(target.startsWith('/') && !target.startsWith('//') ? target : { name: merchantOnly ? 'merchant-businesses' : 'businesses' })
  } catch (e) {
    error.value = e instanceof ApiError && e.status === 401
      ? 'Adresse e-mail ou mot de passe incorrect.'
      : 'Connexion impossible. Veuillez réessayer.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="gc-login-page">
    <div class="gc-login-scene" aria-hidden="true">
      <span class="gc-scene-sun"></span>
      <span class="gc-scene-field"></span>
      <span class="gc-scene-flag"><i></i><i></i><i></i></span>
    </div>
    <div class="gc-login-layout">
      <section class="gc-login-left" aria-labelledby="gc-login-title">
        <img src="/gc-attinguie-logo.png" class="gc-login-watermark" alt="" aria-hidden="true" />
        <div class="gc-login-foreground">
          <div class="gc-login-heading-brand">
            <img src="/gc-attinguie-logo.png" alt="Emblème traditionnel du Dandou" class="gc-login-emblem" />
            <div class="gc-login-brand-text">
              <strong>GC-ATTINGUIÉ</strong>
              <span>Gestion communale des commerces</span>
            </div>
          </div>

          <div class="gc-login-intro">
            <span class="gc-login-intro-icon"><GcIcon name="lock" :size="27" /></span>
            <div>
              <h1 id="gc-login-title">Connexion</h1>
              <p>Accédez à votre espace de gestion municipale</p>
            </div>
          </div>

          <form class="gc-login-form" @submit.prevent="submit">
            <label for="gc-email"><span class="gc-field-label"><GcIcon name="mail" :size="17" /> Adresse e-mail</span></label>
            <input id="gc-email" v-model="email" type="email" class="form-control" autocomplete="username" placeholder="Ex. : utilisateur@attinguie.ci" required autofocus />

            <label for="gc-password"><span class="gc-field-label"><GcIcon name="lock" :size="17" /> Mot de passe</span></label>
            <div class="gc-password-wrap">
              <input id="gc-password" v-model="password" :type="passwordVisible ? 'text' : 'password'" class="form-control" autocomplete="current-password" placeholder="Saisissez votre mot de passe" required />
              <button type="button" class="gc-password-toggle" :aria-label="passwordVisible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="passwordVisible = !passwordVisible"><GcIcon :name="passwordVisible ? 'eyeoff' : 'eye'" :size="19" /></button>
            </div>
            <p v-if="error" class="gc-form-error" role="alert">{{ error }}</p>
            <button type="submit" class="btn gc-button-primary gc-login-submit" :disabled="loading">
              <span>{{ loading ? 'Connexion en cours…' : 'Se connecter' }}</span><GcIcon :name="loading ? 'lock' : 'chevron'" :size="22" />
            </button>
          </form>
          <div class="gc-login-security"><GcIcon name="shield" :size="18" /><span>Plateforme sécurisée de gestion des commerces</span></div>
        </div>
      </section>

      <aside class="gc-login-right" aria-label="Présentation de la plateforme">
        <div class="gc-login-right-content">
          <h2>Pilotez les commerces,<br />la facturation et les paiements<br />en toute simplicité</h2>
          <div class="gc-login-window" aria-hidden="true">
            <div class="gc-window-top"><span></span><span></span><span></span><b>GC-ATTINGUIÉ</b></div>
            <div class="gc-window-body">
              <div class="gc-window-menu"><i></i><i></i><i></i><i></i></div>
              <div class="gc-window-panels">
                <div class="gc-window-tile"><GcIcon name="shop" :size="22"/><small>Commerces</small></div>
                <div class="gc-window-tile gc-window-tile-orange"><GcIcon name="file" :size="22"/><small>Factures</small></div>
                <div class="gc-window-tile"><GcIcon name="coins" :size="22"/><small>Paiements</small></div>
                <div class="gc-window-bars"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
              </div>
            </div>
          </div>
          <div class="gc-login-highlights">
            <div><GcIcon name="shop" :size="25"/><span>Gestion des<br />commerces</span></div>
            <div><GcIcon name="file" :size="25"/><span>Facturation<br />et recettes</span></div>
            <div><GcIcon name="coins" :size="25"/><span>Suivi des<br />paiements</span></div>
          </div>
        </div>
      </aside>
    </div>
    <div class="gc-login-bottom-stripes" aria-hidden="true"><i></i><i></i><i></i></div>
  </div>
</template>
