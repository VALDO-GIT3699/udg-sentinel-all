<template>
  <main class="min-h-screen bg-slate-950 text-slate-100">
    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <header class="mb-8 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-cyan-300">UDG Sentinel</p>
          <h1 class="text-3xl font-semibold text-white sm:text-4xl">Monitoreo de Sitios Oficiales UDG</h1>
          <p class="mt-2 text-sm text-slate-300">
            Estado en tiempo real de sitios oficiales, certificados SSL, cabeceras y alertas activas.
          </p>
        </div>
        <p class="text-xs text-slate-400">Actualizado: {{ formattedUpdatedAt }}</p>
      </header>

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-emerald-300">ACTIVO</p>
          <p class="mt-2 text-3xl font-semibold">{{ normalizedStatusCounts.ACTIVO }}</p>
        </article>
        <article class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-amber-300">DEGRADADO</p>
          <p class="mt-2 text-3xl font-semibold">{{ normalizedStatusCounts.DEGRADADO }}</p>
        </article>
        <article class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-rose-300">CAÍDO</p>
          <p class="mt-2 text-3xl font-semibold">{{ normalizedStatusCounts['CAÍDO'] }}</p>
        </article>
        <article class="rounded-xl border border-slate-500/30 bg-slate-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-slate-300">DESCONOCIDO</p>
          <p class="mt-2 text-3xl font-semibold">{{ normalizedStatusCounts.DESCONOCIDO }}</p>
        </article>
      </section>

      <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-xl border border-cyan-500/30 bg-cyan-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-cyan-300">Chequeos 1h</p>
          <p class="mt-2 text-3xl font-semibold">{{ normalizedPipelineMetrics.totalChecks }}</p>
        </article>
        <article class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-rose-300">Tasa de error 1h</p>
          <p class="mt-2 text-3xl font-semibold">{{ normalizedPipelineMetrics.errorRatePct }}%</p>
        </article>
        <article class="rounded-xl border border-sky-500/30 bg-sky-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-sky-300">Latencia prom. 1h</p>
          <p class="mt-2 text-3xl font-semibold">{{ normalizedPipelineMetrics.avgLatencyMs ?? 'No disponible' }} ms</p>
        </article>
        <article class="rounded-xl border border-indigo-500/30 bg-indigo-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-indigo-300">Cola uptime</p>
          <p class="mt-2 text-3xl font-semibold">{{ normalizedPipelineMetrics.queueDepth['monitoring-uptime'] ?? 'No disponible' }}</p>
        </article>
      </section>

      <section class="mt-6 grid gap-4 xl:grid-cols-3">
        <article class="rounded-xl border border-fuchsia-500/30 bg-fuchsia-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-fuchsia-300">CPU promedio</p>
          <p class="mt-2 text-3xl font-semibold">{{ normalizedPipelineMetrics.resources?.cpuAvgPct ?? 'N/A' }}%</p>
        </article>
        <article class="rounded-xl border border-orange-500/30 bg-orange-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-orange-300">RAM promedio</p>
          <p class="mt-2 text-3xl font-semibold">{{ normalizedPipelineMetrics.resources?.ramAvgPct ?? 'N/A' }}%</p>
        </article>
        <article class="rounded-xl border border-lime-500/30 bg-lime-500/10 p-4">
          <p class="text-xs uppercase tracking-wide text-lime-300">Disco promedio</p>
          <p class="mt-2 text-3xl font-semibold">{{ normalizedPipelineMetrics.resources?.diskAvgPct ?? 'N/A' }}%</p>
        </article>
      </section>

      <section class="mt-6 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <header class="mb-3 flex items-center justify-between">
          <h2 class="text-lg font-semibold">Picos de trafico y latencia (1h)</h2>
          <p class="text-xs text-slate-400">Muestras: {{ normalizedPipelineMetrics.trafficPeaks?.length ?? 0 }}</p>
        </header>

        <div class="flex h-32 items-end gap-1 overflow-hidden rounded-lg border border-slate-800 bg-slate-950 p-2">
          <div
            v-for="(point, idx) in normalizedPipelineMetrics.trafficPeaks"
            :key="`${point.at}-${idx}`"
            class="min-w-[4px] flex-1 rounded-sm bg-cyan-400/80"
            :style="{ height: `${Math.max(4, Math.min(100, Number(point.latencyMs ?? 0) / 5))}%` }"
            :title="`${point.at}: ${point.latencyMs ?? 'N/A'} ms / ${point.rpm ?? 'N/A'} rpm`"
          />
        </div>
      </section>

      <section class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <header class="mb-4">
          <h2 class="text-lg font-semibold">Filtros del tablero</h2>
        </header>

        <form class="grid gap-3 md:grid-cols-5" @submit.prevent="applyFilters">
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
            <option value="ACTIVO">ACTIVO</option>
            <option value="DEGRADADO">DEGRADADO</option>
            <option value="CAÍDO">CAÍDO</option>
            <option value="DESCONOCIDO">DESCONOCIDO</option>
          </select>

          <select
            v-model="localFilters.group_id"
            class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
          >
            <option :value="null">Todos los grupos</option>
            <option v-for="group in normalizedGroups" :key="group.id" :value="group.id">{{ group.name }}</option>
          </select>

          <select
            v-model="localFilters.priority"
            class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
          >
            <option :value="null">Todas las prioridades</option>
            <option :value="1">Critica (1)</option>
            <option :value="2">Alta (2)</option>
            <option :value="3">Media (3)</option>
            <option :value="4">Baja (4)</option>
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
        <header class="mb-4">
          <h2 class="text-lg font-semibold">Vista por grupo</h2>
        </header>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          <a
            v-for="group in normalizedStatusByGroup"
            :key="group.id"
            :href="`/monitoring/groups/${group.id}/view`"
            class="rounded-lg border border-slate-700 bg-slate-900 p-4 transition hover:border-cyan-400"
          >
            <p class="text-sm font-semibold text-white">{{ group.name }}</p>
            <p class="mt-1 text-xs text-slate-400">Monitoreados: {{ group.monitored_sites_count }}</p>
            <p class="mt-3 text-xs text-slate-300">
              ACTIVO {{ group.up_count }} · DEGRADADO {{ group.degraded_count }} · CAÍDO {{ group.down_count }} · DESCONOCIDO {{ group.unknown_count }}
            </p>
          </a>
        </div>
      </section>

      <section class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <header class="mb-4 flex items-center justify-between">
          <h2 class="text-lg font-semibold">Alertas inmediatas</h2>
          <p class="text-sm text-slate-300">
            Abiertas: <span class="font-semibold text-white">{{ openAlertsCount }}</span>
            · Criticas: <span class="font-semibold text-rose-300">{{ criticalAlertsCount }}</span>
          </p>
        </header>

        <ul class="space-y-3">
          <li
            v-for="alert in normalizedOpenAlerts"
            :key="alert.id"
            class="rounded-lg border border-slate-700 bg-slate-900 p-3"
          >
            <p class="text-sm font-medium text-white">{{ alert.title }}</p>
            <p class="mt-1 text-xs text-slate-300">{{ alert.message || 'Sin detalle adicional.' }}</p>
            <p class="mt-2 text-xs text-slate-400">
              Severidad: <span class="uppercase">{{ alert.severity }}</span>
              · Sitio: {{ alert.site?.name || 'No disponible' }}
              · {{ alert.triggered_at }}
            </p>
          </li>
          <li v-if="normalizedOpenAlerts.length === 0" class="rounded-lg border border-slate-800 p-4 text-sm text-slate-300">
            No hay alertas abiertas en este momento.
          </li>
        </ul>
      </section>

      <section class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <header class="mb-4">
          <h2 class="text-lg font-semibold">Timeline operativo (ultima hora)</h2>
        </header>

        <div class="overflow-x-auto">
          <table class="min-w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-700 text-slate-300">
                <th class="px-3 py-2">Hora</th>
                <th class="px-3 py-2">Sitio</th>
                <th class="px-3 py-2">Grupo</th>
                <th class="px-3 py-2">Estado</th>
                <th class="px-3 py-2">HTTP</th>
                <th class="px-3 py-2">Latencia</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="point in normalizedTimeline" :key="point.id" class="border-b border-slate-800">
                <td class="px-3 py-2 text-slate-300">{{ point.checked_at }}</td>
                <td class="px-3 py-2 text-white">{{ point.site?.name || 'No disponible' }}</td>
                <td class="px-3 py-2 text-slate-300">{{ point.site?.site_group?.name || 'Sin grupo' }}</td>
                <td class="px-3 py-2 uppercase" :class="statusClass(point.status_code || point.status)">{{ statusLabel(point.status) }}</td>
                <td class="px-3 py-2 text-slate-300">{{ point.http_code ?? 'No disponible' }}</td>
                <td class="px-3 py-2 text-slate-300">{{ point.response_time_ms ?? 'No disponible' }} ms</td>
              </tr>
              <tr v-if="normalizedTimeline.length === 0">
                <td colspan="6" class="px-3 py-3 text-sm text-slate-400">Sin checks recientes en la ultima hora.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
        <header class="mb-4">
          <h2 class="text-lg font-semibold">Sitios monitoreados</h2>
        </header>

        <div class="overflow-x-auto">
          <table class="min-w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-700 text-slate-300">
                <th class="px-3 py-2">Sitio</th>
                <th class="px-3 py-2">Grupo</th>
                <th class="px-3 py-2">Estado</th>
                <th class="px-3 py-2">Semaforo</th>
                <th class="px-3 py-2">SSL</th>
                <th class="px-3 py-2">Prioridad</th>
                <th class="px-3 py-2">Ultimo check</th>
                <th class="px-3 py-2">Accion</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="site in normalizedSites" :key="site.id">
                <tr class="border-b border-slate-800">
                  <td class="px-3 py-2">
                    <p class="font-medium text-white">{{ site.name }}</p>
                    <p class="text-xs text-slate-400">{{ site.url }}</p>
                  </td>
                  <td class="px-3 py-2 text-slate-300">{{ site.site_group?.name || 'Sin grupo' }}</td>
                  <td class="px-3 py-2">
                    <span
                      class="rounded px-2 py-1 text-xs font-semibold uppercase"
                      :class="statusClass(site.current_status_code || site.current_status)"
                    >
                      {{ statusLabel(site.current_status) }}
                    </span>
                  </td>
                  <td class="px-3 py-2">
                    <span
                      class="rounded px-2 py-1 text-xs font-semibold"
                      :class="protectionBadgeClass(siteTelemetryMap[site.id]?.protectionLevel)"
                    >
                      {{ siteTelemetryMap[site.id]?.protectionLevel ?? 'Sin dato' }}
                    </span>
                  </td>
                  <td class="px-3 py-2 text-slate-300">{{ siteTelemetryMap[site.id]?.ssl.remainingText ?? 'Sin dato SSL' }}</td>
                  <td class="px-3 py-2 text-slate-300">{{ site.priority }}</td>
                  <td class="px-3 py-2 text-slate-400">{{ site.last_checked_at || 'Nunca' }}</td>
                  <td class="px-3 py-2">
                    <div class="flex items-center gap-3">
                      <button class="text-amber-300 hover:text-amber-200" @click="toggleExpanded(site.id)">
                        {{ expandedSiteId === site.id ? 'Ocultar panel' : 'Expandir' }}
                      </button>
                      <a :href="`/monitoring/sites/${site.id}/detail`" class="text-cyan-300 hover:text-cyan-200">Ver detalle</a>
                    </div>
                  </td>
                </tr>

                <tr v-if="expandedSiteId === site.id" class="border-b border-slate-800 bg-slate-950/70">
                  <td colspan="8" class="px-4 py-4">
                    <div class="grid gap-4 lg:grid-cols-2">
                      <article class="rounded-lg border border-slate-800 bg-slate-900 p-3">
                        <h3 class="text-sm font-semibold text-white">Desglose de seguridad</h3>
                        <p class="mt-2 text-xs text-slate-300">Score: {{ siteTelemetryMap[site.id]?.securityScore ?? 0 }}</p>
                        <p class="text-xs text-slate-300">Vulnerabilidades abiertas: {{ siteTelemetryMap[site.id]?.openVulnerabilities ?? 0 }}</p>
                        <p class="text-xs text-slate-300">SSL: {{ siteTelemetryMap[site.id]?.ssl.remainingText ?? 'Sin dato' }}</p>
                      </article>

                      <article class="rounded-lg border border-slate-800 bg-slate-900 p-3">
                        <h3 class="text-sm font-semibold text-white">Recursos del servidor</h3>
                        <p class="mt-2 text-xs text-slate-300">CPU: {{ siteTelemetryMap[site.id]?.resources?.cpuPct ?? 'N/A' }}%</p>
                        <p class="text-xs text-slate-300">RAM: {{ siteTelemetryMap[site.id]?.resources?.ramPct ?? 'N/A' }}%</p>
                        <p class="text-xs text-slate-300">Disco: {{ siteTelemetryMap[site.id]?.resources?.diskPct ?? 'N/A' }}%</p>
                      </article>

                      <article class="rounded-lg border border-slate-800 bg-slate-900 p-3">
                        <h3 class="text-sm font-semibold text-white">Rastreador 404</h3>
                        <ul class="mt-2 space-y-1 text-xs text-slate-300">
                          <li v-for="(broken, idx) in siteTelemetryMap[site.id]?.broken404 || []" :key="idx">
                            {{ broken.route }} - {{ broken.url }}
                          </li>
                          <li v-if="(siteTelemetryMap[site.id]?.broken404 || []).length === 0" class="text-slate-500">
                            No se detectaron rutas 404.
                          </li>
                        </ul>
                      </article>

                      <article class="rounded-lg border border-slate-800 bg-slate-900 p-3">
                        <h3 class="text-sm font-semibold text-white">Firma tecnologica</h3>
                        <ul class="mt-2 space-y-1 text-xs text-slate-300">
                          <li v-for="(tech, idx) in siteTelemetryMap[site.id]?.technologies || []" :key="idx">
                            {{ tech.name }}{{ tech.version ? ` ${tech.version}` : '' }}
                          </li>
                          <li v-if="(siteTelemetryMap[site.id]?.technologies || []).length === 0" class="text-slate-500">
                            Sin tecnologias detectadas recientemente.
                          </li>
                        </ul>
                      </article>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-xs text-slate-400">
            Mostrando {{ normalizedSites.length }} de {{ totalSites }} sitios.
          </p>

          <button
            v-if="hasMoreSites"
            type="button"
            class="rounded-lg border border-cyan-500/40 bg-cyan-500/10 px-4 py-2 text-sm font-semibold text-cyan-200 transition hover:border-cyan-400 hover:bg-cyan-500/20 disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="isLoadingMoreSites"
            @click="loadMoreSites"
          >
            {{ isLoadingMoreSites ? 'Cargando...' : 'Ver más' }}
          </button>
        </div>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'

const INITIAL_PER_PAGE = 50
const PER_PAGE_STEP = 50
const DEFAULT_REFRESH_INTERVAL_MS = 5000

type StatusCounts = {
  ACTIVO: number
  DEGRADADO: number
  'CAÍDO': number
  DESCONOCIDO: number
}

type AlertItem = {
  id: number
  title: string
  message: string | null
  severity: string
  triggered_at: string
  site: {
    id: number
    name: string
  } | null
}

type SiteItem = {
  id: number
  name: string
  url: string
  current_status: 'ACTIVO' | 'DEGRADADO' | 'CAÍDO' | 'DESCONOCIDO'
  current_status_code?: 'up' | 'down' | 'degraded' | 'unknown'
  priority: number
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

type GroupItem = {
  id: number
  name: string
  monitored_sites_count: number
}

type GroupStatusItem = {
  id: number
  name: string
  slug: string
  monitored_sites_count: number
  up_count: number
  degraded_count: number
  down_count: number
  unknown_count: number
}

type PipelineMetrics = {
  window: string
  totalChecks: number
  downChecks: number
  errorRatePct: number
  avgLatencyMs: number | null
  queueDepth: Record<string, number | null>
  trafficPeaks?: Array<{ at: string; latencyMs: number | null; rpm: number | null }>
  resources?: {
    servers: number
    cpuAvgPct: number | null
    ramAvgPct: number | null
    diskAvgPct: number | null
  }
}

type SiteTelemetry = {
  siteId: number
  protectionLevel: 'Expuesto' | 'Bajo' | 'Medio' | 'Alto'
  securityScore: number
  ssl: {
    daysRemaining: number | null
    expiresAt: string | null
    remainingText: string
  }
  broken404: Array<{ url: string; route: string; lastCheckedAt: string | null }>
  technologies: Array<{
    name: string
    slug: string
    category: string
    version: string | null
    isPrimary: boolean
    metadata: Record<string, unknown>
  }>
  traffic: Array<{ at: string; latencyMs: number | null; rpm: number | null }>
  resources: {
    cpuPct: number
    ramPct: number
    diskPct: number
    updatedAt: string | null
  } | null
  openVulnerabilities: number
}

type TimelineItem = {
  id: number
  checked_at: string
  status: 'ACTIVO' | 'DEGRADADO' | 'CAÍDO' | 'DESCONOCIDO'
  status_code?: 'up' | 'down' | 'degraded' | 'unknown' | 'timeout'
  http_code: number | null
  response_time_ms: number | null
  site: {
    name: string
    site_group: {
      name: string
    } | null
  } | null
}

type Filters = {
  status: string
  group_id: number | null
  search: string
  priority: number | null
}

type DashboardProps = {
  filters?: Partial<Filters>
  statusCounts?: Partial<StatusCounts>
  statusByGroup?: GroupStatusItem[]
  pipelineMetrics?: Partial<PipelineMetrics> & {
    total_checks?: number
    down_checks?: number
    error_rate_pct?: number
    avg_latency_ms?: number | null
    queue_depth?: Record<string, number | null>
  }
  groups?: GroupItem[]
  siteTelemetry?: SiteTelemetry[]
  timeline?: TimelineItem[]
  openAlerts?: AlertItem[]
  openAlertsCount?: number
  criticalAlertsCount?: number
  sites?: Paginated<SiteItem> | SiteItem[]
  refreshIntervalMs?: number
  updatedAt?: string
}

const props = defineProps<DashboardProps>()

const localFilters = reactive<Filters>({
  status: toSpanishStatus(props.filters?.status ?? 'all'),
  group_id: props.filters?.group_id ?? null,
  search: props.filters?.search ?? '',
  priority: props.filters?.priority ?? null,
})

const normalizedStatusCounts = computed<StatusCounts>(() => ({
  ACTIVO: props.statusCounts?.ACTIVO ?? 0,
  DEGRADADO: props.statusCounts?.DEGRADADO ?? 0,
  'CAÍDO': props.statusCounts?.['CAÍDO'] ?? 0,
  DESCONOCIDO: props.statusCounts?.DESCONOCIDO ?? 0,
}))

const normalizedPipelineMetrics = computed<PipelineMetrics>(() => ({
  window: props.pipelineMetrics?.window ?? '1h',
  totalChecks: props.pipelineMetrics?.totalChecks ?? props.pipelineMetrics?.total_checks ?? 0,
  downChecks: props.pipelineMetrics?.downChecks ?? props.pipelineMetrics?.down_checks ?? 0,
  errorRatePct: props.pipelineMetrics?.errorRatePct ?? props.pipelineMetrics?.error_rate_pct ?? 0,
  avgLatencyMs: props.pipelineMetrics?.avgLatencyMs ?? props.pipelineMetrics?.avg_latency_ms ?? null,
  queueDepth: props.pipelineMetrics?.queueDepth ?? props.pipelineMetrics?.queue_depth ?? {},
  trafficPeaks: props.pipelineMetrics?.trafficPeaks ?? [],
  resources: props.pipelineMetrics?.resources ?? {
    servers: 0,
    cpuAvgPct: null,
    ramAvgPct: null,
    diskAvgPct: null,
  },
}))

const normalizedGroups = computed<GroupItem[]>(() => props.groups ?? [])
const normalizedStatusByGroup = computed<GroupStatusItem[]>(() => props.statusByGroup ?? [])
const normalizedTimeline = computed<TimelineItem[]>(() => props.timeline ?? [])
const normalizedOpenAlerts = computed<AlertItem[]>(() => props.openAlerts ?? [])
const normalizedSiteTelemetry = computed<SiteTelemetry[]>(() => props.siteTelemetry ?? [])
const siteTelemetryMap = computed<Record<number, SiteTelemetry>>(() => {
  return normalizedSiteTelemetry.value.reduce<Record<number, SiteTelemetry>>((acc, item) => {
    acc[item.siteId] = item
    return acc
  }, {})
})

const normalizedSites = computed<SiteItem[]>(() => {
  if (Array.isArray(props.sites)) {
    return props.sites
  }

  return props.sites?.data ?? []
})

const currentPerPage = computed(() => {
  if (Array.isArray(props.sites)) {
    return Math.max(INITIAL_PER_PAGE, normalizedSites.value.length)
  }

  return props.sites?.per_page ?? INITIAL_PER_PAGE
})

const totalSites = computed(() => {
  if (Array.isArray(props.sites)) {
    return normalizedSites.value.length
  }

  return props.sites?.total ?? normalizedSites.value.length
})

const hasMoreSites = computed(() => {
  if (Array.isArray(props.sites)) {
    return false
  }

  const currentPage = props.sites?.current_page ?? 1
  const lastPage = props.sites?.last_page ?? 1

  return currentPage < lastPage
})

const isLoadingMoreSites = ref(false)
const expandedSiteId = ref<number | null>(null)

const openAlertsCount = computed(() => props.openAlertsCount ?? normalizedOpenAlerts.value.length)
const criticalAlertsCount = computed(() => {
  if (typeof props.criticalAlertsCount === 'number') {
    return props.criticalAlertsCount
  }

  return normalizedOpenAlerts.value.filter((alert) => alert.severity.toLowerCase() === 'critical').length
})

const formattedUpdatedAt = computed(() => {
  if (!props.updatedAt) {
    return 'N/A'
  }

  const parsed = new Date(props.updatedAt)
  return Number.isNaN(parsed.getTime()) ? 'N/A' : parsed.toLocaleString('es-MX')
})

const statusClass = (status: SiteItem['current_status_code'] | TimelineItem['status_code'] | SiteItem['current_status'] | TimelineItem['status']) => {
  if (status === 'ACTIVO') status = 'up'
  if (status === 'DEGRADADO') status = 'degraded'
  if (status === 'CAÍDO') status = 'down'
  if (status === 'DESCONOCIDO') status = 'unknown'

  if (status === 'up') return 'bg-emerald-500/20 text-emerald-300'
  if (status === 'degraded') return 'bg-amber-500/20 text-amber-300'
  if (status === 'down') return 'bg-rose-500/20 text-rose-300'
  return 'bg-slate-500/20 text-slate-300'
}

const protectionBadgeClass = (level?: string) => {
  if (level === 'Expuesto') return 'bg-rose-500/20 text-rose-300 border border-rose-500/40'
  if (level === 'Bajo') return 'bg-amber-500/20 text-amber-300 border border-amber-500/40'
  if (level === 'Medio') return 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/40'
  return 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40'
}

const toggleExpanded = (siteId: number) => {
  expandedSiteId.value = expandedSiteId.value === siteId ? null : siteId
}

const statusLabel = (status: SiteItem['current_status'] | TimelineItem['status'] | SiteItem['current_status_code'] | TimelineItem['status_code']) => {
  if (status === 'ACTIVO' || status === 'up') return 'ACTIVO'
  if (status === 'DEGRADADO' || status === 'degraded') return 'DEGRADADO'
  if (status === 'CAÍDO' || status === 'down') return 'CAÍDO'
  return 'DESCONOCIDO'
}

function toStatusCode (status: string): string {
  if (status === 'ACTIVO') return 'up'
  if (status === 'DEGRADADO') return 'degraded'
  if (status === 'CAÍDO') return 'down'
  if (status === 'DESCONOCIDO') return 'unknown'
  return status
}

function toSpanishStatus (status: string): string {
  if (status === 'up') return 'ACTIVO'
  if (status === 'degraded') return 'DEGRADADO'
  if (status === 'down') return 'CAÍDO'
  if (status === 'unknown') return 'DESCONOCIDO'
  return status
}

const buildDashboardQuery = (perPage: number) => {
  const query: Record<string, string | number> = {
    status: toStatusCode(localFilters.status || 'all'),
    per_page: perPage,
  }

  if (localFilters.group_id !== null) {
    query.group_id = localFilters.group_id
  }

  if (localFilters.search.trim() !== '') {
    query.search = localFilters.search.trim()
  }

  if (localFilters.priority !== null) {
    query.priority = localFilters.priority
  }

  return query
}

const refreshDashboard = () => {
  router.get('/monitoring/dashboard', buildDashboardQuery(currentPerPage.value), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ['sites', 'siteTelemetry', 'statusCounts', 'statusByGroup', 'pipelineMetrics', 'timeline', 'openAlerts', 'openAlertsCount', 'criticalAlertsCount', 'updatedAt'],
  })
}

const applyFilters = () => {
  router.get('/monitoring/dashboard', buildDashboardQuery(INITIAL_PER_PAGE), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

const clearFilters = () => {
  localFilters.status = 'all'
  localFilters.group_id = null
  localFilters.search = ''
  localFilters.priority = null
  applyFilters()
}

const loadMoreSites = () => {
  if (!hasMoreSites.value || isLoadingMoreSites.value) {
    return
  }

  isLoadingMoreSites.value = true

  const nextPerPage = currentPerPage.value + PER_PAGE_STEP
  router.get('/monitoring/dashboard', buildDashboardQuery(nextPerPage), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ['sites', 'siteTelemetry'],
    onFinish: () => {
      isLoadingMoreSites.value = false
    },
  })
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
