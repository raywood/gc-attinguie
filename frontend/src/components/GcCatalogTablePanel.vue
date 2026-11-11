<script setup>
// Rend exactement l'ancien div.gc-catalog-panel.gc-catalog-scroll.
defineOptions({ inheritAttrs: false })
</script>

<template>
  <div class="gc-catalog-panel gc-catalog-scroll" v-bind="$attrs"><slot /></div>
</template>
