<script setup>
import { computed, onMounted, ref } from 'vue'
import { gcCatalogRequest } from '../services/gc-catalog-api'
import { formatAmount } from '../utils/formatters'
import GcEmptyState from '../components/GcEmptyState.vue'
import GcLoadingState from '../components/GcLoadingState.vue'
import GcCatalogFormField from '../components/GcCatalogFormField.vue'
import GcCatalogTablePanel from '../components/GcCatalogTablePanel.vue'

const activities = ref([])
const tariffs = ref([])
const csrf = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const info = ref('')
const selectedActivity = ref('')
const editorVisible = ref(false)
const editingId = ref(null)
const form = ref({ activityTypeId: '', period: '', amount: '', active: true })
const periods = ref([])
const labelPeriod = value => periods.value.find(p => p.value === value)?.label ?? value
const money = formatAmount
const filtered = computed(() => tariffs.value.filter(item => !selectedActivity.value || String(item.activityTypeId) === String(selectedActivity.value)))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [a, t, p] = await Promise.all([
      gcCatalogRequest('GET', '/api/admin/catalog/activities'),
      gcCatalogRequest('GET', '/api/admin/catalog/tariffs'),
      gcCatalogRequest('GET', '/api/admin/catalog/periods'),
    ])
    if (!Array.isArray(p.items) || p.items.length === 0 ||
        !p.items.every(x => typeof x.value === 'string' && typeof x.label === 'string')) {
      throw new Error('Référentiel des périodicités indisponible.')
    }
    activities.value = a.items
    tariffs.value = t.items
    periods.value = p.items
    csrf.value = t.csrf
  } catch (e) { error.value = e.message }
  finally { loading.value = false }
}
function startCreate() {
  if (!activities.value.length || !periods.value.length) return
  editingId.value = null
  form.value = { activityTypeId: selectedActivity.value || activities.value[0]?.id || '', period: periods.value[0].value, amount: '', active: true }
  editorVisible.value = true; error.value = ''; info.value = ''
}
function startEdit(item) {
  editingId.value = item.id
  form.value = { activityTypeId: item.activityTypeId, period: item.period, amount: item.amount, active: item.active }
  editorVisible.value = true; error.value = ''; info.value = ''
}
function cancel() { editorVisible.value = false; editingId.value = null }
async function save() {
  if (saving.value) return
  if (form.value.period === 'monthly' && !window.confirm('Le montant mensuel sera appliqué aux futures factures générées pour cette activité. Confirmer le changement ?')) return
  saving.value = true; error.value = ''; info.value = ''
  try {
    const url = editingId.value ? `/api/admin/catalog/tariffs/${editingId.value}` : '/api/admin/catalog/tariffs'
    const body = editingId.value
      ? { amount: String(form.value.amount).replace(',', '.'), active: form.value.active }
      : { activityTypeId: Number(form.value.activityTypeId), period: form.value.period, amount: String(form.value.amount).replace(',', '.'), active: form.value.active }
    await gcCatalogRequest(editingId.value ? 'PATCH' : 'POST', url, body, csrf.value)
    info.value = 'Tarif enregistré.'; cancel(); await load()
  } catch (e) { error.value = e.message }
  finally { saving.value = false }
}
async function remove(item) {
  if (saving.value) return
  const warning = item.period === 'monthly'
    ? 'Attention : supprimer un tarif mensuel ramène son ancien tarif source à zéro, sauf si des commerces l’utilisent (suppression refusée).'
    : 'La suppression retire ce tarif du catalogue.'
  if (!window.confirm(`Supprimer le tarif ${labelPeriod(item.period)} de « ${item.activityLabel} » ?\n${warning}`)) return
  saving.value = true; error.value = ''; info.value = ''
  try {
    await gcCatalogRequest('DELETE', `/api/admin/catalog/tariffs/${item.id}`, {}, csrf.value)
    info.value = 'Tarif supprimé.'; await load()
  } catch (e) { error.value = e.message }
  finally { saving.value = false }
}
onMounted(load)
</script>

<template>
  <section class="gc-catalog">
    <div class="gc-catalog-heading">
      <div><h1>Grille tarifaire</h1><p>Montants en francs CFA (XOF), définis par activité et périodicité.</p></div>
      <button class="btn gc-button-primary" type="button" :disabled="!activities.length || !periods.length || loading" @click="startCreate">+ Nouveau tarif</button>
    </div>
    <div class="gc-catalog-notice" role="note"><strong>Facturation actuelle :</strong> seul le tarif <strong>mensuel</strong> est relié au moteur de génération. Les montants journaliers, hebdomadaires et annuels sont enregistrés mais ne déclenchent pas encore de facturation. Une modification mensuelle peut affecter les prochaines factures, jamais celles déjà émises.</div>
    <div class="gc-catalog-actions">
      <label>Filtrer par activité
        <select v-model="selectedActivity"><option value="">Toutes les activités</option><option v-for="a in activities" :key="a.id" :value="a.id">{{ a.label }}</option></select>
      </label>
      <RouterLink class="btn gc-button-primary" to="/catalogue/activites">Gérer les activités</RouterLink>
    </div>
    <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
    <p v-if="info" class="alert alert-success" role="status">{{ info }}</p>
    <div v-if="editorVisible" class="gc-catalog-panel">
      <h2>{{ editingId ? 'Modifier un tarif' : 'Créer un tarif' }}</h2>
      <form class="gc-catalog-form" @submit.prevent="save">
        <GcCatalogFormField>Activité commerciale
          <select v-model="form.activityTypeId" :disabled="!!editingId" required><option value="" disabled>Choisir</option><option v-for="a in activities" :key="a.id" :value="a.id">{{ a.label }}</option></select>
        </GcCatalogFormField>
        <GcCatalogFormField>Périodicité
          <select v-model="form.period" :disabled="!!editingId" required><option v-for="p in periods" :key="p.value" :value="p.value">{{ p.label }}</option></select>
        </GcCatalogFormField>
        <GcCatalogFormField>Montant (FCFA)
          <input v-model.trim="form.amount" type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" required placeholder="Ex. 5000" />
        </GcCatalogFormField>
        <label class="gc-catalog-checkbox"><input v-model="form.active" type="checkbox" /> Tarif actif</label>
        <div class="gc-catalog-inline">
          <button class="btn gc-button-primary" :disabled="saving" type="submit">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
          <button class="btn gc-button-primary" :disabled="saving" type="button" @click="cancel">Annuler</button>
        </div>
      </form>
    </div>
    <GcCatalogTablePanel>
      <table class="table gc-data-table">
        <thead><tr><th>Activité</th><th>Périodicité</th><th>Montant</th><th>État</th><th>Actions</th></tr></thead>
        <tbody>
          <tr v-for="item in filtered" :key="item.id">
            <td><strong>{{ item.activityLabel }}</strong></td>
            <td>{{ labelPeriod(item.period) }}</td>
            <td>{{ money(item.amount) }} FCFA</td>
            <td><span :class="item.active ? 'gc-catalog-status' : 'gc-catalog-status is-inactive'">{{ item.active ? 'Actif' : 'Inactif' }}</span></td>
            <td><div class="gc-catalog-inline"><button type="button" class="btn gc-button-primary btn-sm" @click="startEdit(item)">Modifier</button><button type="button" class="btn btn-danger btn-sm" @click="remove(item)">Supprimer</button></div></td>
          </tr>
          <tr v-if="!loading && !filtered.length"><GcEmptyState as="td" colspan="5" class="gc-catalog-empty">Aucun tarif trouvé.</GcEmptyState></tr>
        </tbody>
      </table>
      <GcLoadingState v-if="loading" class="gc-catalog-help">Chargement des tarifs…</GcLoadingState>
    </GcCatalogTablePanel>
  </section>
</template>
