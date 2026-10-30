<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { auth } from '../auth'
import { api } from '../api'

const users = ref([])
const roles = ref([])
const csrf = ref('')
const search = ref('')
const page = ref(1)
const total = ref(0)
const selected = ref(null)
const choice = ref([])
const busy = ref(false)
const error = ref('')
const info = ref('')
const upload = ref(null)
const preview = ref(null)
const pages = computed(() => Math.max(1, Math.ceil(total.value / 20)))
const isSelf = (u) => u?.email === auth.user?.email

async function load() {
  error.value = ''
  try {
    const data = await api.get(`/api/admin/rbac/users?page=${page.value}&search=${encodeURIComponent(search.value.trim())}`)
    users.value = data.items || []
    roles.value = data.roles || []
    total.value = data.total || 0
    csrf.value = data.csrf || ''
    if (selected.value) {
      const fresh = users.value.find(x => x.id === selected.value.id)
      if (fresh) { selected.value = fresh; choice.value = [...fresh.roles] }
    }
  } catch (e) { error.value = e.message || 'Chargement impossible.' }
}
function choose(user) {
  selected.value = user
  choice.value = [...user.roles].filter(r => r !== 'ROLE_USER')
  error.value = ''; info.value = ''
}
async function send(url, payload, multipart = false) {
  const response = await fetch(url, {
    method: multipart ? 'POST' : 'PUT', credentials: 'same-origin',
    headers: multipart ? { 'X-CSRF-Token': csrf.value, Accept: 'application/json' }
      : { 'X-CSRF-Token': csrf.value, Accept: 'application/json', 'Content-Type': 'application/json' },
    body: multipart ? payload : JSON.stringify(payload),
  })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`)
  return data
}
async function save() {
  if (!selected.value || isSelf(selected.value) || choice.value.length === 0 || busy.value) return
  if (!confirm(`Remplacer les rôles de ${selected.value.email} par : ${choice.value.join(', ')} ? Sa session en cours devra être renouvelée.`)) return
  busy.value = true; error.value = ''; info.value = ''
  try {
    await send(`/api/admin/rbac/users/${selected.value.id}/roles`, { roles: choice.value })
    info.value = 'Attribution enregistrée et tracée dans le journal administratif.'
    await load()
  } catch (e) { error.value = e.message } finally { busy.value = false }
}
function selectFile(event) { upload.value = event.target.files?.[0] || null; preview.value = null }
async function bulk(simulate) {
  if (!upload.value || busy.value || (!simulate && !preview.value)) return
  if (!simulate && !confirm(`Appliquer les rôles à ${preview.value.toUpdate} compte(s) ?`)) return
  busy.value = true; error.value = ''; info.value = ''
  try {
    const body = new FormData(); body.append('file', upload.value)
    const result = await send(`/api/admin/rbac/users/import${simulate ? '?dry_run=1' : ''}`, body, true)
    if (simulate) { preview.value = result; info.value = `Simulation : ${result.toUpdate} modification(s), ${result.skipped} inchangé(s).` }
    else { preview.value = null; upload.value = null; info.value = `Import terminé : ${result.toUpdate} compte(s) mis à jour.`; await load() }
  } catch (e) { error.value = e.message; preview.value = null } finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <section class="roles-admin">
    <div class="head">
      <div><h1>Attribution des rôles — V3.1</h1><p class="muted">Assigner les fonctions municipales aux comptes existants, individuellement ou par CSV.</p></div>
      <RouterLink class="btn ghost" to="/roles-permissions">Catalogue V3</RouterLink>
    </div>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="info" class="success" role="status">{{ info }}</p>
    <form class="card filter" @submit.prevent="page=1;load()">
      <label>Rechercher un agent<input v-model="search" maxlength="100" placeholder="Email, prénom, nom" /></label>
      <button class="btn" type="submit">Rechercher</button>
    </form>
    <div class="layout">
      <section class="card"><h2>Comptes ({{ total }})</h2>
        <div class="items">
          <button v-for="u in users" :key="u.id" class="row" type="button" @click="choose(u)">
            <strong>{{ u.email }}</strong><small>{{ u.name || 'Profil non renseigné' }} · {{ u.isActive ? 'Actif' : 'Inactif' }} · {{ u.roles.join(', ') }}</small>
          </button>
        </div>
        <div class="pages"><button type="button" class="btn ghost" :disabled="page<=1" @click="page--;load()">Précédent</button><span>{{ page }} / {{ pages }}</span><button type="button" class="btn ghost" :disabled="page>=pages" @click="page++;load()">Suivant</button></div>
      </section>
      <section class="card"><h2>Rôles du compte</h2>
        <template v-if="selected"><p><strong>{{ selected.email }}</strong></p>
          <p v-if="isSelf(selected)" class="muted">La modification de tes propres rôles est bloquée pour protéger l’administration.</p>
          <div class="role-list">
            <label v-for="r in roles" :key="r.code" class="check"><input v-model="choice" type="checkbox" :value="r.code" :disabled="busy || isSelf(selected)" /> {{ r.label }} <small>({{ r.code }})</small></label>
          </div>
          <button class="btn" type="button" :disabled="busy || isSelf(selected) || !choice.length" @click="save">Enregistrer les attributions</button>
        </template><p v-else class="muted">Sélectionne un compte dans la liste.</p>
      </section>
    </div>
    <section class="card"><h2>Attribution en masse par CSV</h2>
      <p>CSV UTF-8 : <code>email;roles</code> avec plusieurs rôles séparés par <code>|</code>. Maximum 100 lignes. Par sécurité, l’import refuse les comptes administrateurs et l’attribution de <code>ROLE_ADMIN</code>.</p>
      <a href="/examples/attributions-roles-v31.csv" download>Modèle CSV</a>
      <div class="filter"><input type="file" accept=".csv,text/csv" @change="selectFile" /><button class="btn ghost" type="button" :disabled="busy || !upload" @click="bulk(true)">Simuler</button><button class="btn" type="button" :disabled="busy || !preview || preview.toUpdate<1" @click="bulk(false)">Confirmer l’import</button></div>
      <p v-if="preview" class="muted">{{ preview.toUpdate }} modification(s) prévue(s), {{ preview.skipped }} inchangé(s).</p>
    </section>
  </section>
</template>
<style scoped>
.roles-admin{display:grid;gap:1rem}.head{display:flex;justify-content:space-between;flex-wrap:wrap;align-items:center}.filter{display:flex;gap:1rem;align-items:end;flex-wrap:wrap}.filter label{flex:1;min-width:220px}.layout{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:1rem}.items,.role-list{display:grid;gap:.6rem;max-height:480px;overflow:auto}.role-list{margin:1rem 0}.check{display:flex;gap:.4rem;align-items:center}.check small{color:var(--muted, #666)}.row{display:grid;text-align:left;gap:.25rem;background:transparent;color:inherit;border:1px solid #8885;padding:.65rem;border-radius:.5rem;cursor:pointer}.row small{overflow-wrap:anywhere}.pages{display:flex;justify-content:space-between;gap:.5rem;align-items:center;margin-top:.8rem}.error{color:#a52a2a}.success{color:#157447}
</style>
