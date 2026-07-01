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

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border border-emerald-500/25 bg-emerald-500/10 p-5">
          <p class="text-xs uppercase tracking-[0.18em] text-emerald-300">UP</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ normalizedStatusCounts.UP }}</p>
        </article>
        <article class="rounded-2xl border border-amber-500/25 bg-amber-500/10 p-5">
          <p class="text-xs uppercase tracking-[0.18em] text-amber-300">DEGRADED</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ normalizedStatusCounts.DEGRADED }}</p>
        </article>
        <article class="rounded-2xl border border-rose-500/25 bg-rose-500/10 p-5">
          <p class="text-xs uppercase tracking-[0.18em] text-rose-300">DOWN</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ normalizedStatusCounts.DOWN }}</p>
        </article>
        <article class="rounded-2xl border border-slate-600/40 bg-slate-800/70 p-5">
          <p class="text-xs uppercase tracking-[0.18em] text-slate-300">UNKNOWN</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ normalizedStatusCounts.UNKNOWN }}</p>
        </article>
      </section>

      <section class="mt-8 rounded-3xl border border-slate-800 bg-slate-900/80 p-5 shadow-[0_18px_60px_rgba(15,23,42,0.35)]">
        <header class="flex flex-col gap-4 border-b border-slate-800 pb-5 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <h2 class="text-xl font-semibold text-white">Sitios monitoreados</h2>
            <p class="mt-1 text-sm text-slate-400">Un clic abre el detalle completo del sitio.</p>
          </div>

          <form class="flex w-full max-w-xl flex-col gap-3 sm:flex-row" @submit.prevent="applySearch">
            <label class="sr-only" for="dashboard-search">Buscar sitio o dominio</label>
            <input
              id="dashboard-search"
              v-model="localSearch"
              type="search"
              autocomplete="off"
              placeholder="Buscar sitio o dominio"
              class="h-11 flex-1 rounded-xl border border-slate-700 bg-slate-950 px-4 text-sm text-slate-100 placeholder:text-slate-500 focus:border-cyan-400 focus:outline-none"
            />
            <div class="flex gap-2">
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
                <th class="px-4 py-4 font-medium">Grupo</th>
                <th class="px-4 py-4 font-medium">Estado</th>
                <th class="px-4 py-4 font-medium">Prioridad</th>
                <th class="px-4 py-4 font-medium">Último check</th>
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
                <td class="px-4 py-4 text-slate-300">{{ fallbackDomain(site.domain) }}</td>
                <td class="px-4 py-4 text-slate-300">{{ fallbackGroup(site.site_group?.name) }}</td>
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
                <td class="px-4 py-4 text-slate-300">{{ fallbackPriority(site.priority) }}</td>
                <td class="px-4 py-4 text-slate-400">{{ formatCheckTime(site.last_checked_at, resolveStatusCode(site)) }}</td>
              </tr>
              <tr v-if="normalizedSites.length === 0">
                <td colspan="6" class="px-4 py-12 text-center text-sm text-slate-400">
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
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'

const DEFAULT_REFRESH_INTERVAL_MS = 5000
const DEFAULT_PER_PAGE = 50

type StatusCounts = {
  UP: number
  DEGRADED: number
  DOWN: number
  UNKNOWN: number
}

type SiteItem = {
  id: number
  name: string | null
  domain: string | null
  current_status: string
  current_status_code?: string
  priority: number | null
  last_checked_at: string | null
  site_group: {
    id: number
    name: string
  } | null
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
}

type DashboardProps = {
  filters?: Partial<Filters>
  statusCounts?: Partial<StatusCounts>
  sites?: Paginated<SiteItem> | SiteItem[]
  refreshIntervalMs?: number
  updatedAt?: string
}

const props = defineProps<DashboardProps>()

const localSearch = ref(props.filters?.search ?? '')

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
  return 'bg-slate-600/30 text-slate-300'
}

const statusLabel = (status: ReturnType<typeof resolveStatusCode>) => {
  if (status === 'up') return 'UP'
  if (status === 'degraded') return 'DEGRADED'
  if (status === 'down') return 'DOWN'
  return 'UNKNOWN'
}

const fallbackSiteName = (site: SiteItem) => site.name?.trim() || site.domain?.trim() || `Sitio #${site.id}`

const fallbackDomain = (domain: string | null) => domain?.trim() || 'Sin dominio'

const fallbackGroup = (groupName: string | undefined) => groupName?.trim() || 'Sin grupo'

const fallbackPriority = (priority: number | null) => priority ?? 'N/D'

const buildDashboardQuery = (page = 1) => {
  const query: Record<string, string | number> = {
    page,
    per_page: currentPerPage.value || DEFAULT_PER_PAGE,
  }

  if (localSearch.value.trim() !== '') {
    query.search = localSearch.value.trim()
  }

  return query
}

const refreshDashboard = () => {
  router.get('/monitoring/dashboard', buildDashboardQuery(currentPage.value), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ['sites', 'statusCounts', 'updatedAt'],
  })
}

const applySearch = () => {
  router.get('/monitoring/dashboard', buildDashboardQuery(1), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

const clearSearch = () => {
  localSearch.value = ''
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
    only: ['sites', 'statusCounts', 'updatedAt'],
  })
}

const openSiteDetail = (siteId: number) => {
  router.visit(`/monitoring/sites/${siteId}/detail`)
}

const formatCheckTime = (value: string | null, status: ReturnType<typeof resolveStatusCode>) => {
  if (!value) {
    return status === 'unknown' ? 'Escaneo en proceso...' : 'Nunca'
  }

  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? 'Nunca' : parsed.toLocaleString('es-MX')
}

let channel: { stopListening: (name: string) => unknown; unsubscribe: () => unknown } | null = null
let pollingInterval: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  pollingInterval = setInterval(refreshDashboard, props.refreshIntervalMs ?? DEFAULT_REFRESH_INTERVAL_MS)

  const w = window as Window & {
    Echo?: {
      channel: (name: string) => {
        listen: (event: string, callback: (payload: unknown) => void) => unknown
        stopListening: (event: string) => unknown
        unsubscribe: () => unknown
      }
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
})
</script>
