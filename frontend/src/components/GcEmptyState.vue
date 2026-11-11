<script setup>
// Ne change ni la balise finale ni les classes transmises par les vues.
defineOptions({ inheritAttrs: false })
defineProps({
  as: { type: String, default: 'p' },
})
</script>

<template>
  <component :is="as" v-bind="$attrs"><slot /></component>
</template>
