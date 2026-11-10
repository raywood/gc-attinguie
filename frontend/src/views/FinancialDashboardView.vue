<script setup>
import { can } from '../security/abilities'
import FinanceExports from '../components/FinanceExports.vue'
import GcIcon from '../components/GcIcon.vue'
import GcStatusBadge from '../components/GcStatusBadge.vue'
import { businessStatusLabel } from '../utils/business-status'
import { computed, onMounted, ref } from 'vue'
import { api } from '../api'
import { formatMoney, formatCompactAmount, currentAbidjanMonth, formatMonthFr } from '../utils/formatters'
import { useGcDataRefresh } from '../services/gc-data-refresh'

const period = ref(currentAbidjanMonth())
const data = ref(null)
const recent = ref([])
const loading = ref(false)
const error = ref('')
const money = formatMoney
const monthText = formatMonthFr
const trendMax = computed(() => Math.max(1, ...(data.value?.cashTrend ?? []).map(p => Number(p.amount) || 0)))
const moneyCompact = formatCompactAmount
const coloredActivities = computed(() => {
  const colors = ['#078a4b', '#fb7200', '#fbb720', '#116b80', '#7b66c5', '#6b9c74']
  const rows = (data.value?.byActivity ?? []).map(row => ({ ...row, positive: Math.max(0, Number(row.billed) || 0) }))
  const sorted = [...rows].sort((a, b) => b.positive - a.positive)
  const head = sorted.slice(0, 5)
  const tail = sorted.slice(5)
  if (tail.length) head.push({ code: 'other', label: 'Autres activités', positive: tail.reduce((sum, x) => sum + x.positive, 0) })
  return head.map((x, i) => ({ ...x, color: colors[i] }))
})
const pieTotal = computed(() => coloredActivities.value.reduce((sum, x) => sum + x.positive, 0))
const pieBackground = computed(() => {
  if (pieTotal.value <= 0) return '#e5eee9'
  let start = 0
  const parts = coloredActivities.value.map(x => {
    const end = start + x.positive / pieTotal.value * 100
    const part = `${x.color} ${start}% ${end}%`
    start = end
    return part
  })
  return `conic-gradient(${parts.join(', ')})`
})

async function load() {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(period.value)) { error.value = 'Sélectionnez un mois valide.'; return }
  loading.value = true
  error.value = ''
  try {
    data.value = await api.get(`/api/admin/dashboard/financial?period=${encodeURIComponent(period.value)}`)
  } catch (e) {
    error.value = e.message || 'Impossible de charger les indicateurs financiers.'
    data.value = null
  } finally { loading.value = false }
}
async function loadRecent() {
  if (!can('commerces.read')) return
  try {
    const res = await api.get('/api/businesses?page=1')
    recent.value = (res.items || []).slice(0, 4)
  } catch { recent.value = [] }
}
useGcDataRefresh(() => { load(); loadRecent() })
onMounted(() => { load(); loadRecent() })
</script>

<template>
  <section class="gc-page gc-dashboard">
    <div class="gc-page-hero gc-dashboard-hero">
      <div class="gc-hero-copy"><span class="gc-hero-icon"><GcIcon name="home" :size="26"/></span><div><h1>Bienvenue sur GC-ATTINGUIÉ</h1><p>La plateforme de gestion des commerces de la Commune d’Attinguié.</p></div></div>
      <label class="gc-hero-period" for="gc-dashboard-period"><GcIcon name="calendar" :size="17"/><span>Période :</span><input id="gc-dashboard-period" v-model="period" type="month" min="2000-01" max="2099-12"/></label>
    </div>
    <div class="gc-dashboard-actions">
      <span v-if="data" class="gc-data-asof">État au {{ new Date(`${data.asOf}T12:00:00Z`).toLocaleDateString('fr-FR', { timeZone: 'Africa/Abidjan' }) }}</span>
      <button class="btn gc-button-outline" type="button" :disabled="loading" @click="load"><GcIcon name="refresh" :size="16"/> {{ loading ? 'Actualisation…' : 'Actualiser les données' }}</button>
    </div>
    <p v-if="error" role="alert" class="gc-alert-error">{{ error }}</p>
    <div v-if="loading && !data" class="gc-panel gc-empty-state" role="status">Chargement du tableau de bord…</div>

    <template v-if="data">
      <div class="gc-kpi-grid gc-dashboard-kpis">
        <article class="gc-kpi gc-kpi-green"><GcIcon name="wallet" :size="30"/><div><span>Recettes encaissées</span><strong>{{ money(data.summary.cashCollected) }}</strong><small>{{ data.summary.paymentCount }} paiement(s) ce mois</small></div></article>
        <article class="gc-kpi gc-kpi-orange"><GcIcon name="file" :size="30"/><div><span>Factures de la période</span><strong>{{ data.summary.invoiceCount }}</strong><small>{{ money(data.summary.billed) }} facturés</small></div></article>
        <article class="gc-kpi gc-kpi-green"><GcIcon name="coins" :size="30"/><div><span>Reste à recouvrer</span><strong>{{ money(data.summary.remaining) }}</strong><small>{{ data.summary.outstandingInvoiceCount }} facture(s) avec solde</small></div></article>
        <article class="gc-kpi gc-kpi-red"><GcIcon name="alert" :size="30"/><div><span>Impayés échus</span><strong>{{ money(data.summary.overdue) }}</strong><small>{{ data.summary.overdueInvoiceCount }} facture(s) échue(s)</small></div></article>
      </div>

      <div class="gc-chart-grid">
        <section class="gc-panel gc-chart-card">
          <div class="gc-panel-heading"><GcIcon name="chart" :size="23"/><h2>Encaissements mensuels</h2></div>
          <p class="gc-panel-subtitle">Montants réellement encaissés selon la date du paiement · FCFA</p>
          <div v-if="data.cashTrend?.length" class="gc-chart" role="img" :aria-label="'Encaissements mensuels : ' + data.cashTrend.map(row => monthText(row.month) + ', ' + money(row.amount)).join(' ; ')">
            <div v-for="(row, index) in data.cashTrend" :key="row.month" class="gc-chart-col">
              <span class="gc-chart-value" :title="money(row.amount)">{{ moneyCompact(row.amount) }}</span>
              <div class="gc-chart-track"><div class="gc-chart-bar" :class="{ 'is-last': index === data.cashTrend.length - 1 }" :style="{ height: `${Math.max(0, Math.min(100, Number(row.amount || 0) / trendMax * 100))}%` }"></div></div>
              <span class="gc-chart-label">{{ monthText(row.month).split(' ')[0].slice(0, 4) }}</span>
            </div>
          </div>
          <p v-else class="gc-empty-chart">Aucun encaissement pour la période étudiée.</p>
        </section>

        <section class="gc-panel gc-chart-card">
          <div class="gc-panel-heading"><GcIcon name="chart" :size="23"/><h2>Répartition par activité</h2></div>
          <p class="gc-panel-subtitle">Montants facturés pour {{ monthText(data.period) }}</p>
          <div v-if="pieTotal > 0" class="gc-pie-layout">
            <div class="gc-donut" :style="{ background: pieBackground }" role="img" :aria-label="'Répartition des montants facturés : ' + coloredActivities.map(a => a.label + ', ' + money(a.positive)).join(' ; ')"><div><strong>{{ coloredActivities.length }}</strong><small>activités</small></div></div>
            <div class="gc-pie-legend"><div v-for="a in coloredActivities" :key="a.code" class="gc-legend-row"><i :style="{ background: a.color }"></i><span>{{ a.label }}</span><strong>{{ Math.round(a.positive / pieTotal * 100) }} %</strong></div></div>
          </div>
          <p v-else class="gc-empty-chart">Aucune facture émise pour cette période.</p>
        </section>
      </div>

      <div class="gc-recent-grid">
        <section class="gc-panel gc-recent-card">
          <div class="gc-panel-heading"><GcIcon name="shop" :size="23"/><h2>Commerces récents</h2></div>
          <div class="table-responsive"><table class="table gc-data-table align-middle"><thead><tr><th>#</th><th>Commerce</th><th>Activité</th><th>Quartier</th><th>Responsable</th><th>Statut</th></tr></thead><tbody>
            <tr v-for="b in recent" :key="b.id"><td>{{ b.id }}</td><td class="gc-table-name">{{ b.name }}</td><td><span class="gc-activity-chip">{{ b.activityType?.label || '—' }}</span></td><td>{{ b.district || '—' }}</td><td>{{ b.manager?.fullName || '—' }}</td><td><GcStatusBadge :status="b.status" :label="businessStatusLabel(b.status)" /></td></tr>
            <tr v-if="!recent.length"><td colspan="6" class="gc-table-empty">Aucun commerce récent accessible.</td></tr>
          </tbody></table></div>
        </section>
        <section class="gc-panel gc-quick-card">
          <div class="gc-panel-heading"><GcIcon name="refresh" :size="23"/><h2>Actions rapides</h2></div>
          <RouterLink v-if="can('commerces.write')" to="/commerces/nouveau" class="btn gc-button-primary"><GcIcon name="plus"/> Nouveau commerce</RouterLink>
          <RouterLink v-if="can('factures.read')" to="/factures" class="btn gc-button-outline"><GcIcon name="file"/> Voir les factures</RouterLink>
          <RouterLink v-if="can('recouvrement.read')" to="/recouvrement" class="btn gc-button-outline"><GcIcon name="wallet"/> Suivre les encaissements</RouterLink>
        </section>
      </div>
      <p class="gc-finance-note"><strong>Indicateurs :</strong> les encaissements sont comptabilisés à la date du paiement. Les montants facturés et les soldes concernent les factures de {{ monthText(data.period) }}. Les brouillons sont exclus.</p>
      <FinanceExports v-if="can('finance.export')" :period="data.period" />
      <section class="gc-panel gc-finance-detail">
        <div class="gc-panel-heading"><GcIcon name="file" :size="23"/><h2>Détail financier par activité</h2></div>
        <div class="table-responsive"><table class="table gc-data-table align-middle"><thead><tr><th>Activité</th><th>Factures</th><th>Facturé</th><th>Encaissé sur ces factures</th><th>Reste à payer</th><th>Échu impayé</th></tr></thead><tbody>
          <tr v-for="a in data.byActivity" :key="a.code"><td>{{ a.label }}</td><td>{{ a.invoiceCount }}</td><td>{{ money(a.billed) }}</td><td>{{ money(a.collected) }}</td><td><strong>{{ money(a.remaining) }}</strong></td><td>{{ money(a.overdue) }}</td></tr>
          <tr v-if="!data.byActivity?.length"><td colspan="6" class="gc-table-empty">Aucune donnée pour la période.</td></tr>
        </tbody></table></div>
        <p class="gc-panel-footnote">Déjà encaissé sur les factures de cette période, toutes dates confondues : <strong>{{ money(data.summary.collectedOnPeriodInvoices) }}</strong>. Un reçu annulé ne supprime pas le paiement associé.</p>
      </section>
    </template>
  </section>
</template>
