<template>
  <main class="relative min-h-screen overflow-x-hidden bg-sky-50 text-slate-900">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden" aria-hidden="true">
      <div class="absolute -left-40 -top-48 h-[34rem] w-[34rem] rounded-full bg-sky-300/25 blur-[130px]" />
      <div class="absolute -right-32 top-1/4 h-[30rem] w-[30rem] rounded-full bg-blue-300/20 blur-[130px]" />
      <div class="absolute bottom-[-10rem] left-1/3 h-[28rem] w-[28rem] rounded-full bg-emerald-300/15 blur-[130px]" />
      <div class="absolute left-1/2 top-0 h-[24rem] w-[50rem] -translate-x-1/2 rounded-full bg-sky-200/25 blur-[150px]" />
    </div>

    <section class="relative z-10 mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <TopNav current="audit" />
      <Breadcrumbs :items="[{ label: 'Auditoría' }]" />

      <header class="mb-8">
        <p class="text-xs uppercase tracking-[0.22em] text-cyan-600">UDG Sentinel</p>
        <h1 class="mt-2 text-fluid-2xl font-semibold text-slate-900">Registro de auditoría</h1>
        <p class="mt-2 max-w-2xl text-sm text-slate-700">
          Historial de altas, bajas, cambios de permisos y escaneos ejecutados en el sistema.
        </p>
      </header>

      <form class="glass-panel mb-6 flex flex-wrap items-center gap-3 rounded-2xl bg-white/40 p-4" @submit.prevent="applyFilters">
        <input
          v-model="localSearch"
          type="text"
          placeholder="Buscar en la descripción..."
          class="glass-input h-10 min-w-[16rem] flex-1 rounded-xl px-3 text-sm text-slate-900 placeholder:text-slate-500 focus:border-cyan-400 focus:outline-none"
        />
        <select
          v-model="localCauserId"
          class="glass-input h-10 rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
        >
          <option value="">Todos los usuarios</option>
          <option v-for="causer in causers" :key="causer.id" :value="String(causer.id)">{{ causer.name }}</option>
        </select>
        <input
          v-model="localFrom"
          type="date"
          class="glass-input h-10 rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
        />
        <input
          v-model="localTo"
          type="date"
          class="glass-input h-10 rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
        />
        <button
          type="submit"
          class="glass-btn h-10 whitespace-nowrap rounded-xl border-cyan-300/40 bg-cyan-400 px-4 text-sm font-semibold text-slate-50 hover:bg-cyan-300"
        >
          Filtrar
        </button>
      </form>

      <div class="glass-panel overflow-hidden rounded-2xl bg-white/40">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-sky-200 text-left text-sm">
            <thead>
              <tr class="text-slate-600">
                <th class="px-4 py-4 font-medium">Fecha</th>
                <th class="px-4 py-4 font-medium">Usuario</th>
                <th class="px-4 py-4 font-medium">Acción</th>
                <th class="px-4 py-4 font-medium">Sujeto</th>
                <th class="px-4 py-4 font-medium">Detalles</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-sky-200/60">
              <tr v-for="entry in entries.data" :key="entry.id" class="hover:bg-white/40">
                <td class="px-4 py-4 whitespace-nowrap text-slate-600">{{ formatDate(entry.created_at) }}</td>
                <td class="px-4 py-4 font-medium text-slate-900">{{ entry.causer }}</td>
                <td class="px-4 py-4 text-slate-800">{{ entry.description }}</td>
                <td class="px-4 py-4 text-slate-600">
                  {{ entry.subject_type ? `${entry.subject_type} #${entry.subject_id}` : '-' }}
                </td>
                <td class="px-4 py-4">
                  <dl v-if="visibleDetails(entry.properties).length > 0" class="space-y-1 text-xs">
                    <div v-for="detail in visibleDetails(entry.properties)" :key="detail.label" class="flex gap-1.5">
                      <dt class="font-medium text-slate-600">{{ detail.label }}:</dt>
                      <dd class="text-slate-800">{{ detail.value }}</dd>
                    </div>
                  </dl>
                  <span v-else class="text-xs text-slate-400">-</span>
                </td>
              </tr>
              <tr v-if="entries.data.length === 0">
                <td colspan="5" class="px-4 py-12 text-center text-sm text-slate-600">
                  No hay actividad registrada con estos filtros.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="(entries.last_page ?? 1) > 1" class="flex items-center justify-between border-t border-sky-200/60 px-4 py-3 text-xs text-slate-600">
          <span>Página {{ entries.current_page }} de {{ entries.last_page }}</span>
          <div class="flex gap-2">
            <button
              type="button"
              class="glass-btn h-8 rounded-lg border-sky-400/50 px-3 text-xs text-slate-800 disabled:cursor-not-allowed disabled:opacity-40"
              :disabled="(entries.current_page ?? 1) <= 1"
              @click="goToPage((entries.current_page ?? 1) - 1)"
            >
              Anterior
            </button>
            <button
              type="button"
              class="glass-btn h-8 rounded-lg border-sky-400/50 px-3 text-xs text-slate-800 disabled:cursor-not-allowed disabled:opacity-40"
              :disabled="(entries.current_page ?? 1) >= (entries.last_page ?? 1)"
              @click="goToPage((entries.current_page ?? 1) + 1)"
            >
              Siguiente
            </button>
          </div>
        </div>
      </div>

      <AppFooter />
    </section>

    <CookieNotice />
  </main>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import TopNav from '@/components/layout/TopNav.vue'
import AppFooter from '@/components/layout/AppFooter.vue'
import CookieNotice from '@/components/ui/CookieNotice.vue'
import Breadcrumbs from '@/components/layout/Breadcrumbs.vue'

type AuditEntry = {
  id: number
  description: string
  causer: string
  subject_type: string | null
  subject_id: number | null
  properties: Record<string, unknown>
  created_at: string | null
}

type Paginated<T> = {
  data: T[]
  current_page?: number
  last_page?: number
}

const props = defineProps<{
  entries: Paginated<AuditEntry>
  filters: {
    search: string
    causer_id: number | null
    from: string
    to: string
  }
  causers: Array<{ id: number; name: string }>
}>()

const entries = props.entries
const causers = props.causers

const localSearch = ref(props.filters.search)
const localCauserId = ref(props.filters.causer_id ? String(props.filters.causer_id) : '')
const localFrom = ref(props.filters.from)
const localTo = ref(props.filters.to)

const formatDate = (value: string | null) => {
  if (!value) {
    return '-'
  }
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? '-' : parsed.toLocaleString('es-MX')
}

// Claves internas de plomería que no aportan nada al lector (ya se ve la
// acción en la columna "Acción") o que son el contenedor de un diff de
// atributos -ese caso se aplana aparte en vez de mostrarse como bloque.
const HIDDEN_PROPERTY_KEYS = new Set(['action'])

const KEY_LABELS: Record<string, string> = {
  filename: 'Archivo',
  size_bytes: 'Tamaño',
  error: 'Error',
  url: 'URL',
  email: 'Correo',
  user_id: 'ID de usuario',
  before: 'Antes',
  after: 'Después',
  event: 'Evento',
  resolved_alerts: 'Alertas resueltas',
  enabled: 'Activado',
  name: 'Nombre',
  department: 'Departamento',
  is_active: 'Activo',
}

const humanizeKey = (key: string): string =>
  KEY_LABELS[key] ??
  key
    .replace(/_/g, ' ')
    .replace(/^./, (letter) => letter.toUpperCase())

const formatScalar = (key: string, value: unknown): string => {
  if (value === null || value === undefined || value === '') {
    return '—'
  }
  if (typeof value === 'boolean') {
    return value ? 'Sí' : 'No'
  }
  if (key === 'size_bytes' && typeof value === 'number') {
    return value >= 1024 * 1024
      ? `${(value / (1024 * 1024)).toFixed(1)} MB`
      : `${(value / 1024).toFixed(1)} KB`
  }
  return String(value)
}

type DetailRow = { label: string; value: string }

// Aplana un nivel de anidamiento (los diffs "attributes"/"old" que dejo el
// registro automático de cambios de usuario) para que cada campo cambiado
// aparezca como su propia fila, en vez de un bloque tipo JSON.
const visibleDetails = (properties: Record<string, unknown>): DetailRow[] => {
  const rows: DetailRow[] = []

  for (const [key, value] of Object.entries(properties)) {
    if (HIDDEN_PROPERTY_KEYS.has(key)) {
      continue
    }

    if ((key === 'attributes' || key === 'old') && value !== null && typeof value === 'object') {
      const prefix = key === 'old' ? 'Antes de' : 'Nuevo'
      for (const [nestedKey, nestedValue] of Object.entries(value as Record<string, unknown>)) {
        rows.push({
          label: `${prefix} ${humanizeKey(nestedKey).toLowerCase()}`,
          value: formatScalar(nestedKey, nestedValue),
        })
      }
      continue
    }

    if (value !== null && typeof value === 'object') {
      continue
    }

    rows.push({ label: humanizeKey(key), value: formatScalar(key, value) })
  }

  return rows
}

const applyFilters = () => {
  router.get(
    '/monitoring/audit',
    {
      search: localSearch.value || undefined,
      causer_id: localCauserId.value || undefined,
      from: localFrom.value || undefined,
      to: localTo.value || undefined,
    },
    { preserveState: true, replace: true },
  )
}

const goToPage = (page: number) => {
  router.get(
    '/monitoring/audit',
    {
      search: localSearch.value || undefined,
      causer_id: localCauserId.value || undefined,
      from: localFrom.value || undefined,
      to: localTo.value || undefined,
      page,
    },
    { preserveState: true, replace: true },
  )
}
</script>
