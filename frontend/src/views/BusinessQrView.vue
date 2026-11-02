<script setup>
import { useRoute } from 'vue-router'
import BusinessQrCode from '../components/BusinessQrCode.vue'
const route = useRoute()
</script>
<template>
  <div class="row-head">
    <h1>QR Code du commerce</h1>
    <RouterLink to="/commerces" class="btn ghost">← Retour à la liste</RouterLink>
  </div>
  <BusinessQrCode :business-id="route.params.id" />
</template>
