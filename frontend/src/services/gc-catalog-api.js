/** Client spécifique au catalogue, avec CSRF Symfony sur les écritures. Les toasts V3 suivent fetch. */
export async function gcCatalogRequest(method, path, payload, csrf) {
  const write = method !== 'GET'
  const response = await fetch(path, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(write ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf || '' } : {}),
    },
    ...(write ? { body: JSON.stringify(payload ?? {}) } : {}),
  })
  const result = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(result?.error || `Erreur HTTP ${response.status}`)
  return result
}
