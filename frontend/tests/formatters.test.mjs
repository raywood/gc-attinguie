import test from 'node:test'
import assert from 'node:assert/strict'
import {
  formatAmount, formatMoney, formatCompactAmount,
  currentAbidjanMonth, formatMonthFr,
  formatAbidjanDate, formatAbidjanDateTime,
} from '../src/utils/formatters.js'

test('montants FCFA et autres devises', () => {
  const number = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format(15000.5)
  assert.equal(formatAmount('15000.5'), number)
  assert.equal(formatMoney('15000.5'), `${number} FCFA`)
  assert.equal(formatMoney(100, 'EUR'), `${formatAmount(100)} EUR`)
  assert.equal(formatAmount(null), '0')
})

test('montants compacts', () => {
  assert.equal(formatCompactAmount(125000),
    new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 }).format(125000))
})

test('mois dans le fuseau Africa/Abidjan', () => {
  assert.equal(currentAbidjanMonth(new Date('2026-11-01T00:15:00Z')), '2026-11')
  assert.equal(currentAbidjanMonth(new Date('2026-10-31T23:15:00Z')), '2026-10')
  assert.equal(formatMonthFr('2026-11'), 'novembre 2026')
  assert.equal(formatMonthFr('invalide'), 'invalide')
})

test('dates metier en heure ivoirienne', () => {
  assert.equal(formatAbidjanDate(null), 'Non renseignée')
  assert.equal(formatAbidjanDate('2026-11-10'),
    new Date('2026-11-10T12:00:00Z').toLocaleDateString('fr-FR', { timeZone: 'Africa/Abidjan' }))
  assert.equal(formatAbidjanDateTime(null), '—')
  assert.equal(formatAbidjanDateTime('2026-11-10T12:00:00Z'),
    new Date('2026-11-10T12:00:00Z').toLocaleString('fr-FR', {
      timeZone: 'Africa/Abidjan', day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit',
    }))
})
