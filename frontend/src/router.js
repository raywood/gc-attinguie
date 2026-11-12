import { refreshAbilities, can, isAdministrator } from './security/abilities'
import { createRouter, createWebHistory } from 'vue-router'
import { auth } from './auth'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    // gc-manager-link-v1 : espace réservé aux gérants.
    { path: '/mon-espace/commerces', name: 'merchant-businesses', component: () => import('./views/MerchantBusinessesView.vue'), meta: { merchant: true } },
    { path: '/mon-espace/factures', name: 'merchant-invoices', component: () => import('./views/MerchantInvoicesView.vue') },
    { path: '/catalogue/activites', name: 'gc-catalog-activities', component: () => import('./views/ActivityCatalogAdminView.vue'), meta: { admin: true } },
    { path: '/catalogue/tarifs', name: 'gc-catalog-tariffs', component: () => import('./views/TariffCatalogAdminView.vue'), meta: { admin: true } },
    { path: '/administration/personnalisation', name: 'gc-admin-branding', component: () => import('./views/AppBrandingAdminView.vue'), meta: { admin: true } },
    { path: '/mon-profil', name: 'my-profile', component: () => import('./views/MyProfileView.vue') },
    { path: '/roles-attributions', name: 'roles-assignments-v31', component: () => import('./views/UserRoleAssignmentsView.vue'), meta: { admin: true } },
    { path: '/acces-refuse', name: 'access-denied-v31', component: () => import('./views/AccessDeniedView.vue') },
    { path: '/recouvrement', name: 'collections', component: () => import('./views/CollectionsView.vue'), meta: { permission: 'recouvrement.read' } },

    { path: '/roles-permissions', name: 'roles-permissions-v3', component: () => import('./views/RolesPermissionsView.vue'), meta: { admin: true } },
    { path: '/utilisateurs/administration', name: 'admin-users-v2', component: () => import('./views/UsersManagementView.vue'), meta: { admin: true } },
    { path: '/activation', name: 'account-activation', component: () => import('./views/AccountActivationView.vue'), meta: { public: true } },
    { path: '/utilisateurs', name: 'admin-users', component: () => import('./views/UsersAdminView.vue'), meta: { admin: true } },
    { path: '/', redirect: '/commerces' },
    { path: '/connexion', name: 'login', component: () => import('./views/LoginView.vue'), meta: { public: true } },
    { path: '/verification/c/:token', name: 'business-verification', component: () => import('./views/BusinessVerificationView.vue'), meta: { public: true } },
    { path: '/commerces/:id/finances', name: 'business-finances', component: () => import('./views/BusinessFinanceView.vue'), meta: { permission: 'finance.read' } },
    { path: '/commerces/:id/qr', name: 'business-qr', component: () => import('./views/BusinessQrView.vue'), meta: { permission: 'commerces.read' } },
    { path: '/commerces', name: 'businesses', component: () => import('./views/BusinessesView.vue') },
    { path: '/commerces/nouveau', name: 'business-new', component: () => import('./views/BusinessFormView.vue') },
    { path: '/commerces/:id/modifier', name: 'business-edit', component: () => import('./views/BusinessEditView.vue'), meta: { admin: true } },
    { path: '/factures', name: 'invoices', component: () => import('./views/InvoicesView.vue'), meta: { admin: true } },
    { path: '/annulations', name: 'cancellations', component: () => import('./views/CancellationsView.vue'), meta: { admin: true } },
    { path: '/tableau-de-bord', name: 'financial-dashboard', component: () => import('./views/FinancialDashboardView.vue'), meta: { permission: 'finance.read' } },
  ],
})

router.beforeEach(async (to) => {
  if (!auth.loaded) await auth.fetchMe()
  if (!to.meta.public && !auth.user) return { name: 'login', query: { redirect: to.fullPath } }
  if (to.meta.admin && !auth.user?.roles?.includes('ROLE_ADMIN')) return { name: 'businesses' }
  if (to.name === 'login' && auth.user) return { name: auth.user?.roles?.includes('ROLE_GERANT') ? 'merchant-businesses' : 'businesses' }
  if (to.name === 'businesses' && auth.user?.roles?.includes('ROLE_GERANT') &&
      !['ROLE_ADMIN', 'ROLE_AGENT', 'ROLE_REGISSEUR', 'ROLE_COMPTABLE'].some(r => auth.user?.roles?.includes(r)))
    return { name: 'merchant-businesses' }
})


// gc-rbac-v31-guard : contrôle d'interface seulement ; le serveur est la source de vérité.
router.beforeEach(async (to) => {
  if (to.meta.public || to.path.startsWith('/mon-espace/') || to.path === '/acces-refuse') return
  if (!auth.user) return
  const ready = await refreshAbilities() // relit les droits à chaque changement de page
  if (!ready) return '/acces-refuse'
  const path = to.path
  const required = path === '/commerces/nouveau' || /^\/commerces\/[^/]+\/(?:modifier|edit)$/.test(path) ? 'commerces.write'
    : path.startsWith('/commerces') ? 'commerces.read'
    : path.startsWith('/tableau-de-bord') ? 'finance.read'
    : path.startsWith('/recouvrement') ? 'recouvrement.read'
    : path.startsWith('/factures') ? 'factures.read'
    : path.startsWith('/encaissements') ? 'encaissements.read' : null
  const adminPage = to.meta.admin || path.startsWith('/utilisateurs') || path.startsWith('/roles-permissions') || path.startsWith('/roles-attributions') || path.startsWith('/annulations')
  if (adminPage && !isAdministrator()) return '/acces-refuse'
  if (required && !can(required)) return '/acces-refuse'
})
