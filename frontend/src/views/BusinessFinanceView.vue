<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api'
import { formatMoney } from '../utils/formatters'
const route = useRoute()
const result = ref(null)
const error = ref('')
const loading = ref(true)
const money = formatMoney
onMounted(async () => {
  try { result.value = await api.get(`/api/businesses/${encodeURIComponent(route.params.id)}/financial-summary`) }
  catch (e) { error.value = e.status === 403 ? 'Vous n’êtes pas habilité à consulter les finances de ce commerce.' : (e.message || 'Chargement impossible.') }
  finally { loading.value = false }
})
</script>
<template>
  <div class="row-head"><h1>Situation financière du commerce</h1><RouterLink to="/commerces" class="btn ghost">Retour aux commerces</RouterLink></div>
  <p v-if="loading">Chargement…</p>
  <p v-if="error" class="error" role="alert">{{ error }}</p>
  <div v-if="result" class="card">
    <p><strong>{{ result.businessName }}</strong> — {{ result.reference }}</p>
    <p>Total facturé : <strong>{{ money(result.summary.total_billed) }}</strong></p>
    <p>Total encaissé : <strong>{{ money(result.summary.total_paid) }}</strong></p>
    <p>Solde exigible restant : <strong>{{ money(result.summary.outstanding) }}</strong></p>
    <p class="muted small">Les brouillons ne sont pas inclus dans le solde exigible. Total encaissé selon les paiements enregistrés. Les 20 factures les plus récentes sont affichées.</p>
    <div style="overflow-x:auto"><table style="width:100%"><thead><tr><th>Facture</th><th>Période</th><th>Statut</th><th>Total</th><th>Payé</th><th>Solde exigible</th></tr></thead>
      <tbody><tr v-for="row in result.invoices" :key="row.number"><td>{{ row.number }}</td><td>{{ row.period }}</td><td>{{ row.status }}</td><td>{{ money(row.total) }}</td><td>{{ money(row.paid) }}</td><td>{{ money(row.outstanding) }}</td></tr></tbody></table></div>
    <p class="muted small">Consultation réservée au personnel habilité et journalisée.</p>
  </div>
</template>
