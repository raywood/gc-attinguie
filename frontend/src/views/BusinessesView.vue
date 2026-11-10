<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api'
import { useGcDataRefresh } from '../services/gc-data-refresh'
import { can } from '../security/abilities'
import GcIcon from '../components/GcIcon.vue'

const route = useRoute()
const items = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(25)
const q = ref(String(route.query.q || ''))
const district = ref('')
const activity = ref('')
const status = ref('')
const loading = ref(false)
const error = ref('')
const pages = computed(() => Math.max(1, Math.ceil(total.value / Math.max(1, pageSize.value))))
const statusLabels = { active: 'Actif', suspended: 'Suspendu', closed: 'Fermé' }
const districts = computed(() => [...new Set(items.value.map(b => b.district).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'fr')))
const activities = computed(() => [...new Set(items.value.map(b => b.activityType?.label).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'fr')))
const visibleItems = computed(() => items.value.filter(b =>
  (!district.value || b.district === district.value) &&
  (!activity.value || b.activityType?.label === activity.value) &&
  (!status.value || b.status === status.value)
))
const activeOnPage = computed(() => visibleItems.value.filter(b => b.status === 'active').length)
const inactiveOnPage = computed(() => visibleItems.value.filter(b => b.status !== 'active').length)
const first = computed(() => total.value ? (page.value - 1) * pageSize.value + 1 : 0)
const last = computed(() => Math.min(page.value * pageSize.value, total.value))
let timer
let requestCounter = 0

async function load() {
  const requestId = ++requestCounter
  loading.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({ page: String(page.value) })
    if (q.value.trim()) params.set('q', q.value.trim())
    const res = await api.get(`/api/businesses?${params}`)
    if (requestId !== requestCounter) return
    items.value = Array.isArray(res.items) ? res.items : []
    total.value = Number(res.total || 0)
    pageSize.value = Number(res.pageSize || 25)
  } catch {
    if (requestId === requestCounter) error.value = 'Impossible de charger la liste des commerces.'
  } finally {
    if (requestId === requestCounter) loading.value = false
  }
}

watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(() => { page.value = 1; load() }, 300)
})
watch(() => route.query.q, (value) => {
  const next = String(value || '')
  if (next !== q.value) q.value = next
})
function go(number) { page.value = Math.min(Math.max(1, number), pages.value); load() }
function resetFilters() { q.value = ''; district.value = ''; activity.value = ''; status.value = ''; page.value = 1; load() }
function safeCsvCell(value) {
  let s = String(value ?? '')
  if (/^[\s]*[=+@-]/.test(s)) s = "'" + s // Protection contre l'injection de formules CSV
  return '"' + s.replaceAll('"', '""') + '"'
}
function exportCurrentPage() {
  const headers = ['ID', 'Commerce', 'Adresse', 'Activité', 'Quartier', 'Gérant', 'Téléphone', 'Statut']
  const rows = visibleItems.value.map(b => [b.id, b.name, b.address, b.activityType?.label, b.district, b.manager?.fullName, b.manager?.phone, statusLabels[b.status] ?? b.status])
  const csv = '\uFEFF' + [headers, ...rows].map(row => row.map(safeCsvCell).join(';')).join('\r\n')
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
  const a = document.createElement('a')
  a.href = url
  a.download = `gc-attinguie-commerces-page-${page.value}.csv`
  a.click()
  URL.revokeObjectURL(url)
}
useGcDataRefresh(load)
onMounted(load)
</script>

<template>
  <section class="gc-page gc-business-page">
    <div class="gc-page-hero">
      <div class="gc-hero-copy">
        <span class="gc-hero-icon"><GcIcon name="shop" :size="26"/></span>
        <div><h1>Gestion des commerces</h1><p>Accueil <span>›</span> Commerces <span>›</span> Liste des commerces</p></div>
      </div>
      <span class="gc-hero-period"><GcIcon name="shield" :size="17"/> Répertoire communal</span>
    </div>

    <div class="gc-kpi-grid gc-business-kpis">
      <article class="gc-kpi gc-kpi-green"><GcIcon name="shop" :size="31"/><div><span>Total des commerces</span><strong>{{ total }}</strong><small>Dans la recherche</small></div></article>
      <article class="gc-kpi gc-kpi-green"><GcIcon name="check" :size="31"/><div><span>Actifs affichés</span><strong>{{ activeOnPage }}</strong><small>Sur la page courante</small></div></article>
      <article class="gc-kpi gc-kpi-red"><GcIcon name="alert" :size="31"/><div><span>Inactifs affichés</span><strong>{{ inactiveOnPage }}</strong><small>Sur la page courante</small></div></article>
    </div>

    <div class="gc-business-toolbar">
      <div class="gc-search-box"><GcIcon name="search" :size="19"/><label class="visually-hidden" for="gc-business-search">Rechercher nom ou quartier</label><input id="gc-business-search" v-model="q" type="search" placeholder="Rechercher un commerce…"/></div>
      <label class="gc-select"><GcIcon name="shop" :size="17"/><span class="visually-hidden">Quartier</span><select v-model="district"><option value="">Tous les quartiers</option><option v-for="d in districts" :key="d" :value="d">{{ d }}</option></select></label>
      <label class="gc-select"><GcIcon name="filter" :size="17"/><span class="visually-hidden">Activité</span><select v-model="activity"><option value="">Toutes les activités</option><option v-for="a in activities" :key="a" :value="a">{{ a }}</option></select></label>
      <label class="gc-select"><GcIcon name="filter" :size="17"/><span class="visually-hidden">Statut</span><select v-model="status"><option value="">Tous les statuts</option><option value="active">Actif</option><option value="suspended">Suspendu</option><option value="closed">Fermé</option></select></label>
      <RouterLink v-if="can('commerces.write')" to="/commerces/nouveau" class="btn gc-button-primary gc-action-strong"><GcIcon name="plus"/> Nouveau commerce</RouterLink>
      <button class="btn gc-button-outline" type="button" :disabled="!visibleItems.length" title="Exporte uniquement les commerces visibles sur cette page" @click="exportCurrentPage"><GcIcon name="export" :size="18"/> Exporter la page</button>
    </div>

    <p class="gc-filter-scope">Recherche par nom/quartier dans toute la base ; filtres activité, quartier et statut appliqués à la page affichée ({{ items.length }} commerces chargés). <button type="button" @click="resetFilters">Réinitialiser</button></p>
    <p v-if="error" role="alert" class="gc-alert-error">{{ error }}</p>

    <div class="gc-table-panel">
      <div class="table-responsive">
        <table class="table gc-data-table align-middle">
          <thead><tr><th scope="col">#</th><th scope="col">Commerce</th><th scope="col">Adresse</th><th scope="col">Activité</th><th scope="col">Quartier</th><th scope="col">Responsable</th><th scope="col">Téléphone</th><th scope="col">Statut</th><th scope="col">Actions</th></tr></thead>
          <tbody>
            <tr v-for="b in visibleItems" :key="b.id">
              <td class="gc-table-id">{{ b.id }}</td>
              <td class="gc-table-name">{{ b.name }}</td>
              <td>{{ b.address || '—' }}</td>
              <td><span class="gc-activity-chip"><GcIcon name="shop" :size="15"/> {{ b.activityType?.label || '—' }}</span></td>
              <td>{{ b.district || '—' }}</td>
              <td>{{ b.manager?.fullName || '—' }}</td>
              <td class="gc-phone">{{ b.manager?.phone || '—' }}</td>
              <td><span class="gc-status-chip" :class="b.status"><GcIcon :name="b.status === 'active' ? 'check' : 'alert'" :size="15"/> {{ statusLabels[b.status] ?? b.status }}</span></td>
              <td><div class="gc-row-actions">
                <RouterLink v-if="can('commerces.read')" :to="{ name: 'business-qr', params: { id: b.id } }" class="gc-icon-button" :aria-label="`QR Code de ${b.name}`" title="Voir le QR Code"><GcIcon name="qr" :size="19"/></RouterLink>
                <RouterLink v-if="can('commerces.write')" :to="{ name: 'business-edit', params: { id: b.id } }" class="gc-icon-button gc-edit" :aria-label="`Modifier ${b.name}`" title="Modifier"><GcIcon name="edit" :size="18"/></RouterLink>
              </div></td>
            </tr>
            <tr v-if="loading"><td colspan="9" class="gc-table-empty">Chargement des commerces…</td></tr>
            <tr v-else-if="!visibleItems.length"><td colspan="9" class="gc-table-empty">Aucun commerce ne correspond aux critères.</td></tr>
          </tbody>
        </table>
      </div>
      <div class="gc-table-footer"><span>Affichage de {{ first }} à {{ last }} sur {{ total }} commerces — {{ visibleItems.length }} résultat(s) visibles après filtres locaux</span>
        <div class="gc-pagination"><button class="gc-page-btn" :disabled="page <= 1 || loading" @click="go(1)" aria-label="Première page">«</button><button class="gc-page-btn" :disabled="page <= 1 || loading" @click="go(page - 1)" aria-label="Page précédente">‹</button><span class="gc-page-current">{{ page }}</span><button class="gc-page-btn" :disabled="page >= pages || loading" @click="go(page + 1)" aria-label="Page suivante">›</button><button class="gc-page-btn" :disabled="page >= pages || loading" @click="go(pages)" aria-label="Dernière page">»</button></div>
      </div>
    </div>
  </section>
</template>
