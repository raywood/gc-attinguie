<script setup>
import { onMounted, ref, watch } from 'vue'
import { api } from '../api'
import { can } from '../security/abilities'
const props = defineProps({ businessId: { type: [String, Number], required: true } })
const data = ref(null)
const loading = ref(true)
const error = ref('')
async function load() {
  loading.value = true; error.value = ''
  try { data.value = await api.get(`/api/businesses/${encodeURIComponent(props.businessId)}/qrcode`) }
  catch (e) { error.value = e.message || 'Impossible de charger le QR Code.' }
  finally { loading.value = false }
}
onMounted(load)
watch(() => props.businessId, load)
const download = (format) => `/api/businesses/${encodeURIComponent(props.businessId)}/qrcode/download?format=${format}`
const certificate = () => `/api/businesses/${encodeURIComponent(props.businessId)}/certificate.pdf`
</script>
<template>
  <section class="card" style="margin-top:1.5rem;border-top:5px solid #168a45">
    <h2>Identification · QR Code</h2>
    <p class="muted small">Le QR Code est permanent : une modification du commerce ne change pas son lien de vérification.</p>
    <p v-if="loading">Génération de l’aperçu…</p>
    <p v-if="error" class="error">{{ error }} <button type="button" class="btn ghost" @click="load">Réessayer</button></p>
    <div v-if="data" style="display:flex;flex-wrap:wrap;gap:1.5rem;align-items:center">
      <div style="padding:12px;background:#fff;border:1px solid #ddd;border-radius:8px">
        <img :src="data.imageDataUri" :alt="`QR Code ${data.reference}`" width="190" height="190" style="display:block" />
      </div>
      <div style="flex:1;min-width:230px">
        <p><strong>{{ data.reference }}</strong> · Statut : {{ data.status }}</p>
        <p class="muted small" style="word-break:break-all">{{ data.verificationUrl }}</p>
        <div class="actions" style="flex-wrap:wrap">
          <a class="btn" :href="download('png')" download>Télécharger PNG</a>
          <a class="btn ghost" :href="download('svg')" download>Télécharger SVG</a>
          <a class="btn ghost" :href="certificate()" download>Attestation PDF A5</a>
        </div>
        <p v-if="can('commerces.read') && can('factures.read') && can('finance.read')">
          <RouterLink :to="`/commerces/${businessId}/finances`">Consulter la situation financière (personnel habilité)</RouterLink>
        </p>
      </div>
    </div>
  </section>
</template>
