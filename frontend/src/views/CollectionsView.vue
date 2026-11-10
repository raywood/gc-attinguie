<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api'
import { formatMoney, currentAbidjanMonth, formatAbidjanDate } from '../utils/formatters'
import { useGcDataRefresh } from '../services/gc-data-refresh'

const period = ref(currentAbidjanMonth())
const state = ref('all')
const search = ref('')
const page = ref(1)
const payload = ref(null)
const loading = ref(false)
const error = ref('')
const money = formatMoney
const stateText = (v) => ({ overdue: 'Échu', upcoming: 'Non échu', undated: 'Sans échéance' }[v] || v)
const labelDate = formatAbidjanDate

async function load() {
  if (!/^20\d{2}-(0[1-9]|1[0-2])$/.test(period.value)) {
    error.value = 'Sélectionnez une période valide.'
    return
  }
  if (search.value.length > 80) {
    error.value = 'Recherche limitée à 80 caractères.'
    return
  }
  loading.value = true
  error.value = ''
  try {
    const args = new URLSearchParams({ period: period.value, state: state.value, search: search.value.trim(), page: String(page.value) })
    payload.value = await api.get(`/api/admin/collections/outstanding?${args}`)
  } catch (e) {
    payload.value = null
    error.value = e.message || 'Impossible de récupérer les soldes.'
  } finally {
    loading.value = false
  }
}
function applyFilters() { page.value = 1; load() }
function switchPage(next) { page.value = next; load() }
useGcDataRefresh(load)
onMounted(load)
</script>

<template>
  <section class="collections-view">
    <div class="row-head">
      <div>
        <h1>Suivi des impayés et du recouvrement</h1>
        <p class="muted small">Commune d’Attinguié · Factures en FCFA · Consultation administrative en lecture seule</p>
      </div>
      <RouterLink class="btn ghost" to="/tableau-de-bord">Tableau de bord</RouterLink>
    </div>

    <form
      class="card collections-filters"
      @submit.prevent="applyFilters"
    >
      <h3 class="collections-filters-title">
        Rechercher des encaissements
      </h3>

      <div class="collections-filters-grid">
        <!-- Colonne 1 : Période -->
        <div class="collections-filter-field">
          <label for="filter-period">
            Période facturée
          </label>
          <input
            id="filter-period"
            v-model="period"
            type="month"
            min="2000-01"
            max="2099-12"
            required
          />
        </div>

        <!-- Colonne 2 : Situation -->
        <div class="collections-filter-field">
          <label for="filter-state">
            Situation
          </label>
          <select id="filter-state" v-model="state">
            <option value="all">Tous les soldes</option>
            <option value="overdue">Échus uniquement</option>
            <option value="upcoming">Non échus</option>
            <option value="undated">Sans échéance</option>
          </select>
        </div>

        <!-- Ligne 2 : Recherche -->
        <div class="collections-filter-field">
          <label for="filter-search">
            Recherche
          </label>
          <input
            id="filter-search"
            v-model="search"
            type="search"
            maxlength="80"
            placeholder="Commerce, activité ou facture"
          />
        </div>
      </div>

      <div class="collections-filters-actions">
        <button
          type="submit"
          class="btn collections-search-btn"
          :disabled="loading"
        >
          {{ loading ? 'Chargement…' : 'Rechercher' }}
        </button>
      </div>
    </form>



    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="loading && !payload" class="muted" role="status">Chargement des soldes…</p>
    <template v-if="payload">
      <p class="muted small">État des soldes au {{ labelDate(payload.asOf) }} (heure de Côte d’Ivoire). Indicateurs calculés pour toutes les factures émises de la période, indépendamment des filtres ci-dessus.</p>
      <div class="collections-kpis">
        <article class="card">
          <span class="muted small">Reste à recouvrer</span>
          <strong class="collections-value">{{ money(payload.summary.remaining) }}</strong>
          <span class="muted small">{{ payload.summary.outstandingCount }} facture(s) avec solde</span>
        </article>
        <article class="card">
          <span class="muted small">Impayés échus</span>
          <strong class="collections-value">{{ money(payload.summary.overdueAmount) }}</strong>
          <span class="muted small">{{ payload.summary.overdueCount }} facture(s) en retard</span>
        </article>
        <article class="card">
          <span class="muted small">À échoir</span>
          <strong class="collections-value">{{ money(payload.summary.upcomingAmount) }}</strong>
          <span class="muted small">{{ payload.summary.upcomingCount }} facture(s) non échues</span>
        </article>
        <article class="card">
          <span class="muted small">Échéance non renseignée</span>
          <strong class="collections-value">{{ payload.summary.undatedCount }}</strong>
          <span class="muted small">Facture(s) nécessitant une vérification</span>
        </article>
      </div>

      <div class="card">
        <div class="collections-table-head">
          <h2>Factures avec un solde à payer</h2>
          <span class="muted small">{{ payload.pagination.total }} résultat(s) après filtrage</span>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr>
              <th>Facture</th><th>Commerce / activité</th><th>Échéance</th>
              <th>Facturé</th><th>Encaissé</th><th>Solde</th><th>Situation</th>
            </tr></thead>
            <tbody>
              <tr v-for="item in payload.items" :key="item.invoiceId">
                <td><strong>{{ item.invoiceNumber }}</strong></td>
                <td>{{ item.business }}<div class="muted small">{{ item.activity }} · {{ item.district }}</div></td>
                <td>{{ labelDate(item.dueDate) }}</td>
                <td>{{ money(item.total) }}</td>
                <td>{{ money(item.paid) }}</td>
                <td><strong>{{ money(item.balance) }}</strong></td>
                <td>
                  <span :class="['collections-state', item.state === 'overdue' ? 'collections-state-late' : '']">{{ stateText(item.state) }}</span>
                  <span v-if="item.daysOverdue > 0" class="muted small collections-days">{{ item.daysOverdue }} jour(s) de retard</span>
                </td>
              </tr>
              <tr v-if="!payload.items.length"><td colspan="7" class="muted">Aucune facture avec solde pour cette recherche.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="collections-pagination">
          <button class="btn ghost" :disabled="loading || page <= 1" @click="switchPage(page - 1)">Précédent</button>
          <span class="muted small">Page {{ payload.pagination.page }} / {{ payload.pagination.pages }}</span>
          <button class="btn ghost" :disabled="loading || page >= payload.pagination.pages" @click="switchPage(page + 1)">Suivant</button>
        </div>
      </div>
      <p class="muted small">Un reçu annulé reste distinct du paiement enregistré. Aucune relance ni modification comptable n'est effectuée depuis cet écran.</p>
    </template>
  </section>
</template>

<style scoped>
.collections-view { display:grid; gap:1.1rem; }
.collections-view h2 { margin:0; font-size:1.12rem; }
/* .collections-filters { display:flex; flex-wrap:wrap; align-items:end; gap:.9rem; }
.collections-filters label { flex:1 1 185px; }
.collections-filters button { min-height:40px; } */
.collections-kpis { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:.85rem; }
.collections-kpis article { display:grid; gap:.5rem; min-height:130px; }
.collections-value { font-size:clamp(1.1rem, 1.8vw, 1.55rem); overflow-wrap:anywhere; font-variant-numeric:tabular-nums; }
.collections-table-head { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:.5rem; margin-bottom:.8rem; }
.collections-state { display:inline-block; border:1px solid var(--border); border-radius:1.5rem; padding:.12rem .6rem; white-space:nowrap; }
.collections-state-late { color:var(--danger); border-color:var(--danger); }
.collections-days { display:block; margin-top:.3rem; }
.collections-pagination { display:flex; gap:1rem; align-items:center; justify-content:flex-end; margin-top:1rem; }
@media (max-width:1000px) { .collections-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:600px) { .collections-kpis { grid-template-columns:1fr; } }

/* =========================================
   GC-ATTINGUIÉ
   Filtres des encaissements
   Charte ivoirienne
   ========================================= */

.collections-filters.card {
  display: block !important;
  width: 100%;
  max-width: none;
  height: auto !important;
  min-height: 0 !important;
  margin: 0;
  padding: 16px;
  box-sizing: border-box;

  background: #ffffff;
  border: 1px solid #d8e5dc;
  border-radius: 14px;
  box-shadow: 0 5px 14px rgba(0, 65, 35, 0.05);
}

/* Titre vert identique à la grille tarifaire */

.collections-filters-title {
  margin: 0 0 16px;
  color: #075d39;
  font-size: 18px;
  font-weight: 700;
  line-height: 1.4;
}

/* Champs répartis en deux colonnes */

.collections-filters-grid {
  display: grid !important;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
  width: 100%;
  align-items: start;
  justify-items: stretch;
}

/* Libellés au-dessus des champs */

.collections-filters .collections-filter-field {
  display: flex !important;
  flex-direction: column !important;
  align-items: stretch !important;
  width: 100%;
  min-width: 0;
  margin: 0 !important;
  padding: 0;
  gap: 6px;
}

.collections-filters .collections-filter-field label {
  display: block !important;
  position: static !important;
  width: 100%;
  margin: 0 !important;
  padding: 0;
  color: #194e37;
  font-size: 14px;
  font-weight: 700;
  text-align: left;
}

/* Style des champs */

.collections-filters .collections-filter-field input,
.collections-filters .collections-filter-field select {
  display: block !important;
  position: static !important;
  width: 100% !important;
  max-width: none !important;
  height: 42px !important;
  min-height: 42px !important;
  margin: 0 !important;
  padding: 9px 12px;
  box-sizing: border-box;

  color: #14283c;
  background: #ffffff;
  border: 1px solid #d6e3da;
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
}

.collections-filters input:focus,
.collections-filters select:focus {
  outline: none;
  border-color: #008751;
  box-shadow: 0 0 0 3px rgba(0, 135, 81, 0.12);
}

/* Bouton orange aligné à gauche */

.collections-filters-actions {
  display: flex !important;
  justify-content: flex-start !important;
  align-items: center;
  width: 100%;
  margin-top: 14px;
}

.collections-filters .collections-search-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: auto !important;
  min-height: 42px;
  padding: 10px 20px;
  margin: 0 !important;

  background: #ed7100;
  border: 1px solid #ed7100;
  border-radius: 10px;
  color: #ffffff;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 4px 10px rgba(237, 113, 0, 0.17);
}

.collections-filters .collections-search-btn:hover {
  background: #d56400;
  border-color: #d56400;
}

.collections-filters .collections-search-btn:disabled {
  opacity: 0.65;
  cursor: wait;
}

/* Responsive tablette et smartphone */

@media (max-width: 768px) {
  .collections-filters-grid {
    grid-template-columns: 1fr;
    gap: 14px;
  }

  .collections-filters.card {
    padding: 14px;
  }
}

</style>
