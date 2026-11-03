<script setup>
import { computed } from 'vue'
const props = defineProps({ name: { type: String, required: true }, size: { type: [String, Number], default: 20 } })
const shapes = {
  dashboard: ['M3 3h7v7H3z','M14 3h7v7h-7z','M14 14h7v7h-7z','M3 14h7v7H3z'],
  shop: ['M3 10h18l-2-6H5l-2 6Z','M5 10v11h14V10','M9 21v-7h6v7','M3 10c0 2 3 3 4 0 1 3 4 3 5 0 1 3 4 3 5 0 1 3 4 2 4 0'],
  file: ['M5 2h9l5 5v15H5z','M14 2v6h5','M8 13h8','M8 17h8'],
  wallet: ['M3 6h18v15H3z','M3 9h18','M16 15h3'],
  chart: ['M3 3v18h18','M7 16v-4','M12 16V8','M17 16V5'],
  users: ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2','M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8','M22 21v-2a4 4 0 0 0-3-3.87','M16 3.13a4 4 0 0 1 0 7.75'],
  settings: ['M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z','M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06-2 2-.06-.06A1.65 1.65 0 0 0 16 18.5l-.25.1a1.65 1.65 0 0 0-1 1.52V21h-4v-.88a1.65 1.65 0 0 0-1-1.52l-.25-.1a1.65 1.65 0 0 0-1.82.33l-.06.06-2-2 .06-.06A1.65 1.65 0 0 0 6 15l-.1-.25a1.65 1.65 0 0 0-1.52-1H3v-4h1.38a1.65 1.65 0 0 0 1.52-1L6 8.5a1.65 1.65 0 0 0-.33-1.82l-.06-.06 2-2 .06.06A1.65 1.65 0 0 0 9.5 5l.25-.1a1.65 1.65 0 0 0 1-1.52V3h4v.38a1.65 1.65 0 0 0 1 1.52L16 5a1.65 1.65 0 0 0 1.82-.33l.06-.06 2 2-.06.06A1.65 1.65 0 0 0 19.5 8.5l.1.25a1.65 1.65 0 0 0 1.52 1H22v4h-.88a1.65 1.65 0 0 0-1.52 1Z'],
  search: ['M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16','m21 21-4.35-4.35'],
  plus: ['M12 5v14','M5 12h14'],
  check: ['M20 6 9 17l-5-5'],
  alert: ['M12 3 2 21h20L12 3Z','M12 9v4','M12 17h.01'],
  edit: ['M12 20h9','M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z'],
  qr: ['M3 3h7v7H3z','M14 3h7v7h-7z','M3 14h7v7H3z','M14 14h3v3h-3z','M20 14v2','M18 19h3','M20 21v-2','M10 14h2v3','M12 20h2'],
  export: ['M12 3v12','m7 10 5 5 5-5','M4 17v4h16v-4'],
  calendar: ['M3 5h18v16H3z','M7 2v6','M17 2v6','M3 10h18'],
  logout: ['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4','m16 17 5-5-5-5','M21 12H9'],
  menu: ['M4 6h16','M4 12h16','M4 18h16'],
  close: ['M5 5 19 19','M19 5 5 19'],
  chevron: ['m9 18 6-6-6-6'],
  lock: ['M5 11h14v10H5z','M8 11V7a4 4 0 0 1 8 0v4'],
  mail: ['M3 5h18v14H3z','m3 7 9 6 9-6'],
  eye: ['M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z','M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z'],
  eyeoff: ['M3 3l18 18','M2 12s3.5-6 10-6a10 10 0 0 1 5 1.2','M22 12s-3.5 6-10 6a10 10 0 0 1-5-1.2'],
  help: ['M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2-3 4','M12 18h.01','M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z'],
  filter: ['M4 7h16','M7 12h10','M10 17h4'],
  coins: ['M12 8c5 0 9-1.3 9-3s-4-3-9-3-9 1.3-9 3 4 3 9 3Z','M3 5v5c0 1.7 4 3 9 3s9-1.3 9-3V5','M3 10v5c0 1.7 4 3 9 3s9-1.3 9-3v-5','M3 15v4c0 1.7 4 3 9 3s9-1.3 9-3v-4'],
  refresh: ['M20 11a8 8 0 0 0-14-5L3 9','M3 3v6h6','M4 13a8 8 0 0 0 14 5l3-3','M21 21v-6h-6'],
  home: ['M3 10 12 3l9 7v11H3z','M9 21v-8h6v8'],
  shield: ['M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z','m8 12 3 3 5-6'],
}
const pathList = computed(() => shapes[props.name] || shapes.shop)
</script>
<template>
  <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <path v-for="(path, index) in pathList" :key="index" :d="path"/>
  </svg>
</template>
