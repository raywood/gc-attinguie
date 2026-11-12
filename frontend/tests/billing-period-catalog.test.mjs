import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const read = file => readFileSync(new URL(file, import.meta.url), 'utf8')
const view = read('../src/views/TariffCatalogAdminView.vue')
const tariff = read('../../backend/src/Controller/AdminTariffCatalogController.php')
const billing = read('../../backend/src/Controller/AdminBillingController.php')
const endpoint = read('../../backend/src/Controller/AdminBillingPeriodCatalogController.php')
const source = read('../../backend/src/Service/BillingPeriodCatalog.php')

test('source unique PHP des quatre périodicités et capacités', () => {
  for (const code of ['daily', 'weekly', 'monthly', 'yearly']) {
    assert.match(source, new RegExp(`'${code}'\\s*=>`))
  }
  assert.match(source, /'processing' => 'monthly_invoice'/)
  assert.match(source, /'processing' => 'daily_record'/)
  assert.equal((source.match(/'processing' => 'catalog_only'/g) ?? []).length, 2)
})

test('lecture API réservée aux administrateurs et non cacheable', () => {
  assert.match(endpoint, /#\[IsGranted\('ROLE_ADMIN'\)\]/)
  assert.match(endpoint, /methods:\s*\['GET'\]/)
  assert.match(endpoint, /BillingPeriodCatalog::all\(\)/)
  assert.match(endpoint, /Cache-Control', 'private, no-store'/)
})

test('validation backend dédupliquée, sans élargissement des valeurs autorisées', () => {
  assert.match(tariff, /BillingPeriodCatalog::accepts\(\$period\)/)
  assert.match(billing, /BillingPeriodCatalog::accepts\(\$frequency\)/)
  assert.doesNotMatch(tariff, /private const PERIODS/)
  assert.doesNotMatch(billing, /in_array\(\$frequency, \['daily'/)
})

test('Vue lit les périodicités depuis Symfony et conserve le modèle du formulaire', () => {
  assert.match(view, /const periods = ref\(\[\]\)/)
  assert.match(view, /gcCatalogRequest\('GET', '\/api\/admin\/catalog\/periods'\)/)
  assert.match(view, /periods\.value = p\.items/)
  assert.match(view, /v-model="form\.period"/)
  assert.match(view, /!periods\.length/)
  assert.match(view, /!Array\.isArray\(p\.items\)/)
})

test('les avertissements et le traitement mensuel restent en place', () => {
  assert.match(view, /form\.value\.period === 'monthly'/)
  assert.match(tariff, /\$tariff->getPeriod\(\) !== 'monthly'/)
  assert.match(view, /factures générées/)
})
