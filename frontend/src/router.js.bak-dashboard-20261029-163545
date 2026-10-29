import { createRouter, createWebHistory } from 'vue-router'
import { auth } from './auth'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/commerces' },
    { path: '/connexion', name: 'login', component: () => import('./views/LoginView.vue'), meta: { public: true } },
    { path: '/commerces', name: 'businesses', component: () => import('./views/BusinessesView.vue') },
    { path: '/commerces/nouveau', name: 'business-new', component: () => import('./views/BusinessFormView.vue') },
    { path: '/commerces/:id/modifier', name: 'business-edit', component: () => import('./views/BusinessEditView.vue'), meta: { admin: true } },
    { path: '/factures', name: 'invoices', component: () => import('./views/InvoicesView.vue'), meta: { admin: true } },
    { path: '/annulations', name: 'cancellations', component: () => import('./views/CancellationsView.vue'), meta: { admin: true } },
  ],
})

router.beforeEach(async (to) => {
  if (!auth.loaded) await auth.fetchMe()
  if (!to.meta.public && !auth.user) return { name: 'login', query: { redirect: to.fullPath } }
  if (to.meta.admin && !auth.user?.roles?.includes('ROLE_ADMIN')) return { name: 'businesses' }
  if (to.name === 'login' && auth.user) return { name: 'businesses' }
})
