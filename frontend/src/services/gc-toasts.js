import { reactive } from 'vue'

// Notifications pour les enregistrements métiers de GC-ATTINGUIÉ.
export const gcToasts = reactive([])
let sequence = 0
let installed = false
const timeoutIds = new Map()

export function dismissGcToast(id) {
  if (timeoutIds.has(id)) window.clearTimeout(timeoutIds.get(id))
  timeoutIds.delete(id)
  const index = gcToasts.findIndex(item => item.id === id)
  if (index >= 0) gcToasts.splice(index, 1)
}

function startToast() {
  if (gcToasts.length >= 5) {
    const finished = gcToasts.find(item => item.state !== 'loading')
    if (finished) dismissGcToast(finished.id)
  }
  const id = ++sequence
  gcToasts.push({
    id, state: 'loading', title: 'Enregistrement en cours ...',
    duration: 0, phase: 0, startedAt: Date.now(),
  })
  return id
}

function finishToast(id, state, title) {
  const toast = gcToasts.find(item => item.id === id)
  if (!toast) return
  // Ne retarde pas l'API ; laisse simplement le texte de chargement visible >= 450 ms.
  const delay = Math.max(0, 450 - (Date.now() - toast.startedAt))
  const t = window.setTimeout(() => {
    timeoutIds.delete(id)
    const live = gcToasts.find(item => item.id === id)
    if (!live) return
    live.state = state
    live.title = title
    live.duration = state === 'success' ? 4200 : 6500
    live.phase++
    timeoutIds.set(id, window.setTimeout(() => dismissGcToast(id), live.duration))
  }, delay)
  timeoutIds.set(id, t)
}

function trackedMutation(input, init) {
  const method = String(init?.method || (typeof Request !== 'undefined' && input instanceof Request ? input.method : 'GET')).toUpperCase()
  if (!['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) return null
  try {
    const raw = typeof Request !== 'undefined' && input instanceof Request ? input.url : String(input)
    const url = new URL(raw, window.location.href)
    if (url.origin !== window.location.origin || !url.pathname.startsWith('/api/')) return null
    if (/^\/api\/(login|logout)\/?$/.test(url.pathname)) return null
    return method
  } catch {
    return null
  }
}

function successMessage(method) {
  return method === 'DELETE' ? 'Suppression effectuée avec succès.' : 'Enregistrement effectué avec succès.'
}

async function errorMessage(res) {
  let message = res.status === 403 ? 'Action non autorisée.' :
    res.status === 401 ? 'Session expirée : reconnectez-vous.' :
    `Enregistrement refusé (HTTP ${res.status}).`
  try {
    const data = await res.clone().json()
    if (typeof data?.error === 'string' && data.error.trim()) message = data.error.trim().slice(0, 160)
  } catch { /* réponse sans JSON */ }
  return `Échec : ${message}`
}

export function installGcMutationToasts() {
  if (installed || typeof window === 'undefined') return
  installed = true
  const originalFetch = window.fetch.bind(window)
  window.fetch = async function gcTrackedFetch(input, init) {
    const method = trackedMutation(input, init)
    if (!method) return originalFetch(input, init)
    const toastId = startToast()
    try {
      const response = await originalFetch(input, init)
      if (response.ok) finishToast(toastId, 'success', successMessage(method))
      else finishToast(toastId, 'error', await errorMessage(response))
      return response
    } catch (err) {
      finishToast(toastId, 'error', 'Échec : connexion interrompue. Enregistrement non confirmé.')
      throw err
    }
  }
}
