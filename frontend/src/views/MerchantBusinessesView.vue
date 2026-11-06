<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api'

const businesses = ref([])
const loading = ref(true)
const error = ref('')
onMounted(async () => {
  try {
    const result = await api.get('/api/merchant/businesses')
    businesses.value = Array.isArray(result.items) ? result.items : []
  } catch (e) { error.value = e.message || 'Impossible de charger vos commerces.' }
  finally { loading.value = false }
})
</script>
<template>
  <section class="container-fluid gc-merchant-businesses">
    <div class="row-head"><h1>Mes commerces</h1>
      <RouterLink to="/mon-espace/factures" class="btn">Mes factures</RouterLink>
    </div>
    <p class="muted">Vous ne voyez ici que les commerces auxquels un administrateur vous a donné accès.</p>
    <p v-if="loading">Chargement…</p>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <div v-if="!loading && !error" class="row g-3">
      <div v-for="b in businesses" :key="b.id" class="col-12 col-md-6 col-xl-4">
        <div class="card h-100"><h2>{{ b.name }}</h2>
          <p>Activité : {{ b.activity }}</p>
          <p>Statut : <strong>{{ b.status }}</strong></p>
        </div>
      </div>
      <p v-if="businesses.length===0" class="muted">Aucun commerce associé à votre compte. Contactez la mairie.</p>
    </div>
  </section>
</template>
