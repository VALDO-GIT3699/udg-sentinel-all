<template>
  <main class="min-h-screen bg-slate-950 text-slate-100">
    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <header class="mb-8 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.22em] text-cyan-300">UDG Sentinel</p>
          <h1 class="mt-2 text-3xl font-semibold text-white sm:text-4xl">Estado general de sitios oficiales</h1>
          <p class="mt-2 max-w-3xl text-sm text-slate-300">
            Inventario operativo limpio con acceso directo al detalle técnico de cada sitio.
          </p>
        </div>
        <p class="text-xs text-slate-400">Actualizado: {{ formattedUpdatedAt }}</p>
      </header>

      <section v-if="actionMessage" class="mb-4 rounded-xl border border-cyan-500/40 bg-cyan-500/10 px-4 py-3 text-sm text-cyan-100">
        {{ actionMessage }}
      </section>

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <button type="button" class="rounded-2xl border border-emerald-500/25 bg-emerald-500/10 p-5 text-left transition hover:border-emerald-300/60" @click="setStatusFilter('up')">
          <p class="text-xs uppercase tracking-[0.18em] text-emerald-300">Operativos</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ normalizedStatusCounts.UP }}</p>
        </button>
        <button type="button" class="rounded-2xl border border-amber-500/25 bg-amber-500/10 p-5 text-left transition hover:border-amber-300/60" @click="setStatusFilter('degraded')">
          <p class="text-xs uppercase tracking-[0.18em] text-amber-300">Con incidencias</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ normalizedStatusCounts.DEGRADED }}</p>
        </button>
        <button type="button" class="rounded-2xl border border-rose-500/25 bg-rose-500/10 p-5 text-left transition hover:border-rose-300/60" @click="setStatusFilter('down')">
          <p class="text-xs uppercase tracking-[0.18em] text-rose-300">No responde</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ normalizedStatusCounts.DOWN }}</p>
        </button>
        <button type="button" class="rounded-2xl border border-amber-500/20 bg-amber-500/8 p-5 text-left transition hover:border-amber-300/50" @click="setStatusFilter('unknown')">
          <p class="text-xs uppercase tracking-[0.18em] text-slate-300">Sin actualizar</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ normalizedStatusCounts.UNKNOWN }}</p>
        </button>
      </section>

      <section class="mt-6 grid gap-6 xl:grid-cols-2">
        <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
          <h2 class="text-lg font-semibold text-white">Distribucion por estado</h2>
          <VueApexCharts type="pie" height="280" :options="statusPieOptions" :series="statusPieSeries" />
        </article>
        <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
          <h2 class="text-lg font-semibold text-white">Panorama de diagnostico operativo</h2>
          <VueApexCharts type="bar" height="280" :options="diagnosticBarOptions" :series="diagnosticBarSeries" />
        </article>
      </section>

      <section class="mt-8 rounded-3xl border border-slate-800 bg-slate-900/80 p-5 shadow-[0_18px_60px_rgba(15,23,42,0.35)]">
        <header class="flex flex-col gap-4 border-b border-slate-800 pb-5 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <h2 class="text-xl font-semibold text-white">Sitios monitoreados</h2>
            <p class="mt-1 text-sm text-slate-400">Un clic abre el detalle, y el dominio abre el sitio en otra pestana.</p>
          </div>

          <form class="flex w-full max-w-xl flex-col gap-3 sm:flex-row" @submit.prevent="applySearch">
            <label class="sr-only" for="dashboard-search">Buscar sitio o dominio</label>
            <input
              id="dashboard-search"
              v-model="localSearch"
              list="monitoring-site-suggestions"
              type="search"
              autocomplete="off"
              placeholder="Buscar sitio o dominio"
              class="h-11 flex-1 rounded-xl border border-slate-700 bg-slate-950 px-4 text-sm text-slate-100 placeholder:text-slate-500 focus:border-cyan-400 focus:outline-none"
            />
            <datalist id="monitoring-site-suggestions">
              <option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion" />
            </datalist>
            <div class="flex gap-2">
              <button
                type="button"
                class="h-11 rounded-xl border border-amber-500/50 px-4 text-sm font-semibold text-amber-200 transition hover:border-amber-300"
                :disabled="isMassScanRunning"
                @click="scanAllSites"
              >
                {{ isMassScanRunning ? 'Programando...' : 'Programar actualizacion masiva' }}
              </button>
              <button
                type="submit"
                class="h-11 rounded-xl bg-cyan-400 px-4 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300"
              >
                Buscar
              </button>
              <button
                type="button"
                class="h-11 rounded-xl border border-slate-700 px-4 text-sm text-slate-200 transition hover:border-slate-500"
                @click="clearSearch"
              >
                Limpiar
              </button>
            </div>
          </form>
        </header>

        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
            <thead>
              <tr class="text-slate-400">
                <th class="px-4 py-4 font-medium">Sitio</th>
                <th class="px-4 py-4 font-medium">Dominio</th>
                <th class="px-4 py-4 font-medium">Estado operativo</th>
                <th class="px-4 py-4 font-medium">Diagnostico actual</th>
                <th class="px-4 py-4 font-medium">Detalle tecnico</th>
                <th class="px-4 py-4 font-medium">Último check</th>
                <th class="px-4 py-4 font-medium">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/90">
              <tr
                v-for="site in normalizedSites"
                :key="site.id"
                class="cursor-pointer transition hover:bg-slate-800/70 focus-within:bg-slate-800/70"
                tabindex="0"
                role="link"
                @click="openSiteDetail(site.id)"
                @keydown.enter.prevent="openSiteDetail(site.id)"
                @keydown.space.prevent="openSiteDetail(site.id)"
              >
                <td class="px-4 py-4 align-middle">
                  <p class="font-medium text-white">{{ fallbackSiteName(site) }}</p>
                  <p class="mt-1 text-xs text-slate-500">Abrir detalle</p>
                </td>
                <td class="px-4 py-4 text-slate-300">
                  <a :href="safeSiteUrl(site)" target="_blank" rel="noopener noreferrer" class="text-cyan-300 hover:text-cyan-200" @click.stop>
                    {{ fallbackDomain(site.domain) }}
                  </a>
                </td>
                <td class="px-4 py-4">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-3 py-1 text-xs font-semibold uppercase" :class="statusBadgeClass(resolveStatusCode(site))">
                      {{ statusLabel(resolveStatusCode(site)) }}
                    </span>
                    <span
                      v-if="resolveStatusCode(site) === 'unknown'"
                      class="inline-flex items-center gap-1 rounded-full bg-cyan-400/10 px-2 py-1 text-[11px] font-medium text-cyan-200"
                    >
                      <span class="h-1.5 w-1.5 rounded-full bg-cyan-300 animate-pulse" />
                      Escaneo en proceso
                    </span>
                  </div>
                </td>
                <td class="px-4 py-4 text-slate-300">{{ site.diagnostic_label || '-' }}</td>
                <td class="px-4 py-4 text-slate-300">{{ site.diagnostic_reason || '-' }}</td>
                <td class="px-4 py-4 text-slate-400">{{ formatCheckTime(site.last_checked_at, resolveStatusCode(site)) }}</td>
                <td class="px-4 py-4" @click.stop>
                  <button type="button" class="rounded-lg border border-cyan-600/60 px-3 py-1.5 text-xs font-semibold text-cyan-200 hover:border-cyan-400" @click="scanSingleSite(site.id)">
                    Reescanear
                  </button>
                </td>
              </tr>
              <tr v-if="normalizedSites.length === 0">
                <td colspan="7" class="px-4 py-12 text-center text-sm text-slate-400">
                  No hay sitios que coincidan con la búsqueda actual.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <footer class="mt-5 flex flex-col gap-3 border-t border-slate-800 pt-5 sm:flex-row sm:items-center sm:justify-between">
          <div class="text-sm text-slate-400">
            Mostrando {{ firstVisibleItem }}-{{ lastVisibleItem }} de {{ totalSites }} sitios.
          </div>

          <div class="flex items-center gap-3">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Página {{ currentPage }} de {{ lastPage }}</p>
            <button
              type="button"
              class="rounded-xl border border-slate-700 px-4 py-2 text-sm text-slate-200 transition hover:border-slate-500 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="currentPage <= 1"
              @click="goToPage(currentPage - 1)"
            >
              Anterior
            </button>
            <button
              type="button"
              class="rounded-xl border border-slate-700 px-4 py-2 text-sm text-slate-200 transition hover:border-slate-500 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="currentPage >= lastPage"
              @click="goToPage(currentPage + 1)"
            >
              Siguiente
            </button>
          </div>
        </footer>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import type { ApexOptions } from 'apexcharts'
import VueApexCharts from 'vue3-apexcharts'

const DEFAULT_REFRESH_INTERVAL_MS = 5000
const DEFAULT_PER_PAGE = 50

type StatusCounts = {
  UP: number
  DEGRADED: number
  DOWN: number
  UNKNOWN: number
}

type StatusCountPayload = Partial<Record<keyof StatusCounts | Lowercase<keyof StatusCounts>, number>>

type SiteItem = {
  id: number
  name: string | null
  domain: string | null
  url?: string | null
  current_status: string
  current_status_code?: string
  display_status_code?: string
  last_checked_at: string | null
  diagnostic_bucket?: string | null
  diagnostic_label?: string | null
  diagnostic_reason?: string | null
}

type Paginated<T> = {
  data: T[]
  current_page?: number
  last_page?: number
  per_page?: number
  total?: number
}

type Filters = {
  search: string
  status?: string
}

type DashboardProps = {
  filters?: Partial<Filters>
  statusCounts?: StatusCountPayload
  diagnosticBreakdown?: Partial<Record<'operativo' | 'respuesta_lenta' | 'responde_con_errores' | 'inestable' | 'no_responde' | 'sin_actualizar', number>>
  searchSuggestions?: string[]
  sites?: Paginated<SiteItem> | SiteItem[]
  refreshIntervalMs?: number
  updatedAt?: string
}

const props = defineProps<DashboardProps>()

const localSearch = ref(props.filters?.search ?? '')
const localStatus = ref((props.filters?.status ?? 'all').toString())
const actionMessage = ref('')
const isMassScanRunning = ref(false)
const suggestions = computed(() => Array.isArray(props.searchSuggestions) ? props.searchSuggestions : [])

const normalizedStatusCounts = computed<StatusCounts>(() => ({
  UP: Number(props.statusCounts?.UP ?? props.statusCounts?.up ?? 0),
  DEGRADED: Number(props.statusCounts?.DEGRADED ?? props.statusCounts?.degraded ?? 0),
  DOWN: Number(props.statusCounts?.DOWN ?? props.statusCounts?.down ?? 0),
  UNKNOWN: Number(props.statusCounts?.UNKNOWN ?? props.statusCounts?.unknown ?? 0),
}))

const normalizedSites = computed<SiteItem[]>(() => {
  if (Array.isArray(props.sites)) {
    return props.sites
  }

  return props.sites?.data ?? []
})

const currentPage = computed(() => Array.isArray(props.sites) ? 1 : (props.sites?.current_page ?? 1))
const lastPage = computed(() => Array.isArray(props.sites) ? 1 : Math.max(1, props.sites?.last_page ?? 1))
const currentPerPage = computed(() => Array.isArray(props.sites) ? normalizedSites.value.length : (props.sites?.per_page ?? DEFAULT_PER_PAGE))
const totalSites = computed(() => Array.isArray(props.sites) ? normalizedSites.value.length : (props.sites?.total ?? normalizedSites.value.length))

const firstVisibleItem = computed(() => {
  if (totalSites.value === 0) {
    return 0
  }

  return ((currentPage.value - 1) * currentPerPage.value) + 1
})

const lastVisibleItem = computed(() => {
  if (totalSites.value === 0) {
    return 0
  }

  return Math.min(totalSites.value, firstVisibleItem.value + normalizedSites.value.length - 1)
})

const formattedUpdatedAt = computed(() => {
  if (!props.updatedAt) {
    return 'Sin dato'
  }

  const parsed = new Date(props.updatedAt)
  return Number.isNaN(parsed.getTime()) ? 'Sin dato' : parsed.toLocaleString('es-MX')
})

const resolveStatusCode = (site: SiteItem): 'up' | 'down' | 'degraded' | 'unknown' => {
  const diagnosisBucket = (site.diagnostic_bucket ?? '').toString().trim().toLowerCase()
  const diagnosisLabel = (site.diagnostic_label ?? '').toString().trim().toLowerCase()

  if (diagnosisBucket === 'sin_actualizar' || diagnosisLabel === 'sin actualizar' || diagnosisLabel === 'desconocido') {
    return 'unknown'
  }

  const displayStatus = (site.display_status_code ?? '').toString().trim().toLowerCase()

  if (displayStatus === 'up' || displayStatus === 'down' || displayStatus === 'degraded' || displayStatus === 'unknown') {
    return displayStatus
  }

  const raw = (site.current_status_code ?? site.current_status ?? '').toString().trim().toLowerCase()

  if (raw === 'up' || raw === 'activo') return 'up'
  if (raw === 'degraded' || raw === 'degradado') return 'degraded'
  if (raw === 'down' || raw === 'caído' || raw === 'caido') return 'down'

  return 'unknown'
}

const statusBadgeClass = (status: ReturnType<typeof resolveStatusCode>) => {
  if (status === 'up') return 'bg-emerald-500/15 text-emerald-300'
  if (status === 'degraded') return 'bg-amber-500/15 text-amber-300'
  if (status === 'down') return 'bg-rose-500/15 text-rose-300'
  return 'bg-amber-400/10 text-amber-200'
}

const statusLabel = (status: ReturnType<typeof resolveStatusCode>) => {
  if (status === 'up') return 'OPERATIVO'
  if (status === 'degraded') return 'CON INCIDENCIAS'
  if (status === 'down') return 'NO RESPONDE'
  return 'SIN ACTUALIZAR'
}

const fallbackSiteName = (site: SiteItem) => site.name?.trim() || site.domain?.trim() || `Sitio #${site.id}`

const fallbackDomain = (domain: string | null) => domain?.trim() || 'Sin dominio'

const safeSiteUrl = (site: SiteItem) => {
  const candidate = site.url?.trim() || (site.domain ? `https://${site.domain}` : '')
  if (candidate === '') {
    return '#'
  }

  return candidate.startsWith('http://') || candidate.startsWith('https://') ? candidate : `https://${candidate}`
}

const buildDashboardQuery = (page = 1) => {
  const query: Record<string, string | number> = {
    page,
    per_page: currentPerPage.value || DEFAULT_PER_PAGE,
  }

  if (localSearch.value.trim() !== '') {
    query.search = localSearch.value.trim()
  }

  if (localStatus.value !== 'all') {
    query.status = localStatus.value
  }

  return query
}

const setStatusFilter = (status: 'up' | 'degraded' | 'down' | 'unknown') => {
  localStatus.value = status
  router.get('/monitoring/dashboard', buildDashboardQuery(1), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

const refreshDashboard = () => {
  router.get('/monitoring/dashboard', buildDashboardQuery(currentPage.value), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ['sites', 'statusCounts', 'diagnosticBreakdown', 'updatedAt'],
  })
}

const applySearch = () => {
  router.get('/monitoring/dashboard', buildDashboardQuery(1), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

const applySearchDebounced = () => {
  router.get('/monitoring/dashboard', buildDashboardQuery(1), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ['sites', 'statusCounts', 'diagnosticBreakdown', 'updatedAt'],
  })
}

const clearSearch = () => {
  localSearch.value = ''
  localStatus.value = 'all'
  applySearch()
}

const goToPage = (page: number) => {
  if (page < 1 || page > lastPage.value) {
    return
  }

  router.get('/monitoring/dashboard', buildDashboardQuery(page), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ['sites', 'statusCounts', 'diagnosticBreakdown', 'updatedAt'],
  })
}

const openSiteDetail = (siteId: number) => {
  router.visit(`/monitoring/sites/${siteId}/detail`)
}

const scanSingleSite = (siteId: number) => {
  actionMessage.value = 'Reescaneo del sitio en proceso...'
  router.post(`/monitoring/sites/${siteId}/scan`, {}, {
    preserveScroll: true,
    onSuccess: () => {
      actionMessage.value = 'Reescaneo del sitio completado.'
      refreshDashboard()
    },
    onError: () => {
      actionMessage.value = 'No se pudo reescanear el sitio.'
    },
  })
}

const scanAllSites = () => {
  if (isMassScanRunning.value) {
    return
  }

  isMassScanRunning.value = true
  actionMessage.value = 'Iniciando reescaneo masivo. Este proceso puede tardar algunos minutos...'
  router.post('/monitoring/dashboard/scan-all', {}, {
    preserveScroll: true,
    onSuccess: () => {
      actionMessage.value = 'Actualizacion masiva programada. El sistema actualizara resultados por lotes para evitar caidas.'
      isMassScanRunning.value = false
      refreshDashboard()
    },
    onError: () => {
      actionMessage.value = 'No se pudo programar la actualizacion masiva.'
      isMassScanRunning.value = false
    },
    onFinish: () => {
      isMassScanRunning.value = false
    },
  })
}

const formatCheckTime = (value: string | null, status: ReturnType<typeof resolveStatusCode>) => {
  if (!value) {
    return status === 'unknown' ? 'Escaneo en proceso...' : 'Nunca'
  }

  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? 'Nunca' : parsed.toLocaleString('es-MX')
}

const statusPieSeries = computed(() => [
  normalizedStatusCounts.value.UP,
  normalizedStatusCounts.value.DEGRADED,
  normalizedStatusCounts.value.DOWN,
  normalizedStatusCounts.value.UNKNOWN,
])

const statusPieOptions = computed<ApexOptions>(() => ({
  labels: ['Operativos', 'Con incidencias', 'No responde', 'Sin actualizar'],
  legend: {
    position: 'bottom',
    labels: { colors: '#CBD5E1' },
  },
  colors: ['#34D399', '#F59E0B', '#F43F5E', '#64748B'],
  chart: {
    toolbar: { show: false },
    foreColor: '#CBD5E1',
  },
  tooltip: { theme: 'dark' },
}))

const diagnosticBarSeries = computed(() => [{
  name: 'Sitios',
  data: [
    Number(props.diagnosticBreakdown?.operativo ?? 0),
    Number(props.diagnosticBreakdown?.respuesta_lenta ?? 0),
    Number(props.diagnosticBreakdown?.responde_con_errores ?? 0),
    Number(props.diagnosticBreakdown?.inestable ?? 0),
    Number(props.diagnosticBreakdown?.no_responde ?? 0),
    Number(props.diagnosticBreakdown?.sin_actualizar ?? 0),
  ],
}])

const diagnosticBarOptions = computed<ApexOptions>(() => ({
  chart: {
    type: 'bar',
    toolbar: { show: false },
    foreColor: '#CBD5E1',
  },
  colors: ['#06B6D4'],
  xaxis: {
    categories: ['Operativos', 'Respuesta lenta', 'Con errores', 'Inestables', 'No responde', 'Sin actualizar'],
    labels: { style: { colors: '#CBD5E1' } },
  },
  yaxis: {
    labels: { style: { colors: '#94A3B8' } },
  },
  grid: { borderColor: 'rgba(148,163,184,0.2)' },
  plotOptions: {
    bar: {
      borderRadius: 6,
      distributed: true,
    },
  },
}))

type MonitoringEchoChannel = {
  listen: (event: string, callback: (payload: unknown) => void) => unknown
  stopListening: (name: string) => unknown
  unsubscribe: () => unknown
}

let channel: MonitoringEchoChannel | null = null
let pollingInterval: ReturnType<typeof setInterval> | null = null
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null

watch(localSearch, (value, previousValue) => {
  if (value === previousValue) {
    return
  }

  if (searchDebounceTimer) {
    clearTimeout(searchDebounceTimer)
  }

  searchDebounceTimer = setTimeout(() => {
    applySearchDebounced()
  }, 300)
})

onMounted(() => {
  pollingInterval = setInterval(refreshDashboard, props.refreshIntervalMs ?? DEFAULT_REFRESH_INTERVAL_MS)

  const w = window as Window & {
    Echo?: {
      channel: (name: string) => MonitoringEchoChannel
    }
  }

  if (!w.Echo) {
    return
  }

  channel = w.Echo.channel('monitoring.sites')
  channel.listen('.site.status.changed', () => {
    refreshDashboard()
  })
})

onBeforeUnmount(() => {
  if (pollingInterval) {
    clearInterval(pollingInterval)
    pollingInterval = null
  }

  channel?.stopListening('.site.status.changed')
  channel?.unsubscribe()

  if (searchDebounceTimer) {
    clearTimeout(searchDebounceTimer)
    searchDebounceTimer = null
  }
})
</script>
