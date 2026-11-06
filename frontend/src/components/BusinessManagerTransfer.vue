<script setup>
import { onMounted, ref } from 'vue'
import { api, ApiError } from '../api'

const props = defineProps({ businessId: { type: [Number, String], required: true } })
const loading = ref(true)
const sending = ref(false)
const current = ref(null)
const error = ref('')
const fieldErrors = ref({})
const result = ref(null)
const history = ref([])
const form = ref({ managerFirstName: '', managerLastName: '', managerEmail: '', managerPhone: '', reason: '', confirmTransfer: false })

async function reload() {
  loading.value = true
  try {
    const [business, changes] = await Promise.all([
      api.get(`/api/businesses/${encodeURIComponent(props.businessId)}`),
      api.get(`/api/businesses/${encodeURIComponent(props.businessId)}/manager-transfer/history`),
    ])
    current.value = {
      name: `${business.managerFirstName || ''} ${business.managerLastName || ''}`.trim() || 'Aucun gérant actif',
      email: business.managerEmail || 'Non renseigné',
      linked: !!business.managerAccountLinked,
    }
    history.value = Array.isArray(changes.items) ? changes.items : []
  } catch (e) {
    error.value = e.message || 'Impossible de charger la gérance du commerce.'
  } finally {
    loading.value = false
  }
}

onMounted(reload)

async function transfer() {
  if (sending.value || result.value) return
  sending.value = true
  error.value = ''
  fieldErrors.value = {}
  try {
    result.value = await api.post(`/api/businesses/${encodeURIComponent(props.businessId)}/manager-transfer`, {
      ...form.value,
      managerFirstName: form.value.managerFirstName.trim(),
      managerLastName: form.value.managerLastName.trim(),
      managerEmail: form.value.managerEmail.trim(),
      managerPhone: form.value.managerPhone.trim(),
      reason: form.value.reason.trim(),
    })
    // Ne pas recharger automatiquement : l'invitation n'est affichée qu'une fois.
    await reload()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422 && e.data?.errors) {
      fieldErrors.value = e.data.errors
      error.value = 'Corrigez les informations signalées.'
    } else {
      error.value = e.message || 'Le transfert n’a pas pu être réalisé.'
    }
  } finally {
    sending.value = false
  }
}

function refreshPage() { window.location.reload() }

function activationUrl(token) {
  return `${window.location.origin}/activation#token=${encodeURIComponent(token)}`
}

async function copyInvitation(token) {
  try {
    await navigator.clipboard.writeText(activationUrl(token))
  } catch (_) {
    error.value = 'Copie impossible. Copiez le lien manuellement depuis le champ.'
  }
}

function dateDisplay(date) {
  try { return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Africa/Abidjan' }).format(new Date(date)) }
  catch (_) { return date }
}
</script>

<template>
  <section class="card gc-transfer-panel" aria-label="Changement de gérant">
    <div class="gc-transfer-top">
      <div>
        <h2>Gérance du commerce</h2>
        <p class="muted small">Le transfert est réservé à l'administration et conserve l'historique du commerce.</p>
      </div>
      <span class="gc-transfer-pill">Accès sécurisé</span>
    </div>
    <p v-if="loading" class="muted">Chargement des informations du gérant…</p>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <div v-if="current && !loading" class="gc-transfer-current">
      <strong>Gérant actuel : {{ current.name }}</strong>
      <span>{{ current.email }}</span>
      <small v-if="!current.linked" class="muted">Ancien gérant sans compte utilisateur lié : le transfert reste possible.</small>
    </div>

    <div v-if="result" class="gc-transfer-success" role="status">
      <strong>Transfert enregistré avec succès.</strong>
      <p>Le nouveau gérant est {{ result.newManager?.name }} ({{ result.newManager?.email }}).</p>
      <div v-if="result.invitation" class="gc-transfer-invite">
        <strong>Lien d'activation du nouveau gérant</strong>
        <p>Ce lien n'est pas envoyé automatiquement. Transmettez-le uniquement au destinataire par un canal sécurisé ; il expire après 7 jours.</p>
        <input :value="activationUrl(result.invitation.token)" readonly aria-label="Lien d'activation confidentiel" />
        <button type="button" class="gc-transfer-button" @click="copyInvitation(result.invitation.token)">Copier le lien</button>
      </div>
      <button type="button" class="gc-transfer-button" @click="refreshPage">Actualiser la fiche du commerce</button>
    </div>

    <details v-else-if="!loading" class="gc-transfer-details">
      <summary>Changer le gérant principal</summary>
      <p class="muted small">Cette opération révoque l'accès de l'ancien gérant à ce commerce uniquement. Elle ne touche pas à ses autres commerces ni aux factures.</p>
      <form class="gc-transfer-form" @submit.prevent="transfer">
        <div class="gc-transfer-grid">
          <label>Prénom du nouveau gérant *
            <input v-model.trim="form.managerFirstName" maxlength="100" required autocomplete="off" />
            <small v-if="fieldErrors.managerFirstName" class="error">{{ fieldErrors.managerFirstName }}</small>
          </label>
          <label>Nom du nouveau gérant *
            <input v-model.trim="form.managerLastName" maxlength="100" required autocomplete="off" />
            <small v-if="fieldErrors.managerLastName" class="error">{{ fieldErrors.managerLastName }}</small>
          </label>
          <label>Adresse e-mail *
            <input v-model.trim="form.managerEmail" type="email" maxlength="180" required autocomplete="off" />
            <small v-if="fieldErrors.managerEmail" class="error">{{ fieldErrors.managerEmail }}</small>
          </label>
          <label>Téléphone
            <input v-model.trim="form.managerPhone" type="tel" maxlength="30" autocomplete="off" />
            <small v-if="fieldErrors.managerPhone" class="error">{{ fieldErrors.managerPhone }}</small>
          </label>
        </div>
        <label>Motif du changement * (10 à 500 caractères)
          <textarea v-model.trim="form.reason" minlength="10" maxlength="500" rows="3" required placeholder="Ex. : changement du responsable déclaré à la mairie" />
          <small v-if="fieldErrors.reason" class="error">{{ fieldErrors.reason }}</small>
        </label>
        <label class="gc-transfer-confirm">
          <input v-model="form.confirmTransfer" type="checkbox" required />
          Je confirme le remplacement de la gérance et le transfert des accès à ce commerce.
        </label>
        <small v-if="fieldErrors.confirmTransfer" class="error">{{ fieldErrors.confirmTransfer }}</small>
        <button type="submit" class="gc-transfer-button" :disabled="sending || !form.confirmTransfer">
          {{ sending ? 'Transfert en cours…' : 'Confirmer le changement de gérant' }}
        </button>
      </form>
    </details>

    <details v-if="history.length" class="gc-transfer-history">
      <summary>Historique des transferts ({{ history.length }})</summary>
      <div v-for="(item, i) in history" :key="i" class="gc-transfer-history-item">
        <strong>{{ item.oldManager || 'Gérant non renseigné' }} → {{ item.newManager }}</strong>
        <p class="muted small">{{ dateDisplay(item.changedAt) }} · Par {{ item.changedBy }}</p>
        <p>{{ item.reason }}</p>
      </div>
    </details>
  </section>
</template>

<style scoped>
.gc-transfer-panel { margin-top: 1.4rem; border-top: 5px solid #E86D00; }
.gc-transfer-top { display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
.gc-transfer-top h2 { margin:0; font-size:1.3rem; }
.gc-transfer-pill { background:#fbe8d8; color:#873a04; border-radius:99px; padding:.35rem .8rem; align-self:flex-start; font-weight:700; }
.gc-transfer-current { display:grid; gap:.3rem; background:var(--bg,#f7f9f6); padding:1rem; border-radius:12px; margin:.8rem 0; }
.gc-transfer-details, .gc-transfer-history { margin-top:1rem; }
.gc-transfer-details summary, .gc-transfer-history summary { cursor:pointer; color:#d35d00; font-weight:700; padding:.5rem 0; }
.gc-transfer-form { display:grid; gap:1rem; margin-top:.75rem; }
.gc-transfer-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:1rem; }
.gc-transfer-form label { display:grid; gap:.35rem; }
.gc-transfer-form textarea, .gc-transfer-invite input { width:100%; padding:.6rem; color:var(--text,#14253b); background:var(--card,#fff); border:1px solid var(--border,#cdd9d2); border-radius:8px; font:inherit; }
.gc-transfer-confirm { display:flex!important; align-items:flex-start; gap:.65rem!important; font-weight:500; }
.gc-transfer-confirm input { width:auto; margin-top:.35rem; }
.gc-transfer-button { border:1px solid #ba5400; background:#E86D00; color:#fff; border-radius:9px; padding:.7rem 1rem; font-weight:700; cursor:pointer; width:fit-content; max-width:100%; }
.gc-transfer-button:hover { background:#c95e00; }
.gc-transfer-button:disabled { opacity:.55; cursor:not-allowed; }
.gc-transfer-success { background:#e2f5e9; color:#075e3d; padding:1rem; border-radius:12px; margin-top:1rem; }
.gc-transfer-invite { display:grid; gap:.5rem; margin:1rem 0; }
.gc-transfer-history-item { padding:.8rem 0; border-bottom:1px solid var(--border,#ccc); }
.gc-transfer-history-item p { margin:.25rem 0; }
</style>
