import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// En dev, /api est redirigé vers Symfony (même origine pour le navigateur → cookie de session OK).
export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5173,
    proxy: { '/api': { target: 'http://localhost:8000', changeOrigin: false } },
  },
})
