import { installGcMutationToasts } from './services/gc-toasts'
import { createApp, watchEffect } from 'vue'
import App from './App.vue'
import { router } from './router'
import { api } from './api'
import { auth } from './auth'
import { branding, loadBranding } from './services/gc-branding'

// Bootstrap 5.3.6 local : pas de CDN, pas de dépendance npm supplémentaire.
import './vendor/bootstrap.min.css'
import './style.css'
import './styles/gc-tokens.css'
import './styles/gc-brand.css'
import './styles/gc-toasts.css'
import './styles/gc-buttons-orange.css'
import './styles/gc-catalog.css'
import './styles/gc-branding.css'

api.onUnauthorized = () => {
  auth.user = null
  router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
}

installGcMutationToasts()

// Le nom, l'icône d'onglet et la marque CSS suivent immédiatement la marque réactive.
watchEffect(() => {
  document.title = `${branding.appName} · Gestion municipale`
  const icon = document.querySelector('link[rel="icon"]')
  if (icon) { icon.href = branding.logoUrl; icon.type = branding.logoUrl.endsWith('.png') ? 'image/png' : 'image/webp' }
  document.documentElement.style.setProperty('--gc-logo-image', `url("${branding.logoUrl}")`)
  const meta = document.querySelector('meta[name="description"]')
  if (meta) meta.content = branding.dashboardDescription
})
void loadBranding()
window.addEventListener('focus', () => { void loadBranding() })

createApp(App).use(router).mount('#app')
