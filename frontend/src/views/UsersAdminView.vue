<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api'

const userList = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const search = ref('')
const csrf = ref('')
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const info = ref('')
const form = ref({ email: '', first_name: '', last_name: '', phone: '', job_title: '', role: 'ROLE_AGENT' })
const csvFile = ref(null)
const preview = ref(null)
const invitations = ref([])
const roleNames = {
  ROLE_ADMIN: 'Administrateur', ROLE_AGENT: 'Agent municipal',
  ROLE_REGISSEUR: 'Régisseur', ROLE_COMPTABLE: 'Comptable',
}
const pages = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)))
const inviteLink = (token) => `${window.location.origin}/activation#token=${encodeURIComponent(token)}`

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({ page: String(page.value), search: search.value.trim() })
    const data = await api.get(`/api/admin/users?${params}`)
    userList.value = data.items
    total.value = data.total
    pageSize.value = data.pageSize
    csrf.value = data.csrf
  } catch (e) {
    error.value = e.message || 'Chargement impossible.'
  } finally {
    loading.value = false
  }
}
async function send(url, body, multipart = false) {
  const headers = { Accept: 'application/json', 'X-CSRF-Token': csrf.value }
  if (!multipart) headers['Content-Type'] = 'application/json'
  const res = await fetch(url, {
    method: 'POST', credentials: 'same-origin', headers,
    body: multipart ? body : JSON.stringify(body),
  })
  const payload = await res.json().catch(() => ({}))
  if (!res.ok) {
    if (payload.errors?.length) {
      throw new Error(payload.errors.slice(0, 8).map(x => `Ligne ${x.line} : ${x.message}`).join('\n'))
    }
    throw new Error(payload.error || `Erreur HTTP ${res.status}`)
  }
  return payload
}
function keepInvites(items) {
  invitations.value = [...items, ...invitations.value]
  info.value = `${items.length} invitation(s) générée(s). Copiez ou exportez-les maintenant : le serveur n’enregistre pas les liens en clair.`
}
async function createOne() {
  if (busy.value) return
  if (form.value.role === 'ROLE_ADMIN' && !window.confirm('Créer un nouveau compte administrateur ? Cette personne disposera des privilèges administratifs de l’application.')) return
  busy.value = true
  error.value = ''
  try {
    const result = await send('/api/admin/users', form.value)
    keepInvites([result.created])
    form.value = { email: '', first_name: '', last_name: '', phone: '', job_title: '', role: 'ROLE_AGENT' }
    page.value = 1
    await load()
  } catch (e) { error.value = e.message }
  finally { busy.value = false }
}
function pickFile(event) {
  csvFile.value = event.target.files?.[0] || null
  preview.value = null
}
async function importFile(dryRun) {
  if (!csvFile.value || busy.value) return
  if (!dryRun && (!preview.value || preview.value.toCreate === 0)) return
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    const body = new FormData()
    body.set('file', csvFile.value)
    const data = await send(`/api/admin/users/import${dryRun ? '?dry_run=1' : ''}`, body, true)
    if (dryRun) {
      preview.value = data
      info.value = `Simulation : ${data.toCreate} compte(s) à créer, ${data.skipped} adresse(s) déjà existante(s) ignorée(s).`
    } else {
      keepInvites(data.created)
      preview.value = null
      csvFile.value = null
      page.value = 1
      await load()
    }
  } catch (e) {
    preview.value = null
    error.value = e.message
  } finally { busy.value = false }
}
async function reinvite(user) {
  if (busy.value || !window.confirm(`Générer un nouveau lien pour ${user.email} ? L’ancien lien sera invalidé.`)) return
  busy.value = true
  error.value = ''
  try {
    const result = await send(`/api/admin/users/${user.id}/reinvite`, {})
    keepInvites([result])
    await load()
  } catch (e) { error.value = e.message }
  finally { busy.value = false }
}
async function copyInvitation(item) {
  try { await navigator.clipboard.writeText(inviteLink(item.token)); info.value = 'Lien copié. Transmettez-le uniquement à son destinataire.' }
  catch { error.value = 'Copie impossible. Utilisez l’export CSV sécurisé.' }
}
function exportInvitations() {
  if (!invitations.value.length) return
  const csvCell = (v) => `"${String(v).replaceAll('"', '""')}"`
  const lines = ['email;activation_url;expiration_utc', ...invitations.value.map(x => [x.email, inviteLink(x.token), x.expiresAt].map(csvCell).join(';'))]
  const blob = new Blob(['\ufeff', lines.join('\r\n')], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'invitations-utilisateurs-gc-attinguie.csv'
  a.click()
  URL.revokeObjectURL(url)
}
function clearInvitations() { invitations.value = []; info.value = 'Les liens d’invitation ont été effacés de cet écran.' }
function searchUsers() { page.value = 1; load() }
function goTo(n) { page.value = n; load() }
onMounted(load)
</script>

<template>
  <section class="users-admin">
    <div class="row-head"><div><h1>Gestion des profils utilisateurs</h1><RouterLink to="/utilisateurs/administration" class="btn ghost">Administration avancée (V2)</RouterLink><p class="muted small">Comptes municipaux · Création individuelle ou import CSV · Réservé aux administrateurs</p></div></div>
    <p v-if="error" class="error" role="alert" style="white-space:pre-line">{{ error }}</p>
    <p v-if="info" role="status" class="card">{{ info }}</p>

    <div class="user-profiles-grid">
      <form class="card" @submit.prevent="createOne">
        <h2>Créer un utilisateur</h2>
        <label>Prénom <input v-model.trim="form.first_name" required maxlength="80" autocomplete="off" /></label>
        <label>Nom <input v-model.trim="form.last_name" required maxlength="80" autocomplete="off" /></label>
        <label>Adresse e-mail <input v-model.trim="form.email" required type="email" maxlength="180" autocomplete="off" /></label>
        <label>Téléphone (facultatif) <input v-model.trim="form.phone" maxlength="32" autocomplete="off" /></label>
        <label>Fonction (facultative) <input v-model.trim="form.job_title" maxlength="120" autocomplete="off" /></label>
        <label>Profil / rôle
          <select v-model="form.role" required>
            <option v-for="(label, role) in roleNames" :key="role" :value="role">{{ label }}</option>
          </select>
        </label>
        <p class="muted small">Le compte restera inactif jusqu’à la définition du mot de passe par son titulaire.</p>
        <button class="btn" type="submit" :disabled="busy || !csrf">Créer et générer l’invitation</button>
      </form>
      <section class="card">
        <h2>Importer plusieurs utilisateurs</h2>
        <p class="muted small">CSV UTF-8, séparateur « ; » ou « , », jusqu’à 200 lignes et 1 Mo. Les doublons déjà enregistrés sont ignorés, jamais remplacés. Les administrateurs ne peuvent pas être créés par import de masse.</p>
        <div class="muted small"><strong>Colonnes :</strong> email;first_name;last_name;phone;job_title;role</div>
        <label>Fichier CSV <input type="file" accept=".csv,text/csv" @change="pickFile" /></label>
        <div class="user-actions">
          <button class="btn ghost" type="button" :disabled="!csvFile || busy || !csrf" @click="importFile(true)">Simuler l’import</button>
          <button class="btn" type="button" :disabled="!preview || preview.toCreate === 0 || busy" @click="importFile(false)">Confirmer l’import</button>
        </div>
        <p v-if="preview" class="muted small">{{ preview.toCreate }} création(s) prévue(s), {{ preview.skipped }} doublon(s) ignoré(s).</p>
        <p class="muted small">Aucun mot de passe n’est accepté dans les fichiers CSV. Chaque personne reçoit un lien d’activation valable sept jours.</p>
      </section>
    </div>

    <section v-if="invitations.length" class="card">
      <h2>Invitations à transmettre (affichage unique)</h2>
      <p class="muted small">Ces liens donnent accès à l’activation des comptes. Téléchargez le CSV et conservez-le en lieu sûr, puis transmettez chaque lien uniquement à son destinataire.</p>
      <div class="user-actions">
        <button type="button" class="btn" @click="exportInvitations">Exporter les {{ invitations.length }} invitations (CSV)</button>
        <button type="button" class="btn ghost" @click="clearInvitations">Effacer de l’écran</button>
      </div>
      <div class="table-wrap"><table><thead><tr><th>Adresse e-mail</th><th>Expiration</th><th>Action</th></tr></thead>
        <tbody><tr v-for="x in invitations" :key="x.email + x.token"><td>{{ x.email }}</td><td>{{ new Date(x.expiresAt).toLocaleString('fr-FR') }}</td><td><button type="button" class="btn ghost" @click="copyInvitation(x)">Copier le lien</button></td></tr></tbody>
      </table></div>
    </section>

    <section class="card">
      <div class="row-head"><h2>Comptes enregistrés ({{ total }})</h2></div>
      <form class="user-actions" @submit.prevent="searchUsers">
        <input v-model="search" type="search" maxlength="100" placeholder="Rechercher nom ou e-mail" />
        <button type="submit" class="btn ghost">Rechercher</button>
      </form>
      <p v-if="loading" class="muted">Chargement…</p>
      <div class="table-wrap"><table><thead><tr><th>Agent</th><th>E-mail</th><th>Rôle</th><th>État</th><th>Action</th></tr></thead>
        <tbody><tr v-for="u in userList" :key="u.id">
          <td><div class="gc-list-user"><img class="gc-list-photo" :src="`/api/account/photo/${u.id}`" alt="" loading="lazy" @error="$event.target.style.display = 'none'" /><div>{{ [u.firstName, u.lastName].filter(Boolean).join(' ') || 'Compte existant' }}<div class="muted small">{{ u.jobTitle || '' }}</div></div></div></td>
          <td>{{ u.email }}</td><td>{{ u.roles.map(r => roleNames[r] || r).join(', ') }}</td>
          <td>{{ u.isActive ? 'Actif' : 'En attente d’activation' }}</td>
          <td><button v-if="!u.isActive && u.firstName && !u.activatedAt" class="btn ghost" type="button" :disabled="busy" @click="reinvite(u)">Renouveler l’invitation</button><span v-else class="muted">—</span></td>
        </tr><tr v-if="!userList.length && !loading"><td colspan="5">Aucun compte trouvé.</td></tr></tbody>
      </table></div>
      <div v-if="pages > 1" class="user-actions"><button class="btn ghost" :disabled="page <= 1" @click="goTo(page-1)">Précédent</button><span>Page {{ page }} / {{ pages }}</span><button class="btn ghost" :disabled="page >= pages" @click="goTo(page+1)">Suivant</button></div>
    </section>
  </section>
</template>

<style scoped>
.user-profiles-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr)); gap: 1rem; margin-bottom: 1rem; }
.user-profiles-grid .card { display: grid; gap: .65rem; align-content: start; }
.user-profiles-grid label { display: grid; gap: .3rem; }
.user-actions { display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; margin: .75rem 0; }
.user-actions input { min-width: 240px; flex: 1; }
.users-admin section.card { margin-bottom: 1rem; }

.gc-list-user { display:flex; align-items:center; gap:.6rem; min-width:170px; }
.gc-list-photo { width:38px; height:38px; object-fit:cover; border-radius:50%; flex:none; background:#e2e8f0; }
</style>
