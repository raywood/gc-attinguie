// Formats communs aux ecrans de GC-ATTINGUIE.
// Les calculs financiers restent sur le serveur : ces helpers servent uniquement a l'affichage.
const amountFormatter = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 })
const compactFormatter = new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 })
const monthFormatter = new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric', timeZone: 'UTC' })
const abidjanMonthParts = new Intl.DateTimeFormat('en-US', {
  timeZone: 'Africa/Abidjan', year: 'numeric', month: '2-digit',
})
const abidjanDateFormatter = new Intl.DateTimeFormat('fr-FR', { timeZone: 'Africa/Abidjan' })
const abidjanDateTimeFormatter = new Intl.DateTimeFormat('fr-FR', {
  timeZone: 'Africa/Abidjan', day: '2-digit', month: '2-digit', year: 'numeric',
  hour: '2-digit', minute: '2-digit',
})

export function formatAmount(value) {
  return amountFormatter.format(Number(value || 0))
}

export function formatMoney(value, currency = 'XOF') {
  const label = currency === 'XOF' ? 'FCFA' : currency
  return `${formatAmount(value)} ${label}`
}

export function formatCompactAmount(value) {
  return compactFormatter.format(Number(value || 0))
}

export function currentAbidjanMonth(date = new Date()) {
  const parts = abidjanMonthParts.formatToParts(date)
  const year = parts.find(p => p.type === 'year')?.value
  const month = parts.find(p => p.type === 'month')?.value
  return `${year}-${month}`
}

export function formatMonthFr(value) {
  if (!/^\d{4}-\d{2}$/.test(value ?? '')) return value || ''
  const [year, month] = value.split('-').map(Number)
  return monthFormatter.format(new Date(Date.UTC(year, month - 1, 1)))
}

// Les dates des echeances sont de type YYYY-MM-DD (sans decalage horaire).
export function formatAbidjanDate(value, fallback = 'Non renseignée') {
  if (!value) return fallback
  return abidjanDateFormatter.format(new Date(`${value}T12:00:00Z`))
}

export function formatAbidjanDateTime(value, fallback = '—') {
  if (!value) return fallback
  return abidjanDateTimeFormatter.format(new Date(value))
}
