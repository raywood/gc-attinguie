import { reactive } from 'vue'
import { api } from './api'

export const auth = reactive({
  user: null,
  loaded: false,

  async fetchMe() {
    try {
      this.user = await api.get('/api/me')
    } catch {
      this.user = null
    }
    this.loaded = true
  },

  async login(email, password) {
    this.user = await api.post('/api/login', { email, password })
  },

  async logout() {
    try {
      await api.post('/api/logout')
    } finally {
      this.user = null
    }
  },
})
