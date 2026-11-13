import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const read = file => readFileSync(new URL(file, import.meta.url), 'utf8')
const activity = read('../src/views/ActivityCatalogAdminView.vue')
const tariff = read('../src/views/TariffCatalogAdminView.vue')
const field = read('../src/components/GcCatalogFormField.vue')
const panel = read('../src/components/GcCatalogTablePanel.vue')
const count = (text, fragment) => text.split(fragment).length - 1

test('champ mutualisé : balise label native et slot conservés', () => {
  assert.match(field, /<label v-bind="\$attrs"><slot\s*\/><\/label>/)
  assert.match(field, /inheritAttrs:\s*false/)
})
test('panneau mutualisé : mêmes classes CSS et même élément HTML', () => {
  assert.match(panel, /<div class="gc-catalog-panel gc-catalog-scroll" v-bind="\$attrs"><slot\s*\/><\/div>/)
})
test('catalogue activités : deux champs et un panneau, sans changement des v-model', () => {
  assert.equal(count(activity, '<GcCatalogFormField>'), 2)
  assert.equal(count(activity, '<GcCatalogTablePanel>'), 1)
  assert.match(activity, /v-model\.trim="form\.code"/)
  assert.match(activity, /v-model\.trim="form\.label"/)
  assert.match(activity, /@submit\.prevent="save"/)
})
test('catalogue tarifs : trois champs, checkbox native et un panneau', () => {
  assert.equal(count(tariff, '<GcCatalogFormField>'), 3)
  assert.equal(count(tariff, '<GcCatalogTablePanel>'), 1)
  assert.match(tariff, /<label class="gc-catalog-checkbox">/)
  assert.match(tariff, /v-model="form\.period"/)
  assert.match(tariff, /v-model\.trim="form\.amount"/)
  assert.match(tariff, /@submit\.prevent="save"/)
})
