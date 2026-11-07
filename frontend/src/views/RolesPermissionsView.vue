<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '../api'

const roles = ref([])
const permissions = ref([])
const csrf = ref('')
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const info = ref('')
const roleForm = ref({ code: '', label: '', description: '', permissions: [] })
const permissionForm = ref({ code: '', label: '', module: '', description: '' })
const selectedRole = ref(null)
const selectedPermissions = ref([])
const roleSearch = ref('')
const permissionSearch = ref('')
const upload = ref({ role: null, permission: null })
const preview = ref({ role: null, permission: null })
const recentResult = ref(null)

const filteredRoles = computed(() => roles.value.filter(r =>
  [r.code, r.label, r.description].join(' ').toLowerCase().includes(roleSearch.value.toLowerCase())
))
const filteredPermissions = computed(() => permissions.value.filter(p =>
  [p.code, p.label, p.module].join(' ').toLowerCase().includes(permissionSearch.value.toLowerCase())
))
const modules = computed(() => Array.from(new Set(permissions.value.map(p => p.module))).sort())

async function load() {
  loading.value = true
  try {
    const data = await api.get('/api/admin/rbac')
    roles.value = data.roles || []
    permissions.value = data.permissions || []
    csrf.value = data.csrf || ''
    if (selectedRole.value) {
      const match = roles.value.find(r => r.id === selectedRole.value.id)
      if (match) selectedRole.value = match
    }
  } catch (e) { error.value = e.message || 'Impossible de charger le catalogue.' }
  finally { loading.value = false }
}

async function send(url, body, method = 'POST', multipart = false) {
  const result = await fetch(url, {
    method, credentials: 'same-origin',
    headers: multipart ? { Accept: 'application/json', 'X-CSRF-Token': csrf.value }
      : { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.value },
    body: multipart ? body : JSON.stringify(body),
  })
  const json = await result.json().catch(() => ({}))
  if (!result.ok) {
    const details = Array.isArray(json.errors) ? json.errors.map(e => `Ligne ${e.line} : ${e.message}`).join('\n') : ''
    throw new Error([json.error || `Erreur HTTP ${result.status}`, details].filter(Boolean).join('\n'))
  }
  return json
}

async function createPermission() {
  if (busy.value) return
  busy.value = true; error.value = ''; info.value = ''
  try {
    await send('/api/admin/rbac/permissions', permissionForm.value)
    permissionForm.value = { code: '', label: '', module: '', description: '' }
    info.value = 'Permission créée dans le catalogue.'
    await load()
  } catch (e) { error.value = e.message }
  finally { busy.value = false }
}

async function createRole() {
  if (busy.value) return
  busy.value = true; error.value = ''; info.value = ''
  try {
    await send('/api/admin/rbac/roles', roleForm.value)
    roleForm.value = { code: '', label: '', description: '', permissions: [] }
    info.value = 'Rôle créé dans le catalogue, avec ses permissions sélectionnées.'
    await load()
  } catch (e) { error.value = e.message }
  finally { busy.value = false }
}

function chooseRole(role) {
  selectedRole.value = role
  selectedPermissions.value = [...role.permissions]
  error.value = ''; info.value = ''
}
async function saveRolePermissions() {
  if (busy.value || !selectedRole.value) return
  if (!window.confirm(`Remplacer les permissions associées à ${selectedRole.value.code} dans le catalogue ? Les accès aux API raccordées seront modifiés immédiatement pour les utilisateurs concernés.`)) return
  busy.value = true; error.value = ''; info.value = ''
  try {
    await send(`/api/admin/rbac/roles/${selectedRole.value.id}/permissions`, { permissions: selectedPermissions.value }, 'PUT')
    info.value = 'Associations rôle / permissions enregistrées.'
    await load()
  } catch (e) { error.value = e.message }
  finally { busy.value = false }
}

function pickFile(type, event) {
  upload.value[type] = event.target.files?.[0] || null
  preview.value[type] = null
  recentResult.value = null
}

async function importCsv(type, simulate) {
  const file = upload.value[type]
  if (!file || busy.value || (!simulate && !preview.value[type])) return
  busy.value = true; error.value = ''; info.value = ''; recentResult.value = null
  try {
    const form = new FormData()
    form.append('file', file)
    const segment = type === 'role' ? 'roles' : 'permissions'
    const result = await send(`/api/admin/rbac/${segment}/import${simulate ? '?dry_run=1' : ''}`, form, 'POST', true)
    if (simulate) {
      preview.value[type] = result
      info.value = `Simulation ${segment} : ${result.toCreate} création(s), ${result.skipped} code(s) déjà présents.`
    } else {
      recentResult.value = result
      upload.value[type] = null
      preview.value[type] = null
      info.value = `Import terminé : ${result.createdCount} création(s), ${result.skipped} existant(s) conservé(s).`
      await load()
    }
  } catch (e) { preview.value[type] = null; error.value = e.message }
  finally { busy.value = false }
}

onMounted(load)
</script>

<template>
  <main class="rbac-v3">
    <header class="head">
      <div>
        <h1>Rôles et permissions</h1>
        <RouterLink class="v3-btn secondary" to="/roles-attributions">Attribuer les rôles</RouterLink>
        <p class="sub">Création individuelle, imports CSV, associations et consultation du catalogue municipal.</p>
      </div>
      <RouterLink class="v3-btn secondary" to="/utilisateurs/administration">← Utilisateurs</RouterLink>
    </header>

    <div class="notice"><strong>Catalogue + raccordement.</strong> Les permissions raccordées par la sont maintenant appliquées aux API municipales. Les autres permissions du catalogue restent déclaratives tant qu’aucun contrôleur ne les utilise.</div>
    <p v-if="error" class="alert error" role="alert">{{ error }}</p>
    <p v-if="info" class="alert success" role="status">{{ info }}</p>
    <div class="metrics">
      <div class="metric"><span>Rôles</span><strong>{{ roles.length }}</strong></div>
      <div class="metric"><span>Permissions</span><strong>{{ permissions.length }}</strong></div>
      <div class="metric"><span>Modules répertoriés</span><strong>{{ modules.length }}</strong></div>
    </div>

    <div class="v3-grid">
      <form class="v3-panel" @submit.prevent="createPermission">
        <h2>Nouvelle permission</h2>
        <label>Code (ex. commerces.read)<input v-model.trim="permissionForm.code" required maxlength="100" placeholder="commerces.read" pattern="[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+" /></label>
        <label>Libellé<input v-model.trim="permissionForm.label" required maxlength="120" placeholder="Consulter les commerces" /></label>
        <label>Module<input v-model.trim="permissionForm.module" required maxlength="60" placeholder="commerces" pattern="[a-z][a-z0-9_]*" /></label>
        <label>Description<textarea v-model.trim="permissionForm.description" maxlength="500" rows="2" /></label>
        <button class="v3-btn" :disabled="busy || !csrf" type="submit">Créer la permission</button>
      </form>

      <form class="v3-panel" @submit.prevent="createRole">
        <h2>Nouveau rôle</h2>
        <label>Code (ex. ROLE_CONTROLEUR)<input v-model.trim="roleForm.code" required maxlength="64" placeholder="ROLE_CONTROLEUR" /></label>
        <label>Libellé<input v-model.trim="roleForm.label" required maxlength="120" placeholder="Contrôleur municipal" /></label>
        <label>Description<textarea v-model.trim="roleForm.description" maxlength="500" rows="2" /></label>

        <div class="permissions-card">
          <h3 class="permissions-title">
            Permissions associées (facultatif)
          </h3>

          <div class="permissions-table">
            <!-- En-tête -->
            <div class="permissions-header">
              <span>Choix</span>
              <span>Permission</span>
              <span>Code</span>
            </div>

            <!-- Liste des permissions -->
            <label
              v-for="p in permissions"
              :key="p.code"
              class="permission-row"
            >
              <input
                v-model="selectedPermissions"
                type="checkbox"
                :value="p.code"
              />

              <span class="permission-name">
                {{ p.label }}
              </span>

              <small class="permission-code">
                {{ p.code }}
              </small>
            </label>
          </div>
        </div>

        <button class="v3-btn" :disabled="busy || !csrf" type="submit">Créer le rôle</button>
      </form>
    </div>

    <section class="v3-panel">
      <h2>Imports en masse (CSV UTF-8)</h2>
      <p class="sub">Importe d'abord les permissions, puis les rôles. Maximum 500 lignes et 2 Mo par fichier. Les codes existants sont ignorés, jamais modifiés. Si une ligne est invalide, aucun élément du lot n'est créé.</p>
      <div class="v3-grid">
        <div class="import-box">
          <h3>Importer des permissions</h3>
          <p class="sub">Colonnes : <code>code;label;module;description</code></p>
          <a href="/examples/permissions-v3-exemple.csv" download>Télécharger un modèle CSV</a>
          <label>Fichier CSV<input type="file" accept=".csv,text/csv" @change="pickFile('permission', $event)" /></label>
          <div class="actions">
            <button class="v3-btn secondary" :disabled="busy || !csrf || !upload.permission" @click="importCsv('permission', true)">Simuler</button>
            <button class="v3-btn" :disabled="busy || !preview.permission || preview.permission.toCreate < 1 || !upload.permission" @click="importCsv('permission', false)">Confirmer</button>
          </div>
          <p v-if="preview.permission" class="sub">À créer : {{ preview.permission.toCreate }} · Déjà présents : {{ preview.permission.skipped }}</p>
        </div>
        <div class="import-box">
          <h3>Importer des rôles</h3>
          <p class="sub">Colonnes : <code>code;label;description;permissions</code>. Plusieurs permissions sont séparées par <code>|</code>.</p>
          <a href="/examples/roles-v3-exemple.csv" download>Télécharger un modèle CSV</a>
          <label>Fichier CSV<input type="file" accept=".csv,text/csv" @change="pickFile('role', $event)" /></label>
          <div class="actions">
            <button class="v3-btn secondary" :disabled="busy || !csrf || !upload.role" @click="importCsv('role', true)">Simuler</button>
            <button class="v3-btn" :disabled="busy || !preview.role || preview.role.toCreate < 1 || !upload.role" @click="importCsv('role', false)">Confirmer</button>
          </div>
          <p v-if="preview.role" class="sub">À créer : {{ preview.role.toCreate }} · Déjà présents : {{ preview.role.skipped }}</p>
        </div>
      </div>
      <p v-if="recentResult" class="sub">Dernier lot : {{ recentResult.codes.join(', ') }}</p>
    </section>

    <div class="v3-grid">
      <section class="v3-panel">
        <h2>Catalogue des rôles</h2>
        <label>Rechercher<input v-model.trim="roleSearch" placeholder="Code, libellé..." /></label>
        <div class="list" v-if="filteredRoles.length">
          <button v-for="r in filteredRoles" :key="r.id" class="list-row" :class="{ chosen: selectedRole?.id === r.id }" @click="chooseRole(r)">
            <span><strong>{{ r.label }}</strong><small>{{ r.code }}</small></span>
            <span class="pill">{{ r.permissions.length }} perm. <small v-if="r.isSystem">· Système</small></span>
          </button>
        </div>
        <p v-else class="sub">Aucun rôle.</p>
      </section>

      <section class="v3-panel">
        <h2>Association rôle / permissions</h2>
        <template v-if="selectedRole">
          <p><strong>{{ selectedRole.label }}</strong> <small>({{ selectedRole.code }})</small></p>
          <p class="sub">Sélectionne les permissions et enregistre les associations dans le catalogue. Cela n'active pas leur contrôle dans l'application.</p>
          <fieldset><legend>Permissions du rôle</legend>
            <div class="checklist tall">
              <label v-for="p in permissions" :key="p.id" class="check"><input v-model="selectedPermissions" type="checkbox" :value="p.code" /><span>{{ p.label }} <small>({{ p.code }})</small></span></label>
              <p v-if="!permissions.length" class="sub">Aucune permission disponible.</p>
            </div>
          </fieldset>
          <button class="v3-btn" :disabled="busy || !csrf" @click="saveRolePermissions">Enregistrer les associations</button>
        </template>
        <p v-else class="sub">Clique sur un rôle à gauche pour consulter ou modifier ses permissions.</p>
      </section>
    </div>

    <section class="v3-panel">
      <h2>Catalogue des permissions</h2>
      <label>Rechercher<input v-model.trim="permissionSearch" placeholder="Code, module..." /></label>
      <div class="perm-grid">
        <div v-for="p in filteredPermissions" :key="p.id" class="perm-item">
          <small class="module">{{ p.module }}</small>
          <strong>{{ p.label }}</strong>
          <code>{{ p.code }}</code>
          <p v-if="p.description">{{ p.description }}</p>
        </div>
      </div>
      <p v-if="!filteredPermissions.length" class="sub">Aucune permission.</p>
    </section>
  </main>
</template>

<style scoped>
.rbac-v3 { max-width: 1250px; margin: 0 auto; padding: 1rem; display: grid; gap: 1rem; }
.head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
h1 { margin: 0; font-size: 1.65rem; } h2 { margin: 0 0 1rem; font-size: 1.2rem; } h3 { margin: 0 0 .6rem; font-size: 1rem; }
.sub, small { color: #6b7280; } .sub { margin: .2rem 0 1rem; font-size: .93rem; } code { word-break: break-word; }
.notice { border-left: 4px solid #c08416; padding: 1rem; background: #fff8e8; color: #62410a; border-radius: 6px; }
.alert { padding: .8rem; white-space: pre-line; border-radius: 6px; } .error { background: #fee2e2; color: #881337; } .success { background: #dcfce7; color: #166534; }
.metrics, .v3-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; } .metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.metric, .v3-panel { background: var(--card-bg, #fff); border: 1px solid #d1d5db; border-radius: 12px; padding: 1rem; color: var(--text-color, #111827); }
.metric { display: grid; gap: .2rem; } .metric strong { font-size: 1.5rem; }
.v3-panel, form { min-width: 0; }
label { display: grid; gap: .3rem; margin-bottom: .8rem; font-weight: 500; }
input:not([type=checkbox]), textarea { box-sizing: border-box; width: 100%; padding: .6rem .7rem; border-radius: 6px; border: 1px solid #a9b1bc; font: inherit; background: #fff; color: #111827; }
fieldset { border: 1px solid #d1d5db; border-radius: 8px; margin: .8rem 0; } legend { font-weight: 600; padding: 0 .35rem; }
.checklist { max-height: 180px; overflow-y: auto; padding: .3rem; } .checklist.tall { max-height: 300px; }
.check { display: flex; align-items: flex-start; gap: .5rem; font-size: .88rem; font-weight: 400; } .check input { margin-top: .2rem; }
.v3-btn { display: inline-block; color: #fff; background: #1d4ed8; padding: .65rem .9rem; border: 1px solid #1d4ed8; border-radius: 7px; font: inherit; font-size: .88rem; cursor: pointer; text-decoration: none; }
.v3-btn.secondary { color: #1e40af; background: transparent; border-color: #93c5fd; }
.v3-btn:disabled { opacity: .45; cursor: not-allowed; } .actions { display: flex; gap: .55rem; flex-wrap: wrap; }
.import-box { border: 1px solid #e5e7eb; border-radius: 9px; padding: .8rem; min-width: 0; }
.list { display: grid; gap: .4rem; max-height: 360px; overflow-y: auto; }
.list-row { display: flex; text-align: left; justify-content: space-between; gap: .4rem; background: transparent; color: inherit; border: 1px solid #ddd; border-radius: 8px; padding: .75rem; cursor: pointer; }
.list-row.chosen { border-color: #2563eb; background: #eff6ff; color: #1e293b; } .list-row span:first-child { display: grid; gap: .2rem; } .pill { white-space: nowrap; color: #334155; }
.perm-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: .6rem; }.perm-item { border: 1px solid #e5e7eb; border-radius: 8px; padding: .7rem; display: grid; gap: .2rem; }
.module { text-transform: uppercase; font-weight: 700; } .perm-item p { color: #6b7280; margin: 0; font-size: .85rem; }
@media(max-width:760px) { .v3-grid, .metrics { grid-template-columns: 1fr; } }


/* =========================================
   GC-ATTINGUIÉ - Permissions des rôles
   Présentation en 3 colonnes
   ========================================= */

.permissions-card {
  display: block !important;
  width: 100%;
  padding: 18px;
  background: #ffffff;
  border: 1px solid #d8e5dc;
  border-radius: 14px;
  box-sizing: border-box;
}

/* Titre vert */

.permissions-title {
  margin: 0 0 18px;
  color: #075d39;
  font-size: 20px;
  font-weight: 700;
}

/* Conteneur */

.permissions-table {
  display: flex;
  flex-direction: column;
  width: 100%;
  max-height: 360px;
  overflow-y: auto;
  overflow-x: hidden;
  border: 1px solid #e5ece7;
  border-radius: 8px;
}

/* Structure des trois colonnes */

.permissions-header,
.permissions-card .permission-row {
  display: grid !important;
  grid-template-columns:
    65px
    minmax(0, 1fr)
    minmax(150px, 1fr);
  align-items: center !important;
  column-gap: 16px;
  width: 100%;
  box-sizing: border-box;
}

/* En-tête */

.permissions-header {
  position: sticky;
  top: 0;
  z-index: 1;
  padding: 12px 14px;
  background: #edf7f1;
  border-bottom: 2px solid #008751;
  color: #075d39;
  font-size: 13px;
  font-weight: 700;
}

/* Lignes des permissions */

.permissions-card .permission-row {
  position: static !important;
  margin: 0 !important;
  padding: 12px 14px !important;
  border-bottom: 1px solid #e5ece7;
  background: #ffffff;
  cursor: pointer;
  text-align: left !important;
}

.permissions-card .permission-row:hover {
  background: #f8fbf9;
}

/* Checkboxes */

.permissions-card .permission-row input[type="checkbox"] {
  display: block !important;
  position: static !important;
  justify-self: center;
  width: 18px !important;
  height: 18px !important;
  min-width: 18px;
  margin: 0 !important;
  padding: 0 !important;
  accent-color: #008751;
  cursor: pointer;
}

/* Libellés */

.permission-name {
  display: block;
  min-width: 0;
  color: #14283c;
  font-size: 14px;
  font-weight: 500;
  line-height: 1.5;
}

/* Codes techniques */

.permission-code {
  display: block;
  min-width: 0;
  color: #637c73;
  font-size: 12px;
  overflow-wrap: anywhere;
}

/* Bouton orange */

.permission-create-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: auto !important;
  min-height: 44px;
  margin-top: 16px;
  padding: 10px 20px;
  background: #ed7100 !important;
  border: 1px solid #ed7100 !important;
  border-radius: 10px;
  color: #ffffff !important;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 4px 10px rgba(237, 113, 0, 0.17);
}

.permission-create-btn:hover:not(:disabled) {
  background: #d56400 !important;
}

.permission-create-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

/* Responsive */

@media (max-width: 640px) {
  .permissions-card {
    padding: 12px;
  }

  .permissions-header,
  .permissions-card .permission-row {
    grid-template-columns: 40px minmax(0, 1fr);
    gap: 8px;
  }

  .permissions-header span:last-child {
    display: none;
  }

  .permission-code {
    grid-column: 2;
    font-size: 11px;
  }

  .permission-create-btn {
    width: 100% !important;
  }
}
</style>
