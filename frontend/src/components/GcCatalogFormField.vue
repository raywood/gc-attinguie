<script setup>
// Composant purement structurel : les classes CSS et la validation native restent inchangées.
defineOptions({ inheritAttrs: false })
</script>

<template>
  <label v-bind="$attrs"><slot /></label>
</template>
