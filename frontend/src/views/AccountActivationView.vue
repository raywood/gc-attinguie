<script setup>
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '../api'

// Le fragment #token= ne transite pas dans les journaux HTTP de Nginx.
const params = new URLSearchParams(window.location.hash.replace(/^#/, ''))
const token = ref(params.get('token') || '')
if (token.value) window.history.replaceState(window.history.state, '', '/activation')
const password = ref('')
const confirmPassword = ref('')
const busy = ref(false)
const error = ref('')
const success = ref(false)

async function activate() {
  error.value = ''
  if (password.value !== confirmPassword.value) { error.value = 'Les mots de passe sont différents.'; return }
  if (!token.value) { error.value = 'Lien d’activation manquant ou invalide.'; return }
  busy.value = true
  try {
    await api.post('/api/activate-account', { token: token.value, password: password.value })
    success.value = true
    token.value = ''
    password.value = ''
    confirmPassword.value = ''
  } catch (e) { error.value = e.message || 'Activation impossible.' }
  finally { busy.value = false }
}
</script>

<template>
  <form class="card login" @submit.prevent="activate">
    <h1>Activation du compte municipal</h1>
    <p v-if="success" role="status">Votre compte est activé. <RouterLink to="/connexion">Connectez-vous</RouterLink>.</p>
    <template v-else>
      <p class="muted small">Choisissez un mot de passe personnel : 12 caractères minimum, avec une majuscule, une minuscule et un chiffre.</p>
      <p v-if="!token" class="error">Invitation manquante. Demandez un nouveau lien à l’administrateur.</p>
      <label>Nouveau mot de passe <input v-model="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required /></label>
      <label>Confirmer le mot de passe <input v-model="confirmPassword" type="password" autocomplete="new-password" minlength="12" maxlength="128" required /></label>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <button class="btn" :disabled="busy || !token">{{ busy ? 'Activation…' : 'Activer mon compte' }}</button>
    </template>
  </form>
</template>
