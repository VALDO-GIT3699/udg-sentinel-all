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
                  <details v-if="Object.keys(entry.properties).length > 0">
                    <summary class="cursor-pointer text-xs text-cyan-600">Ver</summary>
                    <pre class="mt-2 max-w-xs overflow-x-auto rounded-lg bg-sky-50/60 p-2 text-[11px] text-slate-700">{{ JSON.stringify(entry.properties, null, 2) }}</pre>
                  </details>
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
