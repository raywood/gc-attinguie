import { onMounted, onUnmounted } from 'vue'

// Signal sans données personnelles : seules les vues actives rechargent leur API.
const EVENT = 'gc-attinguie:data-changed'
const CHANNEL = 'gc-attinguie:data-refresh'
let channel = null

if (typeof window !== 'undefined' && 'BroadcastChannel' in window) {
  channel = new BroadcastChannel(CHANNEL)
  channel.onmessage = (message) => {
    if (message.data?.type === 'refresh') window.dispatchEvent(new Event(EVENT))
  }
}

// Appelé uniquement après une mutation API confirmée (HTTP 2xx).
export function notifyGcDataChanged() {
  if (typeof window === 'undefined') return
  window.dispatchEvent(new Event(EVENT))
  channel?.postMessage({ type: 'refresh' })
}

// Refresh des vues montées, des autres onglets et lors du retour sur l'onglet.
export function useGcDataRefresh(reload) {
  let timer = null
  const schedule = () => {
    if (document.visibilityState === 'hidden') return
    if (timer !== null) window.clearTimeout(timer)
    timer = window.setTimeout(() => {
      timer = null
      void reload()
    }, 120)
  }
  const onVisibility = () => {
    if (document.visibilityState === 'visible') schedule()
  }
  onMounted(() => {
    window.addEventListener(EVENT, schedule)
    window.addEventListener('focus', schedule)
    document.addEventListener('visibilitychange', onVisibility)
  })
  onUnmounted(() => {
    window.removeEventListener(EVENT, schedule)
    window.removeEventListener('focus', schedule)
    document.removeEventListener('visibilitychange', onVisibility)
    if (timer !== null) window.clearTimeout(timer)
  })
}
