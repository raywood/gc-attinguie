import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const root = new URL('../src/', import.meta.url)
const source = path => readFileSync(new URL(path, root), 'utf8')

const empty = source('components/GcEmptyState.vue')
const loading = source('components/GcLoadingState.vue')
const activities = source('views/ActivityCatalogAdminView.vue')
const tariffs = source('views/TariffCatalogAdminView.vue')

test('etats mutualises : balise personnalisable et attributs CSS conserves', () => {
  for (const component of [empty, loading]) {
    assert.match(component, /:is="as"/)
    assert.match(component, /v-bind="\$attrs"/)
    assert.match(component, /<slot\s*\/>/)
  }
  assert.match(loading, /role="status"/)
})

test('catalogue activites : message vide et chargement preserves', () => {
  assert.match(activities, /<tr v-if="!loading && !filtered\.length"><GcEmptyState as="td" colspan="5" class="gc-catalog-empty">Aucune activité trouvée\.<\/GcEmptyState><\/tr>/)
  assert.match(activities, /<GcLoadingState v-if="loading" class="gc-catalog-help">Chargement des activités…<\/GcLoadingState>/)
  assert.match(activities, /import GcEmptyState from '\.\.\/components\/GcEmptyState\.vue'/)
  assert.match(activities, /import GcLoadingState from '\.\.\/components\/GcLoadingState\.vue'/)
})

test('catalogue tarifs : message vide et chargement preserves', () => {
  assert.match(tariffs, /<tr v-if="!loading && !filtered\.length"><GcEmptyState as="td" colspan="5" class="gc-catalog-empty">Aucun tarif trouvé\.<\/GcEmptyState><\/tr>/)
  assert.match(tariffs, /<GcLoadingState v-if="loading" class="gc-catalog-help">Chargement des tarifs…<\/GcLoadingState>/)
  assert.match(tariffs, /import GcEmptyState from '\.\.\/components\/GcEmptyState\.vue'/)
  assert.match(tariffs, /import GcLoadingState from '\.\.\/components\/GcLoadingState\.vue'/)
})
