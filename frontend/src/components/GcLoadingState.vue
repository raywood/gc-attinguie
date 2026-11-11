<script setup>
// La sémantique role="status" des messages de chargement est préservée.
defineOptions({ inheritAttrs: false })
defineProps({
  as: { type: String, default: 'p' },
})
</script>

<template>
  <component :is="as" role="status" v-bind="$attrs"><slot /></component>
</template>
