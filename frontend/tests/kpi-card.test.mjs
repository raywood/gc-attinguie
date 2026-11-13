import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const component = readFileSync(new URL('../src/components/GcKpiCard.vue', import.meta.url), 'utf8')
const businesses = readFileSync(new URL('../src/views/BusinessesView.vue', import.meta.url), 'utf8')
const dashboard = readFileSync(new URL('../src/views/FinancialDashboardView.vue', import.meta.url), 'utf8')
const brand = readFileSync(new URL('../src/styles/gc-brand.css', import.meta.url), 'utf8')

test('carte KPI : racine et structure CSS historiques conservées', () => {
  assert.match(component, /<article class="gc-kpi" :class="`gc-kpi-\$\{tone\}`">/)
  assert.match(component, /<GcIcon :name="icon" :size="iconSize"\s*\/>/)
  assert.match(component, /<strong>\{\{ value \}\}<\/strong>/)
  assert.match(brand, /\.gc-kpi > svg/)
})

test('commerces : trois cartes KPI mutualisées', () => {
  assert.equal((businesses.match(/<GcKpiCard\b/g) || []).length, 3)
  assert.doesNotMatch(businesses, /<article class="gc-kpi /)
  assert.match(businesses, /:value="activeOnPage"/)
  assert.match(businesses, /:value="inactiveOnPage"/)
})

test('tableau de bord : quatre cartes KPI mutualisées sans recalcul financier', () => {
  assert.equal((dashboard.match(/<GcKpiCard\b/g) || []).length, 4)
  assert.doesNotMatch(dashboard, /<article class="gc-kpi /)
  for (const expr of [
    'money(data.summary.cashCollected)', 'data.summary.invoiceCount',
    'money(data.summary.remaining)', 'money(data.summary.overdue)',
  ]) assert.ok(dashboard.includes(`:value="${expr}"`), `Valeur conservée : ${expr}`)
})
