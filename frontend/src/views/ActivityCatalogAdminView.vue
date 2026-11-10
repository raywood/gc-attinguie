<script setup>
import { computed, onMounted, ref } from 'vue'
import { gcCatalogRequest } from '../services/gc-catalog-api'
import { formatAmount } from '../utils/formatters'

const rows = ref([])
const csrf = ref('')
const query = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const info = ref('')
const editingId = ref(null)
const editorVisible = ref(false)
const form = ref({ code: '', label: '' })
const money = formatAmount
const filtered = computed(() => rows.value.filter(item => `${item.label} ${item.code}`.toLocaleLowerCase('fr').includes(query.value.trim().toLocaleLowerCase('fr'))))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await gcCatalogRequest('GET', '/api/admin/catalog/activities')
    rows.value = data.items
    csrf.value = data.csrf
  } catch (e) { error.value = e.message }
  finally { loading.value = false }
}
function startCreate() {
  editingId.value = null
  form.value = { code: '', label: '' }
  editorVisible.value = true
  error.value = ''
  info.value = ''
}
function startEdit(item) {
  editingId.value = item.id
  form.value = { code: item.code, label: item.label }
  editorVisible.value = true
  error.value = ''
  info.value = ''
}
function cancel() { editorVisible.value = false; editingId.value = null }
async function save() {
  if (saving.value) return
  saving.value = true
  error.value = ''; info.value = ''
  try {
    const url = editingId.value ? `/api/admin/catalog/activities/${editingId.value}` : '/api/admin/catalog/activities'
    await gcCatalogRequest(editingId.value ? 'PATCH' : 'POST', url, form.value, csrf.value)
    info.value = 'Activité enregistrée.'
    cancel()
    await load()
  } catch (e) { error.value = e.message }
  finally { saving.value = false }
}
async function remove(item) {
  if (saving.value) return
  const message = item.businessCount > 0 || item.tariffCount > 0
    ? 'Cette activité est liée à des commerces ou tarifs. Elle ne pourra pas être supprimée tant que ces liens existent.'
    : `Supprimer définitivement l’activité « ${item.label} » ?`
  if (!window.confirm(message)) return
  saving.value = true
  error.value = ''; info.value = ''
  try {
    await gcCatalogRequest('DELETE', `/api/admin/catalog/activities/${item.id}`, {}, csrf.value)
    info.value = 'Activité supprimée.'
    await load()
  } catch (e) { error.value = e.message }
  finally { saving.value = false }
}
onMounted(load)
</script>

<template>
  <section class="gc-catalog">
    <div class="gc-catalog-heading">
      <div><h1>Activités commerciales</h1><p>Gérez les catégories reconnues par la mairie, sans modifier les factures existantes.</p></div>
      <button type="button" class="btn gc-button-primary" @click="startCreate">+ Nouvelle activité</button>
    </div>
    <div class="gc-catalog-actions">
      <label>Rechercher une activité <input v-model="query" type="search" placeholder="Nom ou code" /></label>
      <RouterLink class="btn gc-button-primary" to="/catalogue/tarifs">Gérer les tarifs</RouterLink>
    </div>
    <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
    <p v-if="info" class="alert alert-success" role="status">{{ info }}</p>
    <div v-if="editorVisible" class="gc-catalog-panel">
      <h2>{{ editingId ? 'Modifier une activité' : 'Créer une activité' }}</h2>
      <form class="gc-catalog-form" @submit.prevent="save">
        <label>Code de référence
          <input v-model.trim="form.code" type="text" maxlength="50" required autocomplete="off" placeholder="EX. BOULANGERIE" />
        </label>
        <label>Libellé de l'activité
          <input v-model.trim="form.label" type="text" maxlength="120" minlength="2" required placeholder="Ex. Boulangerie" />
        </label>
        <p class="gc-catalog-help">Le code d'une activité déjà utilisée ne peut pas être renommé. Pour ses tarifs, ouvrez « Gérer les tarifs ».</p>
        <div class="gc-catalog-inline">
          <button type="submit" class="btn gc-button-primary" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
          <button type="button" class="btn gc-button-primary" :disabled="saving" @click="cancel">Annuler</button>
        </div>
      </form>
    </div>
    <div class="gc-catalog-panel gc-catalog-scroll">
      <table class="table gc-data-table">
        <thead><tr><th>Code</th><th>Activité</th><th>Commerces</th><th>Tarif mensuel actuel</th><th>Actions</th></tr></thead>
        <tbody>
          <tr v-for="item in filtered" :key="item.id">
            <td><code>{{ item.code }}</code></td><td><strong>{{ item.label }}</strong></td>
            <td>{{ item.businessCount }}</td>
            <td>{{ money(item.monthlyFee) }} FCFA</td>
            <td><div class="gc-catalog-inline"><button class="btn gc-button-primary btn-sm" type="button" @click="startEdit(item)">Modifier</button><button class="btn btn-danger btn-sm" type="button" @click="remove(item)">Supprimer</button></div></td>
          </tr>
          <tr v-if="!loading && !filtered.length"><td colspan="5" class="gc-catalog-empty">Aucune activité trouvée.</td></tr>
        </tbody>
      </table>
      <p v-if="loading" role="status" class="gc-catalog-help">Chargement des activités…</p>
    </div>
  </section>
</template>
