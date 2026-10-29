<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '../api'

const filters = reactive({ from: '', to: '', business: '', receipt: '', actor: '' })
const items = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const error = ref('')
const expandedId = ref(null)
const pages = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)))
const moneyFmt = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 })

function money(amount, currency) {
  return `${moneyFmt.format(Number(amount || 0))} ${currency === 'XOF' ? 'FCFA' : currency}`
}
function dateInAbidjan(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString('fr-FR', {
    timeZone: 'Africa/Abidjan', day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

async function load() {
  loading.value = true
  error.value = ''
  expandedId.value = null
  try {
    const params = new URLSearchParams({ page: String(page.value) })
    for (const [key, value] of Object.entries(filters)) {
      if (value.trim()) params.set(key, value.trim())
    }
    const data = await api.get(`/api/admin/receipts/cancellations?${params}`)
    items.value = data.items ?? []
    total.value = Number(data.total ?? 0)
    pageSize.value = Number(data.pageSize ?? 20)
  } catch (e) {
    error.value = e.message || 'Impossible de charger les annulations.'
  } finally {
    loading.value = false
  }
}

function search() {
  if (filters.from && filters.to && filters.from > filters.to) {
    error.value = 'La date de début doit précéder la date de fin.'
    return
  }
  page.value = 1
  load()
}
function reset() {
  Object.assign(filters, { from: '', to: '', business: '', receipt: '', actor: '' })
  search()
}
function changePage(next) {
  if (next < 1 || next > pages.value || loading.value) return
  page.value = next
  load()
}
onMounted(load)
</script>

<template>
  <div class="row-head">
    <h1>Historique administratif des annulations</h1>
    <RouterLink to="/factures" class="btn ghost">Retour aux factures</RouterLink>
  </div>
  <p class="muted small">Lecture seule · Accès réservé aux administrateurs. Heures affichées en heure de Côte d’Ivoire (UTC).</p>

  <form class="card" style="margin-bottom:1rem" @submit.prevent="search">
    <div class="actions" style="margin-top:0">
      <label>Du <input v-model="filters.from" type="date" /></label>
      <label>Au <input v-model="filters.to" type="date" /></label>
      <label>Commerce <input v-model="filters.business" maxlength="120" placeholder="Nom du commerce" /></label>
      <label>Numéro de reçu <input v-model="filters.receipt" maxlength="120" placeholder="REC-2026-..." /></label>
      <label>Administrateur <input v-model="filters.actor" maxlength="120" placeholder="Identifiant / email" /></label>
    </div>
    <div class="actions">
      <button class="btn" type="submit" :disabled="loading">{{ loading ? 'Chargement…' : 'Rechercher' }}</button>
      <button class="btn ghost" type="button" :disabled="loading" @click="reset">Effacer les filtres</button>
      <span class="muted">{{ total }} annulation(s)</span>
    </div>
  </form>

  <p v-if="error" class="error" role="alert">{{ error }}</p>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Reçu</th><th>Commerce</th><th>Montant</th><th>Date d’annulation</th>
          <th>Administrateur</th><th>État</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <template v-for="item in items" :key="item.id">
          <tr>
            <td><strong>{{ item.number }}</strong><div class="muted small">{{ item.invoiceNumber }}</div></td>
            <td>{{ item.businessName }}</td>
            <td>{{ money(item.amount, item.currency) }}</td>
            <td>{{ dateInAbidjan(item.cancelledAt) }}</td>
            <td>{{ item.cancelledBy }}</td>
            <td><span class="badge" style="color:var(--danger);border-color:var(--danger)">Annulé</span></td>
            <td>
              <div class="actions" style="margin-top:0;gap:.4rem">
                <button type="button" class="btn ghost" @click="expandedId = expandedId === item.id ? null : item.id">
                  {{ expandedId === item.id ? 'Masquer' : 'Détails' }}
                </button>
                <a class="btn ghost" :href="`/api/payments/${item.paymentId}/receipt/administrative.pdf`"
                   target="_blank" rel="noopener noreferrer">PDF administratif</a>
              </div>
            </td>
          </tr>
          <tr v-if="expandedId === item.id">
            <td colspan="7">
              <strong>Motif administratif :</strong> {{ item.reason }}
              <div class="muted small">Reçu n° {{ item.number }} · Paiement n° {{ item.paymentId }} · Facture {{ item.invoiceNumber }}</div>
            </td>
          </tr>
        </template>
        <tr v-if="!loading && !items.length"><td colspan="7" class="center muted">Aucune annulation pour les critères choisis.</td></tr>
      </tbody>
    </table>
  </div>
  <div class="pager" v-if="pages > 1">
    <button class="btn ghost" type="button" :disabled="loading || page <= 1" @click="changePage(page - 1)">Précédent</button>
    <span>Page {{ page }} / {{ pages }}</span>
    <button class="btn ghost" type="button" :disabled="loading || page >= pages" @click="changePage(page + 1)">Suivant</button>
  </div>
</template>
