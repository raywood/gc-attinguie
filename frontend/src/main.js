import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router'
import { api } from './api'
import { auth } from './auth'
import './style.css'

// Session expirée : on revient à la page de connexion.
api.onUnauthorized = () => {
  auth.user = null
  router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
}

createApp(App).use(router).mount('#app')
