<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import { auth } from '../auth'
import { can, refreshAbilities } from '../security/abilities'

const route = useRoute()
const router = useRouter()
const financeLoading = ref(false)
const financeError = ref('')
async function openFinance() {
  financeLoading.value = true; financeError.value = ''
  try {
    const resolved = await api.get(`/api/businesses/resolve-qr/${encodeURIComponent(route.params.token)}`)
    await router.push(`/commerces/${resolved.businessId}/finances`)
  } catch (e) { financeError.value = e.message || 'Accès refusé.' }
  finally { financeLoading.value = false }
}
const info = ref(null)
const error = ref('')
const loading = ref(true)
const statusText = { active: 'ACTIF', suspended: 'SUSPENDU', closed: 'FERMÉ' }
onMounted(async () => {
  if (auth.user) await refreshAbilities()
  try {
    info.value = await api.get(`/api/public/businesses/${encodeURIComponent(route.params.token)}`)
  } catch (e) {
    error.value = e.status === 410 ? 'Ce QR Code a été révoqué.' : 'QR Code inconnu ou invalide.'
  } finally { loading.value = false }
})
</script>
<template>
  <section class="card" style="max-width:650px;margin:2rem auto;border-top:7px solid #f77f00;border-bottom:7px solid #168a45">
    <p class="muted">Mairie d’Attinguié · Identification des commerces</p>
    <h1>Vérification du commerce</h1>
    <p v-if="loading">Vérification en cours…</p>
    <p v-else-if="error" class="error" role="alert">{{ error }}</p>
    <div v-else-if="info">
      <p><strong>{{ info.reference }}</strong></p>
      <h2>{{ info.name }}</h2>
      <p>Activité : {{ info.activity }}</p>
      <p>Quartier : {{ info.district || 'Non renseigné' }}</p>
      <p><strong>Statut actuel : {{ statusText[info.status] || 'INCONNU' }}</strong></p>
      <p class="muted small">Cette fiche ne constitue pas une autorisation d’exercer. Les informations de facturation sont confidentielles.</p>
      <p v-if="!auth.user"><RouterLink :to="{ name: 'login' }">Connexion du personnel municipal</RouterLink></p>
      <p v-else-if="can('finance.read') && can('factures.read') && can('commerces.read')" class="muted small"><button class="btn" type="button" :disabled="financeLoading" @click="openFinance">{{ financeLoading ? 'Chargement…' : 'Situation financière (accès sécurisé)' }}</button><span v-if="financeError" class="error">{{ financeError }}</span></p>
    </div>
  </section>
</template>
