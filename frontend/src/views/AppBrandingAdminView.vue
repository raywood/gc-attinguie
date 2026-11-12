<script setup>
import { onMounted, ref } from 'vue'
import GcIcon from '../components/GcIcon.vue'
import { branding, setBranding } from '../services/gc-branding'

const form = ref({ appName: '', organizationName: '', dashboardDescription: '', version: 1 })
const csrf = ref('')
const file = ref(null)
const loading = ref(true)
const saving = ref(false)
const uploading = ref(false)
const error = ref('')
const info = ref('')

function populate(data) {
  if (!setBranding(data)) throw new Error('Réponse du serveur invalide.')
  form.value = {
    appName: data.appName,
    organizationName: data.organizationName,
    dashboardDescription: data.dashboardDescription,
    version: data.version,
  }
}

async function readResponse(response) {
  const data = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(data.error || `Erreur HTTP ${response.status}`)
  return data
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const response = await fetch('/api/admin/branding', {
      credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store',
    })
    const data = await readResponse(response)
    csrf.value = data.csrf
    populate(data)
  } catch (e) { error.value = e.message }
  finally { loading.value = false }
}

async function saveText() {
  if (saving.value || uploading.value) return
  saving.value = true; error.value = ''; info.value = ''
  try {
    const response = await fetch('/api/admin/branding', {
      method: 'PATCH', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.value },
      body: JSON.stringify(form.value),
    })
    populate(await readResponse(response))
    info.value = 'Nom, collectivité et texte du tableau de bord enregistrés.'
  } catch (e) { error.value = e.message }
  finally { saving.value = false }
}

function selectLogo(event) {
  file.value = event.target.files?.[0] || null
}

/** Compression dans le navigateur : compatible avec le plafond HTTP existant. */
async function optimizedImage(source) {
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(source.type)) {
    throw new Error('Formats acceptés : PNG, JPEG ou WebP. SVG interdit.')
  }
  if (source.size > 5 * 1024 * 1024) throw new Error('Fichier original limité à 5 Mo.')
  const url = URL.createObjectURL(source)
  let image
  try {
    image = await new Promise((resolve, reject) => {
      const img = new Image()
      img.onload = () => resolve(img)
      img.onerror = () => reject(new Error('Image illisible.'))
      img.src = url
    })
    if (!image.naturalWidth || !image.naturalHeight || image.naturalWidth * image.naturalHeight > 12_000_000) {
      throw new Error('Image trop grande : 12 millions de pixels maximum.')
    }
    const scale = Math.min(1, 800 / Math.max(image.naturalWidth, image.naturalHeight))
    const canvas = document.createElement('canvas')
    canvas.width = Math.max(1, Math.round(image.naturalWidth * scale))
    canvas.height = Math.max(1, Math.round(image.naturalHeight * scale))
    canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height)
    for (const quality of [0.9, 0.8, 0.7, 0.55]) {
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/webp', quality))
      if (blob && blob.type === 'image/webp' && blob.size <= 700_000) {
        return new File([blob], 'logo.webp', { type: 'image/webp' })
      }
    }
    throw new Error('Impossible de compresser le logo sous 700 Ko.')
  } finally { URL.revokeObjectURL(url) }
}

async function uploadLogo() {
  if (!file.value || saving.value || uploading.value) return
  uploading.value = true; error.value = ''; info.value = ''
  try {
    const prepared = await optimizedImage(file.value)
    const body = new FormData()
    body.append('version', String(form.value.version))
    body.append('logo', prepared, 'logo.webp')
    const response = await fetch('/api/admin/branding/logo', {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': csrf.value }, body,
    })
    populate(await readResponse(response))
    file.value = null
    const input = document.getElementById('gc-branding-logo-input')
    if (input) input.value = ''
    info.value = 'Logo remplacé sur les écrans de l’application.'
  } catch (e) { error.value = e.message }
  finally { uploading.value = false }
}

onMounted(load)
</script>

<template>
  <section class="gc-catalog gc-branding-admin">
    <div class="gc-catalog-heading">
      <div>
        <h1>Personnalisation de l’application</h1>
        <p>Nom, identité de la collectivité, description et logo visibles dans les interfaces.</p>
      </div>
    </div>
    <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
    <p v-if="info" class="alert alert-success" role="status">{{ info }}</p>
    <p v-if="loading" class="gc-catalog-help" role="status">Chargement des paramètres…</p>

    <template v-else>
      <div class="gc-catalog-panel">
        <h2><GcIcon name="settings" :size="20" /> Identité de l’application</h2>
        <form class="gc-catalog-form" @submit.prevent="saveText">
          <label>Nom de l’application
            <input v-model.trim="form.appName" class="form-control" type="text" minlength="3" maxlength="100" required placeholder="Ex. G-AKOUPE" />
          </label>
          <label>Nom de la collectivité
            <input v-model.trim="form.organizationName" class="form-control" type="text" minlength="3" maxlength="150" required placeholder="Ex. Commune d’Akoupé" />
          </label>
          <label class="gc-branding-description">Texte de présentation du tableau de bord
            <textarea v-model.trim="form.dashboardDescription" class="form-control" rows="3" minlength="10" maxlength="500" required placeholder="La plateforme de gestion des commerces…"></textarea>
          </label>
          <p class="gc-catalog-help">Le titre du tableau de bord sera automatiquement « Bienvenue sur {{ form.appName || '…' }} ».</p>
          <div class="gc-catalog-inline">
            <button type="submit" class="btn gc-button-primary" :disabled="saving || uploading">
              {{ saving ? 'Enregistrement…' : 'Enregistrer les textes' }}
            </button>
            <button type="button" class="btn gc-button-outline" :disabled="saving || uploading" @click="load">Annuler les modifications</button>
          </div>
        </form>
      </div>

      <div class="gc-catalog-panel">
        <h2><GcIcon name="shop" :size="20" /> Logo de l’application</h2>
        <div class="gc-branding-logo-layout">
          <img :src="branding.logoUrl" :alt="'Logo de ' + branding.appName" class="gc-branding-preview" />
          <div>
            <label for="gc-branding-logo-input">Choisir un nouveau logo (PNG, JPEG ou WebP)</label>
            <input id="gc-branding-logo-input" class="form-control" type="file" accept="image/png,image/jpeg,image/webp" :disabled="saving || uploading" @change="selectLogo" />
            <p class="gc-catalog-help">Jusqu’à 5 Mo à l’origine, puis conversion WebP avec transparence conservée. SVG interdit. Le nouveau logo apparaîtra sur la connexion, le menu et l’onglet du navigateur.</p>
            <button class="btn gc-button-primary" type="button" :disabled="!file || saving || uploading" @click="uploadLogo">
              {{ uploading ? 'Envoi du logo…' : 'Publier ce logo' }}
            </button>
          </div>
        </div>
      </div>
      <div class="gc-catalog-panel">
        <h2>Aperçu du tableau de bord</h2>
        <p class="gc-branding-example-title">Bienvenue sur {{ form.appName }}</p>
        <p class="gc-branding-example-description">{{ form.dashboardDescription }}</p>
      </div>
    </template>
  </section>
</template>
