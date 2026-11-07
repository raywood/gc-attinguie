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

    <form
      class="card agent-search-form"
      @submit.prevent="page = 1; load()"
    >
      <h3 class="agent-search-title">
        Rechercher un agent
      </h3>

      <div class="agent-search-grid">
        <div class="agent-search-field">
          <label for="agent-search">
            Nom, prénom ou adresse e-mail
          </label>

          <input
            id="agent-search"
            v-model="search"
            type="search"
            maxlength="100"
            placeholder="Email, prénom, nom"
          />
        </div>
      </div>

      <div class="agent-search-actions">
        <button
          class="btn agent-search-btn"
          type="submit"
        >
          Rechercher
        </button>
      </div>
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

<section class="card role-card">
  <h2 class="role-card-title">Rôles du compte</h2>

  <template v-if="selected">
    <p class="role-account-email">
      <strong>{{ selected.email }}</strong>
    </p>

    <p v-if="isSelf(selected)" class="muted">
      La modification de tes propres rôles est bloquée
      pour protéger l’administration.
    </p>

    <div class="roles-grid">
      <!-- En-têtes des trois colonnes -->
      <div class="roles-grid-header">
        <span>Choix</span>
        <span>Fonction</span>
        <span>Code du rôle</span>
      </div>

      <!-- Liste des rôles -->
      <label
        v-for="r in roles"
        :key="r.code"
        class="role-grid-row"
      >
        <input
          v-model="choice"
          type="checkbox"
          :value="r.code"
          :disabled="busy || isSelf(selected)"
        />

        <span class="role-grid-name">
          {{ r.label }}
        </span>

        <small class="role-grid-code">
          {{ r.code }}
        </small>
      </label>
    </div>

    <div class="role-card-actions">
      <button
        class="btn role-save-btn"
        type="button"
        :disabled="busy || isSelf(selected) || !choice.length"
        @click="save"
      >
        {{ busy ? 'Enregistrement…' : 'Enregistrer les attributions' }}
      </button>
    </div>
  </template>

  <p v-else class="muted">
    Sélectionne un compte dans la liste.
  </p>
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

/* ==========================================
   GC-ATTINGUIÉ
   Attribution des rôles V3.1
   Formulaire de recherche des agents
   ========================================== */

.agent-search-form.card {
  display: block !important;
  width: 100%;
  max-width: none;
  height: auto !important;
  min-height: 0 !important;
  margin: 0;
  padding: 20px;
  box-sizing: border-box;
  background: #ffffff;
  border: 1px solid #d8e5dc;
  border-radius: 14px;
  box-shadow: 0 5px 14px rgba(0, 65, 35, 0.05);
}

/* Titre vert */

.agent-search-title {
  margin: 0 0 16px;
  color: #075d39;
  font-size: 18px;
  font-weight: 700;
  line-height: 1.4;
}

/* Grille identique aux autres formulaires */

.agent-search-grid {
  display: grid !important;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
  width: 100%;
  align-items: start;
  justify-items: stretch;
}

/* Champ et libellé */

.agent-search-form .agent-search-field {
  display: flex !important;
  flex-direction: column !important;
  align-items: stretch !important;
  width: 100%;
  min-width: 0;
  margin: 0 !important;
  padding: 0;
  gap: 6px;
}

.agent-search-form .agent-search-field label {
  display: block !important;
  position: static !important;
  width: 100%;
  margin: 0 !important;
  padding: 0;
  color: #194e37;
  font-size: 14px;
  font-weight: 700;
  text-align: left;
}

/* Champ de recherche */

.agent-search-form .agent-search-field input {
  display: block !important;
  position: static !important;
  width: 100% !important;
  max-width: none !important;
  height: 42px !important;
  min-height: 42px !important;
  margin: 0 !important;
  padding: 9px 12px;
  box-sizing: border-box;
  color: #14283c;
  background: #ffffff;
  border: 1px solid #d6e3da;
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
}

.agent-search-form input:focus {
  outline: none;
  border-color: #008751;
  box-shadow: 0 0 0 3px rgba(0, 135, 81, 0.12);
}

/* Actions alignées à gauche */

.agent-search-actions {
  display: flex !important;
  justify-content: flex-start !important;
  align-items: center;
  width: 100%;
  margin-top: 16px;
}

/* Bouton orange */

.agent-search-form .agent-search-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: auto !important;
  min-height: 42px;
  padding: 10px 20px;
  margin: 0 !important;
  background: #ed7100;
  border: 1px solid #ed7100;
  border-radius: 10px;
  color: #ffffff;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 4px 10px rgba(237, 113, 0, 0.17);
}

.agent-search-form .agent-search-btn:hover {
  background: #d56400;
  border-color: #d56400;
}

/* Responsive */

@media (max-width: 768px) {
  .agent-search-grid {
    grid-template-columns: 1fr;
    gap: 14px;
  }

  .agent-search-form.card {
    padding: 14px;
  }
}


/* ============================================
   GC-ATTINGUIÉ - Attribution des rôles V3.1
   Affichage en trois colonnes
   ============================================ */

.role-card.card {
  display: block !important;
  width: 100%;
  height: auto !important;
  min-height: 0 !important;
  padding: 22px;
  background: #ffffff;
  border: 1px solid #d8e5dc;
  border-radius: 14px;
  box-sizing: border-box;
  box-shadow: 0 5px 14px rgba(0, 65, 35, 0.05);
}

/* En-tête */

.role-card-title {
  margin: 0 0 10px;
  color: #075d39;
  font-size: 22px;
  font-weight: 700;
}

.role-account-email {
  margin: 0 0 20px;
  color: #14283c;
  font-size: 15px;
}

/* Grille des rôles */

.roles-grid {
  display: flex;
  flex-direction: column;
  width: 100%;
  gap: 0;
  margin-bottom: 20px;
}

/* Colonnes communes à l'en-tête et aux lignes */

.roles-grid-header,
.role-card .role-grid-row {
  display: grid !important;
  grid-template-columns:
    70px
    minmax(150px, 1fr)
    minmax(230px, 1.15fr);

  align-items: center !important;
  column-gap: 16px;
  width: 100%;
  box-sizing: border-box;
  text-align: left !important;
}

/* En-tête du tableau */

.roles-grid-header {
  padding: 12px 14px;
  color: #075d39;
  background: #f0f7f2;
  border-bottom: 2px solid #008751;
  border-radius: 8px 8px 0 0;
  font-size: 13px;
  font-weight: 700;
}

/* Chaque ligne */

.role-card .role-grid-row {
  position: static !important;
  margin: 0 !important;
  padding: 13px 14px !important;
  border-bottom: 1px solid #e5ece7;
  color: #14283c;
  background: #ffffff;
  cursor: pointer;
  gap: 16px;
}

.role-card .role-grid-row:hover {
  background: #f8fbf9;
}

/* Cases à cocher */

.role-card .role-grid-row input[type="checkbox"] {
  display: block !important;
  position: static !important;
  width: 18px !important;
  height: 18px !important;
  min-width: 18px;
  margin: 0 !important;
  padding: 0 !important;
  justify-self: center;
  accent-color: #008751;
  cursor: pointer;
}

/* Libellé des rôles */

.role-grid-name {
  display: block;
  color: #14283c;
  font-size: 15px;
  font-weight: 500;
  text-align: left;
  line-height: 1.5;
}

/* Codes des rôles */

.role-grid-code {
  display: block;
  color: #637c73;
  font-size: 12px;
  font-weight: 400;
  text-align: left;
  overflow-wrap: anywhere;
}

/* Bouton d'enregistrement */

.role-card-actions {
  display: flex !important;
  justify-content: flex-start !important;
  width: 100%;
}

.role-card .role-save-btn {
  display: inline-flex;
  width: auto !important;
  min-height: 44px;
  padding: 12px 20px;
  background: #ed7100;
  border: 1px solid #ed7100;
  border-radius: 10px;
  color: #ffffff;
  font-size: 14px;
  font-weight: 700;
  box-shadow: 0 4px 10px rgba(237, 113, 0, 0.17);
}

.role-card .role-save-btn:hover:not(:disabled) {
  background: #d56400;
}

.role-card .role-save-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

/* Responsive */

@media (max-width: 640px) {
  .role-card.card {
    padding: 14px;
  }

  .roles-grid-header,
  .role-card .role-grid-row {
    grid-template-columns: 40px minmax(0, 1fr);
    column-gap: 12px;
  }

  .roles-grid-header span:last-child {
    display: none;
  }

  .role-grid-code {
    grid-column: 2;
    font-size: 11px;
  }

  .role-card .role-save-btn {
    width: 100% !important;
    justify-content: center;
  }
}

</style>
