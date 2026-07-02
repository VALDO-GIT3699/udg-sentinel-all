<template>
  <main class="min-h-screen bg-slate-950 text-slate-100">
    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <header class="mb-6 flex flex-col gap-2">
        <a href="/monitoring/dashboard" class="text-sm text-cyan-300 hover:text-cyan-200">Volver al dashboard</a>
        <h1 class="text-3xl font-semibold text-white">Grupo: {{ group.name }}</h1>
        <p class="text-sm text-slate-300">Sitios en este grupo sin sub-anidacion. Actualizado: {{ formattedUpdatedAt }}</p>
        <div>
          <button type="button" class="rounded-lg border border-amber-500/50 px-4 py-2 text-sm font-semibold text-amber-200 transition hover:border-amber-300" @click="scanAllSites">
            Recorrer todos
          </button>
        </div>
      </header>

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-emerald-300">Activos</p>
          <p class="mt-2 text-3xl font-semibold">{{ groupStatus('up') }}</p>
        </article>
        <article class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-amber-300">Degradados</p>
          <p class="mt-2 text-3xl font-semibold">{{ groupStatus('degraded') }}</p>
        </article>
        <article class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-rose-300">Caidos</p>
          <p class="mt-2 text-3xl font-semibold">{{ groupStatus('down') }}</p>
        </article>
        <article class="rounded-xl border border-slate-500/30 bg-slate-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-slate-300">Sin clasificar</p>
          <p class="mt-2 text-3xl font-semibold">{{ groupStatus('unknown') }}</p>
        </article>
      </section>

      <section class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <header class="mb-4">
          <h2 class="text-lg font-semibold">Filtros del grupo</h2>
        </header>

        <form class="grid gap-3 md:grid-cols-4" @submit.prevent="applyFilters">
          <input
            v-model="localFilters.search"
            type="text"
            placeholder="Buscar sitio o dominio"
            class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500"
          />

          <select
            v-model="localFilters.status"
            class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
          >
            <option value="all">Todos los estados</option>
            <option value="up">Activo</option>
            <option value="degraded">Degradado</option>
            <option value="down">Caido</option>
            <option value="unknown">Sin clasificar</option>
          </select>

          <select
            v-model="localFilters.priority"
            class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
          >
            <option :value="null">Todas las prioridades</option>
            <option :value="1">Critica</option>
            <option :value="2">Alta</option>
            <option :value="3">Media</option>
            <option :value="4">Baja</option>
          </select>

          <div class="flex items-center gap-2">
            <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-400">
              Aplicar
            </button>
            <button type="button" class="rounded-lg border border-slate-700 px-4 py-2 text-sm text-slate-200 hover:border-slate-500" @click="clearFilters">
              Limpiar
            </button>
          </div>
        </form>
      </section>

      <section class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="mb-4 text-lg font-semibold">Sitios del grupo</h2>
        <div class="overflow-x-auto">
          <table class="min-w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-700 text-slate-300">
                <th class="px-3 py-2">Sitio</th>
                <th class="px-3 py-2">Estado</th>
                <th class="px-3 py-2">Prioridad</th>
                <th class="px-3 py-2">Ultimo check</th>
                <th class="px-3 py-2">Accion</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="site in sites.data" :key="site.id" class="border-b border-slate-800">
                <td class="px-3 py-2">
                  <p class="font-medium text-white">{{ fallbackSiteName(site) }}</p>
                  <a :href="safeSiteUrl(site.url)" target="_blank" rel="noopener noreferrer" class="text-xs text-cyan-300 hover:text-cyan-200">
                    {{ fallbackUrl(site.url) }}
                  </a>
                </td>
                <td class="px-3 py-2">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold uppercase" :class="statusBadgeClass(site.current_status)">
                      {{ statusLabel(site.current_status) }}
                    </span>
                    <span
                      v-if="site.current_status === 'unknown'"
                      class="inline-flex items-center gap-1 rounded-full bg-cyan-400/10 px-2 py-1 text-[11px] font-medium text-cyan-200"
                    >
                      <span class="h-1.5 w-1.5 rounded-full bg-cyan-300 animate-pulse" />
                      Escaneo en proceso
                    </span>
                  </div>
                </td>
                <td class="px-3 py-2 text-slate-300">{{ fallbackPriority(site.priority) }}</td>
                <td class="px-3 py-2 text-slate-400">{{ formatCheckTime(site.last_checked_at, site.current_status) }}</td>
                <td class="px-3 py-2">
                  <div class="flex items-center gap-3">
                    <a :href="`/monitoring/sites/${site.id}/detail`" class="text-cyan-300 hover:text-cyan-200">Ver detalle</a>
                    <button type="button" class="rounded border border-cyan-700 px-2 py-1 text-xs text-cyan-200 hover:border-cyan-500" @click="scanSingleSite(site.id)">
                      Reescanear
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="sites.data.length === 0">
                <td colspan="5" class="px-3 py-3 text-sm text-slate-400">No hay sitios para estos filtros.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <footer class="mt-4 flex items-center justify-between text-xs text-slate-400">
          <p>Mostrando {{ sites.data.length }} de {{ sites.total }} sitios.</p>
          <div class="flex items-center gap-2">
            <a
              v-if="sites.prev_page_url"
              :href="sites.prev_page_url"
              class="rounded border border-slate-700 px-3 py-1 hover:border-slate-500"
            >Anterior</a>
            <a
              v-if="sites.next_page_url"
              :href="sites.next_page_url"
              class="rounded border border-slate-700 px-3 py-1 hover:border-slate-500"
            >Siguiente</a>
          </div>
        </footer>
      </section>

      <section class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <h2 class="mb-4 text-lg font-semibold">Alertas abiertas del grupo</h2>
        <ul class="space-y-3">
          <li v-for="alert in openAlerts" :key="alert.id" class="rounded border border-slate-700 p-3">
            <p class="text-sm font-medium text-white">{{ alert.title }}</p>
            <p class="mt-1 text-xs text-slate-300">{{ alert.message || 'Sin detalle adicional.' }}</p>
            <p class="mt-2 text-xs text-slate-400">{{ alert.severity }} · {{ alert.triggered_at }}</p>
          </li>
          <li v-if="openAlerts.length === 0" class="rounded border border-slate-800 p-3 text-sm text-slate-400">
            No hay alertas abiertas para este grupo.
          </li>
        </ul>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'

type SiteItem = {
  id: number
  name: string | null
  url: string | null
  current_status: 'up' | 'down' | 'degraded' | 'unknown'
  priority: number | null
  last_checked_at: string | null
}

type GroupItem = {
  id: number
  name: string
}

type AlertItem = {
  id: number
  title: string
  message: string | null
  severity: string
  triggered_at: string
}

type Filters = {
  status: string
  search: string
  priority: number | null
  group_id: number
}

type Paginated<T> = {
  data: T[]
  total: number
  prev_page_url: string | null
  next_page_url: string | null
}

const props = defineProps<{
  group: GroupItem
  filters: Filters
  sites: Paginated<SiteItem>
  statusCounts: Record<string, number>
  openAlerts: AlertItem[]
  updatedAt: string
}>()

const localFilters = reactive<Filters>({
  ...props.filters,
})

const formattedUpdatedAt = computed(() => new Date(props.updatedAt).toLocaleString('es-MX'))

const groupStatus = (status: SiteItem['current_status']) => {
  return props.statusCounts[status] ?? 0
}

const statusBadgeClass = (status: SiteItem['current_status']) => {
  if (status === 'up') return 'bg-emerald-500/15 text-emerald-300'
  if (status === 'degraded') return 'bg-amber-500/15 text-amber-300'
  if (status === 'down') return 'bg-rose-500/15 text-rose-300'
  return 'bg-slate-600/30 text-slate-300'
}

const statusLabel = (status: SiteItem['current_status']) => {
  if (status === 'up') return 'ACTIVO'
  if (status === 'degraded') return 'DEGRADADO'
  if (status === 'down') return 'CAIDO'
  return 'SIN CLASIFICAR'
}

const fallbackSiteName = (site: SiteItem) => site.name?.trim() || site.url?.trim() || `Sitio #${site.id}`

const fallbackUrl = (url: string | null) => url?.trim() || 'Sin URL registrada'

const fallbackPriority = (priority: number | null) => {
  if (priority === 1) return 'Critica'
  if (priority === 2) return 'Alta'
  if (priority === 3) return 'Media'
  if (priority === 4) return 'Baja'
  return 'Sin definir'
}

const safeSiteUrl = (url: string | null) => {
  const value = url?.trim() || ''
  if (value === '') {
    return '#'
  }

  return value.startsWith('http://') || value.startsWith('https://') ? value : `https://${value}`
}

const formatCheckTime = (value: string | null, status: SiteItem['current_status']) => {
  if (!value) {
    return status === 'unknown' ? 'Escaneo en proceso...' : 'Nunca'
  }

  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? 'Nunca' : parsed.toLocaleString('es-MX')
}

const applyFilters = () => {
  router.get(`/monitoring/groups/${props.group.id}/view`, {
    status: localFilters.status,
    search: localFilters.search,
    priority: localFilters.priority,
  }, {
    preserveState: true,
    preserveScroll: true,
  })
}

const clearFilters = () => {
  localFilters.status = 'all'
  localFilters.search = ''
  localFilters.priority = null
  applyFilters()
}

const scanSingleSite = (siteId: number) => {
  router.post(`/monitoring/sites/${siteId}/scan`, {}, {
    preserveScroll: true,
  })
}

const scanAllSites = () => {
  router.post('/monitoring/dashboard/scan-all', {}, {
    preserveScroll: true,
  })
}
</script>
