<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { auth } from '../auth'
import { ApiError } from '../api'

const route = useRoute()
const router = useRouter()
const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value.trim(), password.value)
    const target = String(route.query.redirect || '')
    // On n'accepte que des chemins internes (évite une redirection ouverte).
    router.push(target.startsWith('/') && !target.startsWith('//') ? target : { name: 'businesses' })
  } catch (e) {
    error.value = e instanceof ApiError && e.status === 401 ? 'Identifiants incorrects.' : 'Connexion impossible, réessayez.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="card login" @submit.prevent="submit">
    <h1>Connexion</h1>
    <label>
      Adresse e-mail
      <input v-model="email" type="email" autocomplete="username" required autofocus />
    </label>
    <label>
      Mot de passe
      <input v-model="password" type="password" autocomplete="current-password" required />
    </label>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <button class="btn" :disabled="loading">{{ loading ? 'Connexion…' : 'Se connecter' }}</button>
  </form>
</template>
