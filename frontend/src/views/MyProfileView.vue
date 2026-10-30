<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { auth } from '../auth'

const loading = ref(true)
const busy = ref(false)
const uploading = ref(false)
const error = ref('')
const message = ref('')
const csrf = ref('')
const account = ref({ id: null, email: '', roles: [], hasPhoto: false })
const fields = reactive({ firstName: '', lastName: '', phone: '', jobTitle: '' })
const revision = ref(Date.now())
const photoSrc = computed(() => account.value.hasPhoto && account.value.id ? `/api/account/photo/${account.value.id}?v=${revision.value}` : '')
const initials = computed(() => ((fields.firstName[0] || '') + (fields.lastName[0] || '') || account.value.email.slice(0, 2)).toUpperCase())

async function checked(response) {
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(payload.error || `Erreur HTTP ${response.status}`)
  return payload
}

async function loadProfile() {
  loading.value = true
  error.value = ''
  try {
    const result = await checked(await fetch('/api/account/profile', { credentials: 'same-origin', cache: 'no-store' }))
    account.value = result
    csrf.value = result.csrf
    fields.firstName = result.firstName || ''
    fields.lastName = result.lastName || ''
    fields.phone = result.phone || ''
    fields.jobTitle = result.jobTitle || ''
    revision.value = Date.now()
  } catch (err) { error.value = err.message }
  finally { loading.value = false }
}

async function saveProfile() {
  error.value = ''; message.value = ''; busy.value = true
  try {
    await checked(await fetch('/api/account/profile', {
      method: 'PATCH', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf.value },
      body: JSON.stringify({ ...fields }),
    }))
    message.value = 'Tes informations personnelles ont été enregistrées.'
    await auth.fetchMe()
  } catch (err) { error.value = err.message }
  finally { busy.value = false }
}

async function imageToSmallBlob(file) {
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
    throw new Error('Sélectionne une photo JPEG, PNG ou WebP.')
  }
  if (file.size > 30 * 1024 * 1024) {
    throw new Error('Le fichier original dépasse 30 Mo.')
  }
  const url = URL.createObjectURL(file)
  try {
    const image = new Image()
    image.src = url
    await image.decode()
    const factor = Math.min(1, 960 / Math.max(image.naturalWidth, image.naturalHeight))
    const canvas = document.createElement('canvas')
    canvas.width = Math.max(1, Math.round(image.naturalWidth * factor))
    canvas.height = Math.max(1, Math.round(image.naturalHeight * factor))
    canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height)
    // Export volontairement en JPEG pour compatibilité ; le serveur retire les métadonnées et produit du WebP.
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.80))
    if (!blob) throw new Error('Impossible de réduire cette photo.')
    return blob
  } finally { URL.revokeObjectURL(url) }
}

async function uploadPhoto(event) {
  const file = event.target.files?.[0]
  if (!file) return
  error.value = ''; message.value = ''; uploading.value = true
  try {
    const optimized = await imageToSmallBlob(file)
    const form = new FormData()
    form.append('photo', optimized, 'photo.jpg')
    await checked(await fetch('/api/account/photo', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf.value },
      body: form,
    }))
    account.value.hasPhoto = true
    revision.value = Date.now()
    message.value = 'Photo mise à jour et optimisée pour le stockage.'
  } catch (err) { error.value = err.message }
  finally { uploading.value = false; event.target.value = '' }
}

async function deletePhoto() {
  if (!window.confirm('Supprimer ta photo de profil ?')) return
  error.value = ''; message.value = ''; uploading.value = true
  try {
    await checked(await fetch('/api/account/photo', {
      method: 'DELETE', credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf.value },
    }))
    account.value.hasPhoto = false
    revision.value = Date.now()
    message.value = 'Photo supprimée.'
  } catch (err) { error.value = err.message }
  finally { uploading.value = false }
}

onMounted(loadProfile)
</script>

<template>
  <section class="my-profile">
    <h1>Mon profil</h1>
    <p class="muted">Mets à jour tes informations et ta photo de profil. Tes rôles et ton adresse de connexion sont gérés par un administrateur.</p>
    <p v-if="loading" class="muted">Chargement…</p>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="message" class="card" role="status">{{ message }}</p>

    <div v-if="!loading && account.id" class="profile-layout">
      <section class="card avatar-panel">
        <div class="avatar-big">
          <img v-if="photoSrc" :src="photoSrc" alt="Ta photo de profil" @error="account.hasPhoto = false" />
          <span v-else>{{ initials }}</span>
        </div>
        <h2>Photo de profil</h2>
        <p class="muted small">JPEG, PNG ou WebP. Redimensionnement automatique, stockage privé en WebP (640 px max, 200 Ko max).</p>
        <label class="btn profile-file">
          {{ uploading ? 'Traitement…' : 'Choisir une photo' }}
          <input type="file" accept="image/jpeg,image/png,image/webp" :disabled="uploading" @change="uploadPhoto" />
        </label>
        <button v-if="account.hasPhoto" type="button" class="btn ghost" :disabled="uploading" @click="deletePhoto">Supprimer ma photo</button>
      </section>

      <form class="card profile-form" @submit.prevent="saveProfile">
        <h2>Mes informations</h2>
        <label>Prénom <input v-model.trim="fields.firstName" required maxlength="80" autocomplete="given-name" /></label>
        <label>Nom <input v-model.trim="fields.lastName" required maxlength="80" autocomplete="family-name" /></label>
        <label>Téléphone <input v-model.trim="fields.phone" maxlength="32" autocomplete="tel" /></label>
        <label>Fonction <input v-model.trim="fields.jobTitle" maxlength="120" /></label>
        <label>Adresse de connexion <input :value="account.email" disabled /></label>
        <p class="muted small">Rôle(s) : {{ (account.roles || []).join(', ') || 'Aucun rôle explicite' }}</p>
        <button class="btn" type="submit" :disabled="busy || !csrf">{{ busy ? 'Enregistrement…' : 'Enregistrer mon profil' }}</button>
      </form>
    </div>
  </section>
</template>

<style scoped>
.my-profile { display: grid; gap: 1rem; }
.profile-layout { display: grid; grid-template-columns: minmax(240px, 1fr) minmax(290px, 2fr); gap: 1rem; align-items: start; }
.avatar-panel, .profile-form { display: flex; flex-direction: column; gap: .85rem; }
.avatar-panel { align-items: center; text-align: center; }
.avatar-big { display: grid; place-items: center; width: 132px; height: 132px; border-radius: 50%; background: #e2e8f0; color: #334155; font-size: 2rem; font-weight: 700; overflow: hidden; }
.avatar-big img { width: 100%; height: 100%; object-fit: cover; }
.profile-file { display: inline-flex; cursor: pointer; }
.profile-file input { display: none; }
.profile-form label { display: grid; gap: .35rem; text-align: left; }
.profile-form input { width: 100%; }
@media (max-width: 720px) { .profile-layout { grid-template-columns: 1fr; } }
</style>
