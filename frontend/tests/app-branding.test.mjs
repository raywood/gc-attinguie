import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
const store = read('../src/services/gc-branding.js')
const admin = read('../src/views/AppBrandingAdminView.vue')
const login = read('../src/views/LoginView.vue')
const shell = read('../src/App.vue')
const dashboard = read('../src/views/FinancialDashboardView.vue')
const collections = read('../src/views/CollectionsView.vue')
const businesses = read('../src/views/BusinessesView.vue')
const router = read('../src/router.js')
const php = read('../../backend/src/Controller/AdminBrandingController.php')
const publicApi = read('../../backend/src/Controller/PublicBrandingController.php')
const security = read('../../backend/config/packages/security.yaml')
const image = read('../../backend/src/Service/AppLogoStorage.php')
const migration = read('../../backend/migrations/Version20261112234500.php')

test('identité réactive, URL de logo strictement locale', () => {
  assert.match(store, /export const branding = reactive\(/)
  assert.match(store, /export async function loadBranding/)
  assert.match(store, /logoUrl/)
  assert.match(store, /gc-attinguie-logo/)
})
test('connexion et menu utilisent une marque partagée', () => {
  assert.match(login, /:src="branding\.logoUrl"/)
  assert.match(login, /\{\{ branding\.appName \}\}/)
  assert.match(shell, /:src="branding\.logoUrl"/)
  assert.match(shell, /\{\{ branding\.appName \}\}/)
})
test('le texte de bienvenue du dashboard est paramétrable', () => {
  assert.match(dashboard, /Bienvenue sur \{\{ branding\.appName \}\}/)
  assert.match(dashboard, /\{\{ branding\.dashboardDescription \}\}/)
})
test('écran réservé aux administrateurs et changements protégés côté API', () => {
  assert.match(router, /'\/administration\/personnalisation'.*meta: \{ admin: true \}/)
  assert.match(php, /#\[IsGranted\('ROLE_ADMIN'\)\]/)
  assert.match(php, /validCsrf\(\$request\)/)
  assert.match(php, /PESSIMISTIC_WRITE/)
  assert.match(admin, /form\.value\.version/) // concurrence versionnée
})
test('logo image réencodée, sans SVG, fichiers hors du public', () => {
  assert.match(image, /IMAGETYPE_PNG/)
  assert.match(image, /IMAGETYPE_JPEG/)
  assert.match(image, /IMAGETYPE_WEBP/)
  assert.match(image, /imagewebp/)
  assert.match(image, /'\/var\/branding\/'/)
  assert.match(publicApi, /X-Content-Type-Options/)
})
test('API publique limitée à GET et migration append-only non financière', () => {
  assert.match(security, /api\/public\/branding/)
  assert.match(publicApi, /methods: \['GET'\]/)
  assert.match(migration, /CREATE TABLE app_branding/)
  assert.match(migration, /CREATE TABLE app_branding_audit/)
  assert.doesNotMatch(migration, /ALTER TABLE (?:invoices|payments|businesses)/)
})

test('collectivité et noms CSV suivent également la configuration', () => {
  assert.match(collections, /branding\.organizationName/)
  assert.match(businesses, /brandingSlug\(\)/)
  assert.match(store, /export function brandingSlug/)
})
