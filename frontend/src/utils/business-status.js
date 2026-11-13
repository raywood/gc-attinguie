// Libellés actuels des statuts métiers, regroupés en attendant le référentiel API.
// Les règles d'autorisation et de transition restent exclusivement côté Symfony.
const LABELS = Object.freeze({
  active: 'Actif',
  suspended: 'Suspendu',
  closed: 'Fermé',
})

export function businessStatusLabel(status) {
  return LABELS[status] ?? String(status || '—')
}
