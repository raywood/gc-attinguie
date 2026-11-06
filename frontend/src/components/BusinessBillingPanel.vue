<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api'

const props = defineProps({ businessId: { type: [String, Number], required: true } })
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const info = ref('')
const data = ref(null)
const frequencies = [
  { value: 'monthly', label: 'Mensuelle' },
  { value: 'daily', label: 'Journalière (constats)' },
  { value: 'weekly', label: 'Hebdomadaire (en préparation)' },
  { value: 'yearly', label: 'Annuelle (en préparation)' },
]
function isoToday() {
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Africa/Abidjan', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
}
function nextMonth() { const [y, m] = isoToday().split('-').map(Number); return `${m === 12 ? y + 1 : y}-${String(m === 12 ? 1 : m + 1).padStart(2, '0')}-01` }
const profile = ref({ frequency: 'monthly', effectiveFrom: nextMonth(), reason: '' })
const daily = ref({ activityDate: isoToday(), note: '' })
const merchantEmail = ref('')
const activeDailyTariff = computed(() => data.value?.tariffs?.find(t => t.frequency === 'daily' && t.active && Number(t.amount) > 0))
const num = value => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format(Number(value || 0))
const frequencyLabel = value => frequencies.find(f => f.value === value)?.label || value
async function load() {
  loading.value = true; error.value = ''
  try { data.value = await api.get(`/api/admin/billing/businesses/${encodeURIComponent(props.businessId)}`) }
  catch (e) { error.value = e.message || 'Chargement impossible.' }
  finally { loading.value = false }
}
async function act(url, payload, success) {
  if (saving.value) return
  saving.value = true; error.value = ''; info.value = ''
  try {
    await api.post(url, { ...payload, csrf: data.value.csrf })
    info.value = success
    await load()
  } catch (e) { error.value = e.message || 'Opération impossible.' }
  finally { saving.value = false }
}
async function saveProfile() {
  if (!window.confirm('La nouvelle périodicité prendra effet à la date indiquée. Les factures existantes resteront inchangées. Confirmer ?')) return
  await act(`/api/admin/billing/businesses/${props.businessId}/profiles`, profile.value, 'Profil enregistré pour une date future.')
}
async function confirmDay() {
  if (!window.confirm(`Confirmer l’activité constatée le ${daily.value.activityDate} ?`)) return
  await act(`/api/admin/billing/businesses/${props.businessId}/daily`, daily.value, 'Journée constatée et valorisée.')
}
async function voidDay(row) {
  const reason = window.prompt('Motif obligatoire de l’annulation (10 caractères minimum) :')
  if (!reason) return
  await act(`/api/admin/billing/businesses/${props.businessId}/daily/${row.id}/void`, { reason }, 'Constat annulé avec historique.')
}
async function linkMerchant() {
  if (!window.confirm(`Autoriser le compte ${merchantEmail.value} à consulter les factures de ce commerce ?`)) return
  await act(`/api/admin/billing/businesses/${props.businessId}/merchant-users`, { email: merchantEmail.value.trim() }, 'Accès commerçant accordé.')
  merchantEmail.value = ''
}
async function revokeAccess(user) {
  if (!window.confirm(`Révoquer l’accès de ${user.email} aux factures ?`)) return
  await act(`/api/admin/billing/businesses/${props.businessId}/merchant-users/${user.userId}/revoke`, {}, 'Accès commerçant révoqué.')
}
onMounted(load)
</script>

<template>
  <section class="card gc-billing-panel mt-4" aria-label="Facturation et échéances">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <div><h2 class="h4 mb-1">Facturation et échéances</h2><p class="text-secondary mb-0">Gestion datée, sans réécriture des factures déjà émises.</p></div>
      <span class="badge text-bg-success">{{ frequencyLabel(data?.currentFrequency || 'monthly') }}</span>
    </div>
    <p v-if="loading">Chargement de la facturation…</p>
    <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
    <p v-if="info" class="alert alert-success" role="status">{{ info }}</p>
    <template v-if="data && !loading">
      <div class="row g-3">
        <div class="col-12 col-lg-6">
          <div class="border rounded-3 p-3 h-100">
            <h3 class="h6">Programmer une nouvelle périodicité</h3>
            <form class="d-grid gap-2" @submit.prevent="saveProfile">
              <label>Périodicité
                <select v-model="profile.frequency" class="form-select" required>
                  <option v-for="f in frequencies" :key="f.value" :value="f.value">{{ f.label }}</option>
                </select>
              </label>
              <label>Date d’effet (1er d’un mois futur)
                <input v-model="profile.effectiveFrom" type="date" class="form-control" :min="nextMonth()" required />
              </label>
              <label>Motif de changement
                <input v-model.trim="profile.reason" class="form-control" maxlength="255" minlength="6" required placeholder="Ex. décision municipale du ..." />
              </label>
              <button class="btn gc-button-primary" type="submit" :disabled="saving">Programmer le changement</button>
            </form>
            <small class="text-secondary d-block mt-2">Les modes hebdomadaire et annuel sont configurables, mais leur émission automatique reste désactivée.</small>
          </div>
        </div>
        <div class="col-12 col-lg-6">
          <div class="border rounded-3 p-3 h-100">
            <h3 class="h6">Historique du profil</h3>
            <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Depuis</th><th>Périodicité</th><th>Motif</th></tr></thead>
              <tbody><tr v-for="p in data.profiles" :key="p.id"><td>{{ p.effectiveFrom }}</td><td>{{ frequencyLabel(p.frequency) }}</td><td>{{ p.reason }}</td></tr></tbody>
            </table></div>
          </div>
        </div>
      </div>
      <div class="border rounded-3 p-3 mt-3">
        <h3 class="h6">Constat journalier — solution B</h3>
        <p class="small text-secondary">Un agent municipal confirme une journée d’activité réelle. Le montant est figé à la saisie. Aucun paiement ni PDF de facture n’est créé par cette action.</p>
        <p v-if="data.currentFrequency !== 'daily'" class="alert alert-info">Les constats journaliers seront disponibles uniquement quand le profil journalier prendra effet.</p>
        <p v-if="activeDailyTariff">Tarif journalier actuel : <strong>{{ num(activeDailyTariff.amount) }} FCFA</strong></p>
        <p v-else class="text-warning">Aucun tarif journalier actif trouvé pour cette activité.</p>
        <form class="row g-2 align-items-end" @submit.prevent="confirmDay">
          <div class="col-sm-4"><label>Jour constaté<input v-model="daily.activityDate" type="date" class="form-control" :max="isoToday()" required /></label></div>
          <div class="col-sm-5"><label>Observation<input v-model.trim="daily.note" maxlength="250" class="form-control" placeholder="Lieu, passage de l’agent…" /></label></div>
          <div class="col-sm-3"><button class="btn gc-button-primary w-100" type="submit" :disabled="saving || !activeDailyTariff || data.currentFrequency !== 'daily'">Valider le constat</button></div>
        </form>
        <div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>Date</th><th>Montant figé</th><th>Statut</th><th>Action</th></tr></thead>
          <tbody>
            <tr v-for="r in data.dailyRecords" :key="r.id"><td>{{ r.activityDate }}</td><td>{{ num(r.amount) }} FCFA</td><td>{{ r.status === 'confirmed' ? 'Confirmé' : 'Annulé' }}</td>
              <td><button v-if="r.status === 'confirmed'" type="button" class="btn btn-sm btn-outline-danger" :disabled="saving" @click="voidDay(r)">Annuler avec motif</button><span v-else class="small text-secondary">{{ r.voidReason }}</span></td>
            </tr><tr v-if="!data.dailyRecords.length"><td colspan="4">Aucun constat pour ce commerce.</td></tr>
          </tbody>
        </table></div>
      </div>
      <div class="border rounded-3 p-3 mt-3">
        <h3 class="h6">Accès du commerçant à ses factures</h3>
        <p class="small text-secondary">Rattacher explicitement un compte utilisateur actif. Ne jamais se fier simplement à l’adresse du gérant.</p>
        <form class="row g-2 align-items-end" @submit.prevent="linkMerchant">
          <div class="col-sm-8"><label>E-mail du compte utilisateur<input v-model.trim="merchantEmail" type="email" class="form-control" required /></label></div>
          <div class="col-sm-4"><button class="btn gc-button-primary w-100" type="submit" :disabled="saving">Autoriser l’accès</button></div>
        </form>
        <ul class="list-group mt-2"><li v-for="u in data.merchantUsers" :key="u.userId" class="list-group-item d-flex justify-content-between align-items-center gap-3">
          <span>{{ u.email }} <small class="text-secondary">({{ u.active ? 'actif' : 'révoqué' }})</small></span>
          <button v-if="u.active" type="button" class="btn btn-sm btn-outline-danger" :disabled="saving" @click="revokeAccess(u)">Révoquer</button>
        </li></ul>
      </div>
    </template>
  </section>
</template>

<style scoped>
.gc-billing-panel {border-top: 4px solid #e86d00; margin-top: 1.5rem;}
.gc-billing-panel h2, .gc-billing-panel h3 {color:#075e3d;}
.gc-billing-panel .gc-button-primary {background:#e86d00;border-color:#e86d00;color:#fff;font-weight:700;}
.gc-billing-panel .gc-button-primary:hover {background:#c85e00;border-color:#c85e00;}
</style>
