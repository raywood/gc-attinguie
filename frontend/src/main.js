import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router'
import { api } from './api'
import { auth } from './auth'

// Bootstrap 5.3.6 local : pas de CDN, pas de dépendance npm supplémentaire.
import './vendor/bootstrap.min.css'
import './style.css'
import './styles/gc-brand.css'

api.onUnauthorized = () => {
  auth.user = null
  router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
}

createApp(App).use(router).mount('#app')
