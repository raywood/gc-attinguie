<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, ApiError } from '../api'

const MAX_ROWS = 200
const columns = [
  { key: 'name', label: 'Nom du commerce *', placeholder: 'Ex. Maquis Chez Awa' },
  { key: 'activityTypeId', label: 'Type d’activité *', select: true },
  { key: 'district', label: 'Quartier / commune' },
  { key: 'address', label: 'Adresse' },
  { key: 'managerLastName', label: 'Gérant — nom' },
  { key: 'managerFirstName', label: 'Gérant — prénom' },
  { key: 'managerPhone', label: 'Téléphone', inputmode: 'tel' },
]

const blankRow = () => ({
  name: '', activityTypeId: '', district: '', address: '',
  managerLastName: '', managerFirstName: '', managerPhone: '',
})
const blankRows = (n = 5) => Array.from({ length: n }, blankRow)

const types = ref([])
const rows = ref(blankRows())
const errors = ref({}) // clé "indexLigne.champ" → message
const globalError = ref('')
const createdCount = ref(0)
const loading = ref(false)

const fmt = new Intl.NumberFormat('fr-FR')
const typeLabel = (t) => `${t.label} — ${fmt.format(Number(t.monthlyFee))} ${t.currency}/mois`

const isEmpty = (r) => Object.values(r).every((v) => String(v).trim() === '')
const filledCount = computed(() => rows.value.filter((r) => !isEmpty(r)).length)
const err = (i, key) => errors.value[`${i}.${key}`]

onMounted(async () => {
  try {
    types.value = await api.get('/api/activity-types')
  } catch {
    globalError.value = 'Impossible de charger les types d’activité.'
  }
})

function addRow() {
  if (rows.value.length < MAX_ROWS) rows.value.push(blankRow())
}

function removeRow(i) {
  rows.value.splice(i, 1)
  if (!rows.value.length) rows.value.push(blankRow())
  errors.value = {} // les indices ont changé
}

async function submit() {
  globalError.value = ''
  createdCount.value = 0
  errors.value = {}

  // On n'envoie que les lignes remplies, en gardant leur position d'origine pour afficher les erreurs.
  const entries = rows.value.map((row, index) => ({ row, index })).filter(({ row }) => !isEmpty(row))
  if (!entries.length) {
    globalError.value = 'Saisissez au moins un commerce.'
    return
  }

  loading.value = true
  try {
    const res = await api.post('/api/businesses', {
      rows: entries.map(({ row }) => ({
        ...row,
        activityTypeId: row.activityTypeId === '' ? null : Number(row.activityTypeId),
      })),
    })
    createdCount.value = res.created
    rows.value = blankRows()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422 && e.data?.errors) {
      const map = {}
      for (const [key, message] of Object.entries(e.data.errors)) {
        const m = key.match(/^rows\.(\d+)\.(\w+)$/)
        const entry = m && entries[Number(m[1])]
        if (entry) map[`${entry.index}.${m[2]}`] = message
        else globalError.value = message
      }
      errors.value = map
      if (!globalError.value) globalError.value = 'Corrigez les champs signalés en rouge ; rien n’a été enregistré.'
    } else {
      globalError.value = e.message || 'Enregistrement impossible.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="row-head">
    <h1>Saisir des commerces</h1>
    <RouterLink to="/commerces" class="btn ghost">Voir la liste</RouterLink>
  </div>
  <p class="muted">
    Remplissez une ligne par commerce (les lignes vides sont ignorées). L’enregistrement est « tout ou rien » :
    si une ligne contient une erreur, aucun commerce n’est créé.
  </p>

  <p v-if="createdCount" class="success" role="status">
    ✔ {{ createdCount }} commerce(s) enregistré(s).
    <RouterLink to="/commerces">Voir la liste</RouterLink>
  </p>
  <p v-if="globalError" class="error" role="alert">{{ globalError }}</p>

  <form @submit.prevent="submit">
    <div class="table-wrap">
      <table class="entry">
        <thead>
          <tr>
            <th class="num">#</th>
            <th v-for="c in columns" :key="c.key">{{ c.label }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in rows" :key="i">
            <td class="num muted">{{ i + 1 }}</td>
            <td v-for="c in columns" :key="c.key">
              <select v-if="c.select" v-model="row[c.key]" :class="{ invalid: err(i, c.key) }">
                <option value="">— Choisir —</option>
                <option v-for="t in types" :key="t.id" :value="t.id">{{ typeLabel(t) }}</option>
              </select>
              <input
                v-else
                v-model="row[c.key]"
                :class="{ invalid: err(i, c.key) }"
                :placeholder="c.placeholder"
                :inputmode="c.inputmode"
                type="text"
              />
              <small v-if="err(i, c.key)" class="error">{{ err(i, c.key) }}</small>
            </td>
            <td>
              <button type="button" class="btn ghost icon" title="Supprimer la ligne" @click="removeRow(i)">✕</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="actions">
      <button type="button" class="btn ghost" :disabled="rows.length >= MAX_ROWS" @click="addRow">+ Ajouter une ligne</button>
      <button type="button" class="btn ghost" @click="rows.push(...blankRows(5))" :disabled="rows.length + 5 > MAX_ROWS">
        + 5 lignes
      </button>
      <span class="spacer"></span>
      <span class="muted">{{ filledCount }} commerce(s) à enregistrer</span>
      <button class="btn" :disabled="loading || !filledCount">
        {{ loading ? 'Enregistrement…' : 'Enregistrer tout' }}
      </button>
    </div>
  </form>
</template>
