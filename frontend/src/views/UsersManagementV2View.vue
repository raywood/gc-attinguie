<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '../api'
import { auth } from '../auth'

const accounts = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const search = ref('')
const csrf = ref('')
const selected = ref(null)
const edit = ref({ first_name: '', last_name: '', phone: '', job_title: '', role: 'ROLE_AGENT' })
const audit = ref([])
const auditTotal = ref(0)
const auditPage = ref(1)
const auditTargetId = ref('')
const busy = ref(false)
const loading = ref(false)
const error = ref('')
const message = ref('')
const resetInvitation = ref(null)
const roleLabels = {
  ROLE_ADMIN: 'Administrateur', ROLE_REGISSEUR: 'Régisseur',
  ROLE_COMPTABLE: 'Comptable', ROLE_AGENT: 'Agent municipal',
}
const actionLabels = {
  user_updated: 'Profil / rôle modifié', user_suspended: 'Compte suspendu',
  user_reactivated: 'Compte réactivé', password_reset_requested: 'Réinitialisation demandée',
}
const accountPages = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)))
const auditPages = computed(() => Math.max(1, Math.ceil(auditTotal.value / 30)))
const ownEmail = computed(() => auth.user?.email || '')
const isSelf = (row) => row?.email === ownEmail.value
const roleOf = (row) => ['ROLE_ADMIN', 'ROLE_REGISSEUR', 'ROLE_COMPTABLE', 'ROLE_AGENT'].find(r => row.roles?.includes(r)) || 'ROLE_AGENT'
const activeState = (row) => row.isActive ? 'Actif' : row.invitationPending ? (row.activatedAt ? 'Réinitialisation en attente' : 'Invitation en attente') : 'Suspendu'
const invitationUrl = computed(() => resetInvitation.value ? `${location.origin}/activation#token=${encodeURIComponent(resetInvitation.value.token)}` : '')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const q = new URLSearchParams({ page: String(page.value), search: search.value.trim() })
    const data = await api.get(`/api/admin/users?${q}`)
    accounts.value = data.items || []
    total.value = data.total || 0
    pageSize.value = data.pageSize || 20
    csrf.value = data.csrf || ''
    if (selected.value) {
      const current = accounts.value.find(x => x.id === selected.value.id)
      if (current) selected.value = current
    }
  } catch (e) {
    error.value = e.message || 'Chargement des comptes impossible.'
  } finally { loading.value = false }
}

function choose(row) {
  selected.value = row
  edit.value = {
    first_name: row.firstName || '', last_name: row.lastName || '',
    phone: row.phone || '', job_title: row.jobTitle || '', role: roleOf(row),
  }
  message.value = ''
  error.value = ''
}

async function mutate(url, body, method = 'POST') {
  const response = await fetch(url, {
    method, credentials: 'same-origin',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.value },
    body: JSON.stringify(body),
  })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(data.error || `Erreur HTTP ${response.status}`)
  return data
}

async function save() {
  if (!selected.value || busy.value) return
  if (isSelf(selected.value) && edit.value.role !== roleOf(selected.value)) {
    error.value = 'Le changement de votre propre rôle est interdit.'
    return
  }
  if (edit.value.role !== roleOf(selected.value) && !confirm(`Changer le rôle de ${selected.value.email} ? Sa session devra être renouvelée.`)) return
  busy.value = true
  error.value = ''; message.value = ''
  try {
    const id = selected.value.id
    await mutate(`/api/admin/users/${id}`, edit.value, 'PATCH')
    await load()
    const current = accounts.value.find(x => x.id === id)
    if (current) choose(current)
    message.value = 'Profil enregistré. Un changement de rôle impose une nouvelle connexion au titulaire.'
    await loadAudit()
  } catch (e) { error.value = e.message }
  finally { busy.value = false }
}

async function changeStatus(row) {
  if (busy.value || isSelf(row)) return
  const active = !row.isActive
  const explanation = active
    ? `Réactiver ${row.email} ? Le compte pourra de nouveau se connecter avec son mot de passe existant.`
    : `Suspendre ${row.email} ? Ses sessions seront invalidées dès la prochaine requête API.`
  if (!confirm(explanation)) return
  busy.value = true
  error.value = ''; message.value = ''
  try {
    await mutate(`/api/admin/users/${row.id}/status`, { active })
    await load(); await loadAudit()
    message.value = active ? 'Compte réactivé.' : 'Compte suspendu.'
  } catch (e) { error.value = e.message }
  finally { busy.value = false }
}

async function resetPassword(row) {
  if (busy.value || isSelf(row)) return
  if (!confirm(`Générer un lien de réinitialisation pour ${row.email} ? Le compte sera (ou restera) désactivé jusqu'à une nouvelle activation.`)) return
  busy.value = true
  error.value = ''; message.value = ''; resetInvitation.value = null
  try {
    resetInvitation.value = await mutate(`/api/admin/users/${row.id}/reset-password`, {})
    await load(); await loadAudit()
    message.value = 'Nouveau lien créé (affichage unique). Transmettez-le au titulaire par un canal sûr.'
  } catch (e) { error.value = e.message }
  finally { busy.value = false }
}

async function copyLink() {
  if (!invitationUrl.value) return
  try { await navigator.clipboard.writeText(invitationUrl.value); message.value = 'Lien copié : à transmettre uniquement au destinataire.' }
  catch { error.value = 'Copie impossible. Sélectionnez manuellement le lien.' }
}

async function loadAudit() {
  try {
    const q = new URLSearchParams({ page: String(auditPage.value) })
    if (auditTargetId.value) q.set('targetId', auditTargetId.value)
    const data = await api.get(`/api/admin/users/audit?${q}`)
    audit.value = data.items || []
    auditTotal.value = data.total || 0
  } catch (e) { error.value = e.message || 'Historique indisponible.' }
}
function searchAccounts() { page.value = 1; selected.value = null; load() }
function pageAccounts(p) { page.value = p; selected.value = null; load() }
function filterAudit(id = '') { auditTargetId.value = id ? String(id) : ''; auditPage.value = 1; loadAudit() }
function pageAudit(p) { auditPage.value = p; loadAudit() }
function auditDetails(row) {
  const parts = []
  if (row.details?.changedFields?.length) parts.push(`Champs : ${row.details.changedFields.join(', ')}`)
  if (row.details?.roleBefore && row.details?.roleAfter) parts.push(`Rôle : ${row.details.roleBefore} → ${row.details.roleAfter}`)
  if (row.details?.activeAfter !== undefined) parts.push(row.details.activeAfter ? 'Réactivé' : 'Suspendu')
  return parts.join(' · ') || 'Action enregistrée'
}
onMounted(async () => { await load(); await loadAudit() })
</script>

<template>
  <section class="users-v2">
    <header class="row-head">
      <div><h1>Administration avancée des utilisateurs</h1>
        <RouterLink class="btn ghost" to="/roles-attributions">Attribuer les rôles (V3.1)</RouterLink>
        <RouterLink class="btn ghost" to="/roles-permissions">Rôles et permissions (V3)</RouterLink><p class="muted small">Modifier les profils, suspendre, réactiver, réinitialiser les accès et consulter le journal.</p></div>
      <RouterLink class="btn ghost" to="/utilisateurs">Création et import CSV</RouterLink>
    </header>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="message" class="card" role="status">{{ message }}</p>

    <section v-if="resetInvitation" class="card invite-panel">
      <h2>Invitation de réinitialisation — affichage unique</h2>
      <p class="muted small">{{ resetInvitation.email }} · Expire le {{ new Date(resetInvitation.expiresAt).toLocaleString('fr-FR') }}. Le lien est un secret : ne le mettez pas dans un ticket ou un dépôt Git.</p>
      <input :value="invitationUrl" readonly aria-label="Lien d'activation" @focus="$event.target.select()" />
      <div class="user-actions"><button class="btn" type="button" @click="copyLink">Copier le lien</button><button class="btn ghost" type="button" @click="resetInvitation = null">Effacer de l'écran</button></div>
    </section>

    <section class="card">
      <h2>Comptes municipaux ({{ total }})</h2>
      <form class="user-actions" @submit.prevent="searchAccounts"><input v-model="search" type="search" placeholder="Nom, prénom ou email" maxlength="100" /><button class="btn ghost">Rechercher</button></form>
      <p v-if="loading" class="muted">Chargement…</p>
      <div class="table-wrap"><table><thead><tr><th>Utilisateur</th><th>Rôle</th><th>État</th><th>Actions</th></tr></thead>
        <tbody>
          <tr v-for="row in accounts" :key="row.id">
            <td><div class="gc-admin-user"><img class="gc-admin-photo" :src="`/api/account/photo/${row.id}`" alt="" loading="lazy" @error="$event.target.style.display = 'none'" /><div><strong>{{ [row.firstName, row.lastName].filter(Boolean).join(' ') || 'Compte historique' }}</strong><div class="muted small">{{ row.email }} · #{{ row.id }}</div></div></div></td>
            <td>{{ roleLabels[roleOf(row)] }}</td>
            <td>{{ activeState(row) }}</td>
            <td><div class="user-actions">
              <button class="btn ghost" type="button" :disabled="busy" @click="choose(row)">Modifier</button>
              <button v-if="row.isActive" class="btn ghost" type="button" :disabled="busy || isSelf(row)" @click="changeStatus(row)">Suspendre</button>
              <button v-else-if="!row.invitationPending" class="btn ghost" type="button" :disabled="busy || isSelf(row)" @click="changeStatus(row)">Réactiver</button>
              <button v-if="row.isActive || row.invitationPending" class="btn ghost" type="button" :disabled="busy || isSelf(row)" @click="resetPassword(row)">Réinitialiser</button>
              <button class="btn ghost" type="button" @click="filterAudit(row.id)">Historique</button>
            </div></td>
          </tr>
          <tr v-if="!accounts.length && !loading"><td colspan="4">Aucun compte trouvé.</td></tr>
        </tbody>
      </table></div>
      <div v-if="accountPages > 1" class="user-actions"><button class="btn ghost" type="button" :disabled="page <= 1" @click="pageAccounts(page-1)">Précédent</button><span>Page {{ page }} / {{ accountPages }}</span><button class="btn ghost" type="button" :disabled="page >= accountPages" @click="pageAccounts(page+1)">Suivant</button></div>
    </section>

    <form v-if="selected" class="card edit-form" @submit.prevent="save">
      <h2>Modifier : {{ selected.email }}</h2>
      <div class="form-grid">
        <label>Prénom <input v-model.trim="edit.first_name" required maxlength="80" /></label>
        <label>Nom <input v-model.trim="edit.last_name" required maxlength="80" /></label>
        <label>Téléphone <input v-model.trim="edit.phone" maxlength="32" /></label>
        <label>Fonction <input v-model.trim="edit.job_title" maxlength="120" /></label>
        <label>Rôle <select v-model="edit.role" disabled><option v-for="(name, role) in roleLabels" :key="role" :value="role">{{ name }}</option></select></label>
      </div>
      <p class="muted small">L'email n'est pas modifiable dans cette version. Les rôles sont administrés dans la V3.1.</p>
      <div class="user-actions"><button type="submit" class="btn" :disabled="busy || !csrf">{{ busy ? 'Enregistrement…' : 'Enregistrer' }}</button><button type="button" class="btn ghost" @click="selected = null">Fermer</button></div>
    </form>

    <section class="card">
      <div class="row-head"><h2>Journal administratif ({{ auditTotal }})</h2><button type="button" class="btn ghost" @click="filterAudit()">Toutes les actions</button></div>
      <p v-if="auditTargetId" class="muted small">Filtré sur le compte #{{ auditTargetId }}</p>
      <div class="table-wrap"><table><thead><tr><th>Date</th><th>Administrateur</th><th>Utilisateur concerné</th><th>Opération</th><th>Détails</th></tr></thead>
        <tbody><tr v-for="row in audit" :key="row.id">
          <td>{{ new Date(row.createdAt).toLocaleString('fr-FR') }}</td><td>{{ row.actorEmail || `#${row.actorId ?? 'supprimé'}` }}</td>
          <td>{{ row.targetEmail || `#${row.targetId ?? 'supprimé'}` }}</td><td>{{ actionLabels[row.action] || row.action }}</td><td>{{ auditDetails(row) }}</td>
        </tr><tr v-if="!audit.length"><td colspan="5">Aucune action V2 enregistrée.</td></tr></tbody>
      </table></div>
      <div v-if="auditPages > 1" class="user-actions"><button type="button" class="btn ghost" :disabled="auditPage <= 1" @click="pageAudit(auditPage-1)">Précédent</button><span>Page {{ auditPage }} / {{ auditPages }}</span><button type="button" class="btn ghost" :disabled="auditPage >= auditPages" @click="pageAudit(auditPage+1)">Suivant</button></div>
      <p class="muted small">Le journal commence avec les opérations de la V2. Les créations et imports déjà effectués dans la V1 ne sont pas reconstitués.</p>
    </section>
  </section>
</template>

<style scoped>
.users-v2 { display:grid; gap: 1rem; }
.users-v2 .user-actions { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; margin:.4rem 0; }
.users-v2 .user-actions input { flex:1; min-width:210px; }
.users-v2 td .user-actions { flex-wrap:wrap; }
.users-v2 td .btn { font-size:.82rem; padding:.35rem .6rem; }
.users-v2 .form-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:.8rem; }
.users-v2 .form-grid label { display:grid; gap:.3rem; }
.users-v2 .invite-panel input { width:100%; max-width:100%; }
.users-v2 td { vertical-align:top; }

.gc-admin-user { display:flex; align-items:center; gap:.65rem; min-width:210px; }
.gc-admin-photo { width:42px; height:42px; object-fit:cover; border-radius:50%; flex:none; background:#e2e8f0; }
</style>
