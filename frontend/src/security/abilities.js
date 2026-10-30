import { reactive } from 'vue'

/** La navigation est cosmétique : les API restent contrôlées par Symfony. */
export const abilities = reactive({ roles: [], permissions: [], isAdmin: false, ready: false })

export async function refreshAbilities() {
  abilities.ready = false
  abilities.roles = []
  abilities.permissions = []
  abilities.isAdmin = false
  try {
    const res = await fetch('/api/rbac/me', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
    if (!res.ok) return false
    const data = await res.json()
    abilities.roles = Array.isArray(data.roles) ? data.roles : []
    abilities.permissions = Array.isArray(data.permissions) ? data.permissions : []
    abilities.isAdmin = data.isAdmin === true
    abilities.ready = true
    return true
  } catch { return false }
}

export function can(permission) {
  return abilities.ready && (abilities.isAdmin || abilities.permissions.includes(permission))
}
export function isAdministrator() { return abilities.ready && abilities.isAdmin }
export function clearAbilities() {
  abilities.roles = []; abilities.permissions = []; abilities.isAdmin = false; abilities.ready = false
}
