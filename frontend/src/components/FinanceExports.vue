<script setup>
import { computed } from 'vue'
const props = defineProps({ period: { type: String, required: true } })
const valid = computed(() => /^20\d{2}-(0[1-9]|1[0-2])$/.test(props.period))
const url = (name) => `/api/admin/reports/finance/${name}?period=${encodeURIComponent(props.period)}`
</script>

<template>
  <section class="card financial-exports">
    <h2>Rapports et exports financiers</h2>
    <p class="muted small">Exports administratifs en lecture seule, pour la période {{ period }}. CSV UTF-8 compatibles Excel.</p>
    <div class="financial-export-actions">
      <a class="btn" :class="{ disabled: !valid }" :href="valid ? url('summary.pdf') : undefined" download aria-label="Télécharger le rapport financier PDF">Rapport PDF</a>
      <a class="btn ghost" :class="{ disabled: !valid }" :href="valid ? url('invoices.csv') : undefined" download>Factures CSV</a>
      <a class="btn ghost" :class="{ disabled: !valid }" :href="valid ? url('payments.csv') : undefined" download>Encaissements CSV</a>
      <a class="btn ghost" :class="{ disabled: !valid }" :href="valid ? url('activities.csv') : undefined" download>Activités CSV</a>
    </div>
    <p class="muted small" style="margin-bottom:0">Un reçu annulé ne retranche pas le paiement : une régularisation financière nécessite une opération distincte.</p>
  </section>
</template>

<style scoped>
.financial-exports { display:grid;gap:.6rem; }
.financial-exports h2 { font-size: 1.08rem; margin:0; }
.financial-exports p { margin:0; }
.financial-export-actions { display:flex; gap:.6rem; flex-wrap:wrap; }
.financial-export-actions .disabled { opacity:.5; pointer-events:none; }
</style>
