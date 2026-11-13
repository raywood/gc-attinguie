<script setup>
import GcIcon from './GcIcon.vue'

defineProps({
  icon: { type: String, required: true },
  iconSize: { type: [Number, String], default: 30 },
  title: { type: String, required: true },
  value: { type: [String, Number], required: true },
  detail: { type: [String, Number], default: '' },
  tone: { type: String, default: 'green', validator: value => ['green', 'orange', 'red'].includes(value) },
})
</script>

<template>
  <article class="gc-kpi" :class="`gc-kpi-${tone}`">
    <GcIcon :name="icon" :size="iconSize" />
    <div>
      <span>{{ title }}</span>
      <strong>{{ value }}</strong>
      <small>{{ detail }}</small>
    </div>
  </article>
</template>
