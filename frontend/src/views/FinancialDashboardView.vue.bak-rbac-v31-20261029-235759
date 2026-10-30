<script setup>
import FinanceExports from '../components/FinanceExports.vue'
import { computed, onMounted, ref } from 'vue'
import { api } from '../api'

function abidjanMonth() {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Africa/Abidjan', year: 'numeric', month: '2-digit',
  }).formatToParts(new Date())
  return `${parts.find(x => x.type === 'year').value}-${parts.find(x => x.type === 'month').value}`
}

const period = ref(abidjanMonth())
const data = ref(null)
const loading = ref(false)
const error = ref('')
const moneyFormat = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
const money = (amount) => `${moneyFormat.format(Number(amount || 0))} FCFA`
const monthText = (value) => {
  if (!/^\d{4}-\d{2}$/.test(value ?? '')) return value || ''
  const [year, month] = value.split('-').map(Number)
  return new Intl.DateTimeFormat('fr-FR', { month: 'short', year: 'numeric', timeZone: 'UTC' })
    .format(new Date(Date.UTC(year, month - 1, 1)))
}
const trendMax = computed(() => Math.max(1, ...(data.value?.cashTrend ?? []).map(p => Number(p.amount) || 0)))
const activityMax = computed(() => Math.max(1, ...(data.value?.byActivity ?? []).map(p => Number(p.billed) || 0)))
const barWidth = (val, max) => `${Math.max(0, Math.min(100, 100 * Number(val || 0) / max))}%`

async function load() {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(period.value)) {
    error.value = 'Sélectionnez un mois valide.'
    return
  }
  loading.value = true
  error.value = ''
  try {
    data.value = await api.get(`/api/admin/dashboard/financial?period=${encodeURIComponent(period.value)}`)
  } catch (e) {
    error.value = e.message || 'Impossible de charger les indicateurs financiers.'
    data.value = null
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="finance-dashboard">
    <div class="row-head">
      <div>
        <h1>Tableau de bord financier municipal</h1>
        <p class="muted small" style="margin: .4rem 0 0">Gestion des commerces · Commune d’Attinguié · FCFA</p>
      </div>
      <RouterLink to="/factures" class="btn ghost">Voir les factures</RouterLink>
    </div>

    <div class="card finance-toolbar">
      <label for="finance-period">Période des factures
        <input id="finance-period" v-model="period" type="month" min="2000-01" max="2099-12" />
      </label>
      <button class="btn" :disabled="loading" @click="load">{{ loading ? 'Chargement…' : 'Actualiser' }}</button>
      <span v-if="data" class="muted small">Échéances évaluées au {{ new Date(`${data.asOf}T12:00:00Z`).toLocaleDateString('fr-FR', { timeZone: 'Africa/Abidjan' }) }}</span>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-else-if="loading && !data" class="muted" role="status">Chargement du tableau de bord…</p>

    <template v-if="data">
      <p class="finance-scope-note">
        <strong>Lecture des indicateurs :</strong> les encaissements du mois sont calculés à la date de paiement.
        Les montants facturés et les soldes concernent les factures de {{ monthText(data.period) }}.
        L’annulation d’un reçu ne déduit pas son paiement.
      </p>

      <FinanceExports :period="data.period" />

      <div class="finance-kpis">
        <article class="card finance-kpi">
          <span class="muted small">Recettes encaissées dans le mois</span>
          <strong class="finance-number">{{ money(data.summary.cashCollected) }}</strong>
          <span class="muted small">{{ data.summary.paymentCount }} paiement(s)</span>
        </article>
        <article class="card finance-kpi">
          <span class="muted small">Facturé pour la période</span>
          <strong class="finance-number">{{ money(data.summary.billed) }}</strong>
          <span class="muted small">{{ data.summary.invoiceCount }} facture(s) émises ou payées</span>
        </article>
        <article class="card finance-kpi">
          <span class="muted small">Reste à recouvrer</span>
          <strong class="finance-number">{{ money(data.summary.remaining) }}</strong>
          <span class="muted small">{{ data.summary.outstandingInvoiceCount }} facture(s) avec solde</span>
        </article>
        <article class="card finance-kpi finance-kpi-late">
          <span class="muted small">Impayés échus</span>
          <strong class="finance-number">{{ money(data.summary.overdue) }}</strong>
          <span class="muted small">{{ data.summary.overdueInvoiceCount }} facture(s) après échéance</span>
        </article>
      </div>

      <div class="card finance-info">
        <div>
          <strong>{{ money(data.summary.collectedOnPeriodInvoices) }}</strong>
          <span class="muted small"> déjà encaissés sur les factures de {{ monthText(data.period) }}, toutes dates de paiement confondues.</span>
        </div>
        <span class="muted small">Brouillons exclus · Solde jamais négatif · Devise XOF</span>
      </div>

      <div class="finance-panels">
        <section class="card">
          <h2>Encaissements des six derniers mois</h2>
          <p class="muted small">Sommes reçues selon la date du paiement · FCFA</p>
          <div v-if="data.cashTrend?.length" class="finance-chart" role="img" aria-label="Histogramme des encaissements mensuels">
            <div v-for="row in data.cashTrend" :key="row.month" class="finance-chart-col">
              <span class="finance-bar-value" :title="money(row.amount)">{{ money(row.amount) }}</span>
              <div class="finance-bar-track">
                <div class="finance-bar" :style="{ height: barWidth(row.amount, trendMax) }"></div>
              </div>
              <span class="finance-bar-label">{{ monthText(row.month) }}</span>
            </div>
          </div>
          <p v-else class="muted">Aucune donnée disponible.</p>
        </section>

        <section class="card">
          <h2>Répartition par activité</h2>
          <p class="muted small">Factures de {{ monthText(data.period) }}, du plus gros montant au plus faible</p>
          <template v-if="data.byActivity?.length">
            <div v-for="activity in data.byActivity" :key="activity.code" class="finance-activity">
              <div class="finance-activity-top">
                <span>{{ activity.label }}</span>
                <strong>{{ money(activity.billed) }}</strong>
              </div>
              <div class="finance-activity-track"><div :style="{ width: barWidth(activity.billed, activityMax) }"></div></div>
              <span class="muted small">{{ activity.invoiceCount }} facture(s) · Solde : {{ money(activity.remaining) }}</span>
            </div>
          </template>
          <p v-else class="muted">Aucune facture émise pour cette période.</p>
        </section>
      </div>

      <section class="card">
        <h2>Détail financier par activité commerciale</h2>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Activité</th>
                <th>Factures</th>
                <th>Facturé</th>
                <th>Encaissé sur ces factures</th>
                <th>Reste à payer</th>
                <th>Échu impayé</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="activity in data.byActivity" :key="activity.code">
                <td>{{ activity.label }}</td>
                <td>{{ activity.invoiceCount }}</td>
                <td>{{ money(activity.billed) }}</td>
                <td>{{ money(activity.collected) }}</td>
                <td><strong>{{ money(activity.remaining) }}</strong></td>
                <td>{{ money(activity.overdue) }}</td>
              </tr>
              <tr v-if="!data.byActivity?.length"><td colspan="6" class="muted">Aucune facture émise sur la période.</td></tr>
            </tbody>
          </table>
        </div>
        <p class="muted small" style="margin-bottom:0">Un reçu annulé reste dans l'historique comptable : le remboursement ou la correction d'encaissement fera l'objet d'un module distinct.</p>
      </section>
    </template>
  </section>
</template>

<style scoped>
.finance-dashboard { display: grid; gap: 1.15rem; }
.finance-toolbar { display: flex; align-items: end; flex-wrap: wrap; gap: .9rem; }
.finance-toolbar label { min-width: 190px; }
.finance-toolbar .btn { height: 39px; }
.finance-scope-note { padding: .85rem 1rem; border-left: 3px solid var(--primary); margin:0; background: var(--card); border-radius: 6px; font-size: .9rem; }
.finance-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .85rem; }
.finance-kpi { display: grid; gap: .55rem; min-height: 142px; }
.finance-number { font-size: clamp(1.1rem, 2vw, 1.7rem); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.finance-kpi-late { border-color: var(--danger); }
.finance-info { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; }
.finance-panels { display:grid; grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); gap: 1rem; }
.finance-panels h2, .finance-dashboard h2 { font-size: 1.08rem; margin: 0 0 .35rem; }
.finance-panels .card { min-width: 0; }
.finance-chart { display:flex; height:215px; gap:.6rem; align-items:stretch; padding-top:.6rem; }
.finance-chart-col { min-width:0; display:flex; flex-direction:column; flex:1; align-items:center; gap:.4rem; }
.finance-bar-value { font-size:.7rem; overflow-wrap:anywhere; text-align:center; min-height:29px; }
.finance-bar-track { flex:1; display:flex; align-items:end; width:70%; max-width:58px; min-height:15px; border-radius:6px 6px 0 0; background:var(--bg); }
.finance-bar { width:100%; border-radius:6px 6px 0 0; background:var(--primary); min-height:0; }
.finance-bar-label { font-size:.69rem; white-space:nowrap; text-align:center; }
.finance-activity { display:grid; gap:.35rem; margin:1rem 0; }
.finance-activity-top { display:flex; justify-content:space-between; gap:.75rem; font-size:.9rem; }
.finance-activity-track { height:9px; border-radius:8px; background:var(--bg); overflow:hidden; }
.finance-activity-track > div { background:var(--primary); height:100%; border-radius:8px; }
@media (max-width:950px) { .finance-kpis { grid-template-columns:repeat(2, minmax(0,1fr)); } .finance-panels { grid-template-columns: 1fr; } }
@media (max-width:550px) { .finance-kpis { grid-template-columns:1fr; } .finance-chart { gap:.2rem; } .finance-bar-label { font-size:.6rem; } }
</style>
