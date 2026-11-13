<script setup>
import GcIcon from './GcIcon.vue'

defineProps({
  status: { type: String, required: true },
  label: { type: String, required: true },
  icon: { type: String, default: '' },
})
</script>

<template>
  <span class="gc-status-chip" :class="status">
    <GcIcon v-if="icon" :name="icon" :size="15"/>
    {{ label }}
  </span>
</template>
