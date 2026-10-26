<script setup>
import { reactive, ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '../api'

const route = useRoute()
const id = String(route.params.id)
const types = ref([])
const loading = ref(true)
const saving = ref(false)
const saved = ref(false)
const error = ref('')
const fieldErrors = ref({})
const form = reactive({
  name: '', activityTypeId: '', district: '', address: '',
  managerLastName: '', managerFirstName: '', managerPhone: '', status: 'active',
})
const statuses = [
  { value: 'active', label: 'Actif' },
  { value: 'suspended', label: 'Suspendu' },
  { value: 'closed', label: 'Fermé' },
]

onMounted(async () => {
  try {
    const [business, activityTypes] = await Promise.all([
      api.get(`/api/businesses/${encodeURIComponent(id)}`),
      api.get('/api/activity-types'),
    ])
    types.value = activityTypes
    for (const key of Object.keys(form)) form[key] = business[key] ?? ''
    form.activityTypeId = String(business.activityTypeId)
  } catch (e) {
    error.value = e.message || 'Impossible de charger ce commerce.'
  } finally {
    loading.value = false
  }
})

async function save() {
  saving.value = true
  saved.value = false
  error.value = ''
  fieldErrors.value = {}
  try {
    const payload = { ...form, activityTypeId: form.activityTypeId ? Number(form.activityTypeId) : null }
    await api.put(`/api/businesses/${encodeURIComponent(id)}`, payload)
    saved.value = true
  } catch (e) {
    if (e instanceof ApiError && e.status === 422 && e.data?.errors) {
      fieldErrors.value = e.data.errors
      error.value = 'Corrigez les champs signalés.'
    } else {
      error.value = e.message || 'Modification impossible.'
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="row-head">
    <h1>Modifier le commerce</h1>
    <RouterLink to="/commerces" class="btn ghost">← Retour à la liste</RouterLink>
  </div>
  <p v-if="loading">Chargement du commerce…</p>
  <p v-if="error" class="error" role="alert">{{ error }}</p>
  <p v-if="saved" class="success" role="status">Modification enregistrée. <RouterLink to="/commerces">Voir la liste</RouterLink></p>

  <form v-if="!loading && types.length" class="card" @submit.prevent="save">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem">
      <label>Nom du commerce *
        <input v-model.trim="form.name" maxlength="180" required />
        <small v-if="fieldErrors.name" class="error">{{ fieldErrors.name }}</small>
      </label>
      <label>Type d'activité *
        <select v-model="form.activityTypeId" required>
          <option value="">— Choisir —</option>
          <option v-for="type in types" :key="type.id" :value="String(type.id)">{{ type.label }}</option>
        </select>
        <small v-if="fieldErrors.activityTypeId" class="error">{{ fieldErrors.activityTypeId }}</small>
      </label>
      <label>Quartier
        <input v-model.trim="form.district" maxlength="120" />
        <small v-if="fieldErrors.district" class="error">{{ fieldErrors.district }}</small>
      </label>
      <label>Adresse
        <input v-model.trim="form.address" maxlength="255" />
        <small v-if="fieldErrors.address" class="error">{{ fieldErrors.address }}</small>
      </label>
      <label>Nom du gérant
        <input v-model.trim="form.managerLastName" maxlength="100" />
        <small v-if="fieldErrors.managerLastName" class="error">{{ fieldErrors.managerLastName }}</small>
      </label>
      <label>Prénom du gérant
        <input v-model.trim="form.managerFirstName" maxlength="100" />
        <small v-if="fieldErrors.managerFirstName" class="error">{{ fieldErrors.managerFirstName }}</small>
      </label>
      <label>Téléphone du gérant
        <input v-model.trim="form.managerPhone" type="tel" maxlength="30" />
        <small v-if="fieldErrors.managerPhone" class="error">{{ fieldErrors.managerPhone }}</small>
      </label>
      <label>Statut
        <select v-model="form.status">
          <option v-for="status in statuses" :key="status.value" :value="status.value">{{ status.label }}</option>
        </select>
        <small v-if="fieldErrors.status" class="error">{{ fieldErrors.status }}</small>
      </label>
    </div>
    <p class="muted small">Un changement de gérant conserve l'ancien responsable dans l'historique.</p>
    <div class="actions">
      <RouterLink to="/commerces" class="btn ghost">Annuler</RouterLink>
      <button type="submit" class="btn" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer les modifications' }}</button>
    </div>
  </form>
</template>
