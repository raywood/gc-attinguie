<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api'
import { formatAmount } from '../utils/formatters'
const loading = ref(true)
const error = ref('')
const items = ref([])
const businesses = ref([])
const selected = ref('')
const visible = computed(() => items.value.filter(i => !selected.value || String(i.businessId) === selected.value))
const money = formatAmount
async function load() {
  loading.value = true; error.value = ''
  try {
    const result = await api.get('/api/merchant/invoices')
    items.value = result.items || []
    businesses.value = result.businesses || []
  } catch (e) { error.value = e.message || 'Impossible de charger les factures.' }
  finally { loading.value = false }
}
onMounted(load)
</script>
<template>
  <section class="gc-merchant-invoices">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <div><h1>Mes factures</h1><p class="text-secondary">Suivi des factures émises, règlements et soldes à payer.</p></div>
      <button type="button" class="btn gc-button-primary" @click="load">Actualiser</button>
    </div>
    <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
    <p v-if="loading" role="status">Chargement…</p>
    <div v-else-if="!businesses.length" class="alert alert-info">Aucun commerce n’est encore rattaché à ton compte. Contacte un administrateur municipal.</div>
    <template v-else>
      <div class="card mb-3"><label>Commerce
        <select v-model="selected" class="form-select"><option value="">Tous mes commerces</option><option v-for="b in businesses" :key="b.id" :value="String(b.id)">{{ b.name }}</option></select>
      </label></div>
      <div class="table-responsive card"><table class="table table-hover align-middle"><thead><tr><th>Facture</th><th>Commerce</th><th>Période</th><th>Montant</th><th>Déjà payé</th><th>Reste dû</th><th>Échéance</th><th>Document</th></tr></thead>
        <tbody><tr v-for="i in visible" :key="i.id">
          <td><strong>{{ i.number }}</strong></td><td>{{ i.businessName }}</td><td>{{ i.period }}</td>
          <td>{{ money(i.total) }} FCFA</td><td>{{ money(i.paid) }} FCFA</td>
          <td><strong>{{ money(i.balance) }} FCFA</strong></td><td>{{ i.dueAt || '—' }}</td>
          <td><a class="btn gc-button-primary btn-sm" :href="`/api/merchant/invoices/${i.id}/pdf`">PDF</a></td>
        </tr><tr v-if="!visible.length"><td colspan="8">Aucune facture émise pour cette sélection.</td></tr></tbody>
      </table></div>
    </template>
  </section>
</template>
<style scoped>
.gc-merchant-invoices h1 {color:#075e3d}
.gc-merchant-invoices .gc-button-primary {background:#e86d00;border-color:#e86d00;color:#fff;font-weight:700}
.gc-merchant-invoices .gc-button-primary:hover {background:#c85e00;border-color:#c85e00}
</style>
