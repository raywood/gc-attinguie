import test from 'node:test'
import assert from 'node:assert/strict'
import { businessStatusLabel } from '../src/utils/business-status.js'

test('statuts municipaux existants', () => {
  assert.equal(businessStatusLabel('active'), 'Actif')
  assert.equal(businessStatusLabel('suspended'), 'Suspendu')
  assert.equal(businessStatusLabel('closed'), 'Fermé')
})

test('statuts inconnus et absents', () => {
  assert.equal(businessStatusLabel('pending'), 'pending')
  assert.equal(businessStatusLabel(null), '—')
})
