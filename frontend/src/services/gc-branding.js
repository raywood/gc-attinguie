import { reactive } from 'vue'

/** Identité commune à toutes les pages (y compris la connexion). */
export const branding = reactive({
  appName: 'GC-ATTINGUIÉ',
  organizationName: 'Commune d’Attinguié',
  dashboardDescription: 'La plateforme de gestion des commerces de la Commune d’Attinguié.',
  logoUrl: '/gc-attinguie-logo.png',
  version: 1,
  loaded: false,
})

let inFlight = null

export function setBranding(data) {
  if (!data || typeof data.appName !== 'string' || typeof data.dashboardDescription !== 'string'
      || typeof data.organizationName !== 'string' || typeof data.logoUrl !== 'string'
      || !Number.isInteger(data.version)) return false
  // N'autoriser que l'URL du logo de notre propre API ou le logo historique.
  if (!/^\/(?:gc-attinguie-logo\.png|api\/public\/branding\/logo\?v=[0-9]+)$/.test(data.logoUrl)) return false
  branding.appName = data.appName
  branding.organizationName = data.organizationName
  branding.dashboardDescription = data.dashboardDescription
  branding.logoUrl = data.logoUrl
  branding.version = data.version
  branding.loaded = true
  return true
}

export async function loadBranding() {
  if (inFlight) return inFlight
  inFlight = (async () => {
    try {
      const response = await fetch('/api/public/branding', {
        headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store',
      })
      if (!response.ok) return false
      return setBranding(await response.json())
    } catch { return false }
    finally { branding.loaded = true }
  })()
  try { return await inFlight }
  finally { inFlight = null }
}

/** Nom stable et sûr pour les fichiers CSV téléchargés. */
export function brandingSlug() {
  return branding.appName.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'municipalite'
}
