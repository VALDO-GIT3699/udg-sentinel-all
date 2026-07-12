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

      <section class="mb-5 flex flex-wrap items-center gap-3">
        <button
          type="button"
          class="h-11 rounded-xl border border-cyan-400/50 bg-cyan-500/10 px-4 text-sm font-semibold text-cyan-100 transition hover:border-cyan-300 hover:bg-cyan-500/20 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="isExporting"
          @click="exportDashboardPdf"
        >
          {{ isExporting ? 'Exportando...' : '📄 Exportar PDF Editable' }}
        </button>
        <button
          type="button"
          class="h-11 rounded-xl border border-amber-500/60 bg-amber-500/10 px-4 text-sm font-semibold text-amber-100 transition hover:border-amber-300 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="isMassScanRunning"
          @click="scanAllSites"
        >
          {{ isMassScanRunning ? 'Escaneo masivo en ejecución...' : 'Iniciar escaneo masivo' }}
        </button>
        <p class="text-xs text-slate-400">Revalida disponibilidad, SSL, cabeceras y tecnologías en todos los sitios.</p>
      </section>

      <section v-if="canManageSettings" class="mb-6 rounded-2xl border border-slate-700 bg-slate-900/80 p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.18em] text-slate-400">Modo de ejecución</p>
            <h2 class="mt-1 text-lg font-semibold text-white">Escaneos programados</h2>
            <p class="mt-1 text-sm text-slate-300">Controla si el scheduler ejecuta ciclos automáticos o si todo se maneja solo manualmente.</p>
          </div>
          <button
            type="button"
            class="h-10 rounded-xl border px-4 text-sm font-semibold transition disabled:opacity-60"
            :class="scheduledScansEnabledLocal ? 'border-emerald-500/60 bg-emerald-500/10 text-emerald-200 hover:border-emerald-300' : 'border-rose-500/60 bg-rose-500/10 text-rose-200 hover:border-rose-300'"
            :disabled="isUpdatingScheduledScans"
            @click="toggleScheduledScans"
          >
            {{ isUpdatingScheduledScans ? 'Guardando...' : (scheduledScansEnabledLocal ? 'Programados ACTIVOS' : 'Programados DESACTIVADOS') }}
          </button>
        </div>
      </section>

      <section v-if="actionMessage" class="mb-4 rounded-xl border border-cyan-500/40 bg-cyan-500/10 px-4 py-3 text-sm text-cyan-100">
        {{ actionMessage }}
      </section>

      <section v-if="isMassScanRunning" class="mb-4 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
        Snapshot estable activo: la lista y los conteos quedan congelados durante la corrida para evitar saltos visuales. Se actualizarán automáticamente al finalizar.
      </section>

      <section v-if="showMassScanOverlay" class="mb-6 rounded-2xl border border-cyan-500/40 bg-slate-900/95 p-5 shadow-[0_15px_45px_rgba(8,145,178,0.15)]">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p class="text-xs uppercase tracking-[0.22em] text-cyan-300">Escaneo masivo en curso</p>
            <h2 class="mt-2 text-xl font-semibold text-white">Revalidando todos los sitios y tecnologías</h2>
            <p class="mt-1 text-sm text-slate-300">
              Completado {{ massScanCompletedTasks }} de {{ massScanTotalTasks }} tareas · Restantes {{ massScanRemainingTasks }}
            </p>
          </div>
          <div class="text-right">
            <p class="text-3xl font-semibold text-cyan-200">{{ massScanProgressPct.toFixed(1) }}%</p>
            <p class="mt-1 text-xs text-slate-400">Inicio: {{ massScanStartedAt }}</p>
          </div>
        </div>

        <div class="mt-4 h-3 w-full overflow-hidden rounded-full bg-slate-800">
          <div class="h-full rounded-full bg-gradient-to-r from-cyan-400 via-emerald-300 to-cyan-200 transition-all duration-300" :style="{ width: `${massScanProgressPct}%` }" />
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-2">
          <div v-for="stage in massScanStageRows" :key="stage.key" class="rounded-xl border border-slate-700/80 bg-slate-950/60 p-3">
            <div class="mb-2 flex items-center justify-between text-xs">
              <span class="font-semibold uppercase tracking-[0.16em] text-slate-300">{{ stage.label }}</span>
              <span class="text-slate-400">{{ stage.completed }}/{{ stage.total }} · faltan {{ stage.remaining }}</span>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-slate-800">
              <div class="h-full rounded-full bg-cyan-400 transition-all duration-300" :style="{ width: `${stage.progressPct}%` }" />
            </div>
          </div>
        </div>
      </section>

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <button type="button" class="rounded-2xl border border-emerald-500/25 bg-emerald-500/10 p-5 text-left transition hover:border-emerald-300/60 disabled:cursor-not-allowed disabled:opacity-60" :title="'Sitios que responden dentro de los parametros esperados.'" :disabled="isSnapshotFrozen" @click="setStatusFilter('up')">
          <p class="text-xs uppercase tracking-[0.18em] text-emerald-300">Operativos</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ normalizedStatusCounts.UP }}</p>
        </button>
        <button type="button" class="rounded-2xl border border-amber-500/25 bg-amber-500/10 p-5 text-left transition hover:border-amber-300/60 disabled:cursor-not-allowed disabled:opacity-60" :title="'Sitios que responden, pero con degradacion o restricciones temporales.'" :disabled="isSnapshotFrozen" @click="setStatusFilter('degraded')">
          <p class="text-xs uppercase tracking-[0.18em] text-amber-300">Con incidencias</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ normalizedStatusCounts.DEGRADED }}</p>
        </button>
        <button type="button" class="rounded-2xl border border-rose-500/25 bg-rose-500/10 p-5 text-left transition hover:border-rose-300/60 disabled:cursor-not-allowed disabled:opacity-60" :title="'Sitios que no responden o fallan la verificacion principal.'" :disabled="isSnapshotFrozen" @click="setStatusFilter('down')">
          <p class="text-xs uppercase tracking-[0.18em] text-rose-300">No responde</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ normalizedStatusCounts.DOWN }}</p>
        </button>
        <button type="button" class="rounded-2xl border border-amber-500/20 bg-amber-500/8 p-5 text-left transition hover:border-amber-300/50 disabled:cursor-not-allowed disabled:opacity-60" :title="'Sitios sin una medicion reciente o aun en proceso de escaneo.'" :disabled="isSnapshotFrozen" @click="setStatusFilter('unknown')">
          <p class="text-xs uppercase tracking-[0.18em] text-slate-300">Sin actualizar</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ normalizedStatusCounts.UNKNOWN }}</p>
        </button>
      </section>

      <section class="mt-6 rounded-3xl border border-slate-800 bg-slate-900/80 p-5 shadow-[0_18px_60px_rgba(15,23,42,0.35)]">
        <header class="mb-4 flex flex-col gap-3 border-b border-slate-800 pb-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.22em] text-cyan-300">📅 Calendario de Vencimientos Preventivos</p>
            <h2 class="mt-1 text-xl font-semibold text-white">Certificados SSL por renovar en los próximos 60 días</h2>
            <p class="mt-1 text-sm text-slate-400">Vista cronológica para anticipar renovaciones y evitar interrupciones.</p>
          </div>
          <p class="text-xs text-slate-500">Ordenado por fecha de expiración</p>
        </header>

        <div v-if="preventiveCalendarGroups.length === 0" class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-400">
          No hay certificados SSL que expiren en los próximos 60 días.
        </div>

        <div v-else class="space-y-6">
          <section v-for="group in visiblePreventiveCalendarGroups" :key="group.monthKey" class="space-y-3">
            <div class="flex items-center justify-between gap-3">
              <h3 class="text-lg font-semibold text-white">{{ group.monthLabel }}</h3>
              <span class="rounded-full bg-cyan-500/10 px-3 py-1 text-xs font-semibold text-cyan-200">{{ group.items.length }} vencimientos</span>
            </div>

            <div class="space-y-3">
              <article v-for="item in group.items" :key="item.id" class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                  <div>
                    <p class="text-sm font-semibold text-white">{{ item.site_name }} · {{ item.domain }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ formatDate(item.valid_until) }}</p>
                  </div>
                  <div class="text-sm text-slate-300">
                    <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="item.days_remaining <= 15 ? 'bg-rose-500/15 text-rose-200' : item.days_remaining <= 30 ? 'bg-amber-500/15 text-amber-200' : 'bg-emerald-500/15 text-emerald-200'">
                      Quedan {{ item.days_remaining }} días
                    </span>
                  </div>
                </div>
              </article>
            </div>

            <button
              v-if="preventiveCalendarHiddenCount(group) > 0"
              type="button"
              class="inline-flex items-center gap-2 rounded-full border border-slate-700 px-4 py-2 text-xs font-semibold text-slate-200 transition hover:border-cyan-400 hover:text-cyan-100"
              @click="toggleCalendarMonth(group.monthKey)"
            >
              <span v-if="!isCalendarExpanded(group.monthKey)">Ver más (+{{ preventiveCalendarHiddenCount(group) }})</span>
              <span v-else>Ver menos</span>
            </button>
          </section>
        </div>
      </section>

      <section class="mt-6 grid gap-6 xl:grid-cols-2">
        <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
            <h2 class="text-lg font-semibold text-white">Distribución por estado</h2>
          <VueApexCharts type="pie" height="280" :options="statusPieOptions" :series="statusPieSeries" />
        </article>
        <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
            <h2 class="text-lg font-semibold text-white">Panorama de diagnóstico operativo</h2>
          <VueApexCharts type="bar" height="280" :options="diagnosticBarOptions" :series="diagnosticBarSeries" />
        </article>
      </section>

      <section class="mt-8 rounded-3xl border border-slate-800 bg-slate-900/80 p-5 shadow-[0_18px_60px_rgba(15,23,42,0.35)]">
        <header class="flex flex-col gap-4 border-b border-slate-800 pb-5 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <h2 class="text-xl font-semibold text-white">Sitios monitoreados</h2>
            <p class="mt-1 text-sm text-slate-400">Un clic abre el detalle, y el dominio abre el sitio en otra pestaña.</p>
          </div>

          <form class="flex w-full max-w-xl flex-col gap-3 sm:flex-row" @submit.prevent="applySearch">
            <label class="sr-only" for="dashboard-search">Buscar sitio o dominio</label>
            <input
              id="dashboard-search"
              v-model="localSearch"
              list="monitoring-site-suggestions"
              type="search"
              autocomplete="off"
              :disabled="isSnapshotFrozen"
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
                {{ isMassScanRunning ? 'Escaneo en ejecución...' : 'Iniciar escaneo masivo' }}
              </button>
              <button
                type="button"
                class="h-11 rounded-xl border border-emerald-500/50 px-4 text-sm font-semibold text-emerald-200 transition hover:border-emerald-300 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="isMassScanRunning || selectedSiteIds.length === 0"
                @click="scanSelectedSites"
              >
                Escanear seleccionados ({{ selectedSiteIds.length }})
              </button>
              <button
                type="submit"
                class="h-11 rounded-xl bg-cyan-400 px-4 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300"
                :disabled="isSnapshotFrozen"
              >
                Buscar
              </button>
              <button
                type="button"
                class="h-11 rounded-xl border border-slate-700 px-4 text-sm text-slate-200 transition hover:border-slate-500"
                :disabled="isSnapshotFrozen"
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
                <th class="px-4 py-4 font-medium">
                  <input
                    type="checkbox"
                    class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-cyan-400 focus:ring-cyan-500"
                    :checked="isAllVisibleSelected"
                    :indeterminate.prop="isSomeVisibleSelected && !isAllVisibleSelected"
                    @click.stop
                    @change="toggleSelectVisible"
                  />
                </th>
                <th class="px-4 py-4 font-medium">Sitio</th>
                <th class="px-4 py-4 font-medium">Dominio</th>
                <th class="px-4 py-4 font-medium">Tecnología</th>
                <th class="px-4 py-4 font-medium">Certificado</th>
                <th class="px-4 py-4 font-medium">Estado operativo</th>
                <th class="px-4 py-4 font-medium">Diagnóstico actual</th>
                <th class="px-4 py-4 font-medium">Detalle técnico</th>
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
                <td class="px-4 py-4" @click.stop>
                  <input
                    type="checkbox"
                    class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-cyan-400 focus:ring-cyan-500"
                    :checked="isSiteSelected(site.id)"
                    @click.stop
                    @change="toggleSelectedSite(site.id)"
                  />
                </td>
                <td class="px-4 py-4 align-middle">
                  <p class="font-medium text-white">{{ fallbackSiteName(site) }}</p>
                  <p class="mt-1 text-xs text-slate-500">Abrir detalle</p>
                </td>
                <td class="px-4 py-4 text-slate-300">
                  <a :href="safeSiteUrl(site)" target="_blank" rel="noopener noreferrer" class="text-cyan-300 hover:text-cyan-200" @click.stop>
                    {{ fallbackDomain(site.domain) }}
                  </a>
                </td>
                <td class="px-4 py-4 text-slate-300">
                  <span
                    class="rounded-full bg-cyan-500/10 px-2.5 py-1 text-xs font-semibold text-cyan-200"
                    :title="technologyTooltip(site)"
                  >
                    {{ site.technology_label || 'No identificada' }}
                  </span>
                </td>
                <td class="px-4 py-4 text-slate-300">
                  <span
                    class="rounded-full px-2.5 py-1 text-xs font-semibold"
                    :class="certificateBadgeClass(site)"
                  >
                    {{ certificateLabel(site) }}
                  </span>
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
                <td colspan="10" class="px-4 py-12 text-center text-sm text-slate-400">
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
              :disabled="currentPage <= 1 || isSnapshotFrozen"
              @click="goToPage(currentPage - 1)"
            >
              Anterior
            </button>
            <button
              type="button"
              class="rounded-xl border border-slate-700 px-4 py-2 text-sm text-slate-200 transition hover:border-slate-500 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="currentPage >= lastPage || isSnapshotFrozen"
              @click="goToPage(currentPage + 1)"
            >
              Siguiente
            </button>
          </div>
        </footer>
      </section>

      <section class="mt-8 rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
        <header class="mb-4">
          <h2 class="text-xl font-semibold text-white">Histórico de escaneos masivos</h2>
          <p class="mt-1 text-sm text-slate-400">Auditoría de quién lanzó cada ejecución, cuándo inició y cómo terminó.</p>
        </header>

        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
            <thead>
              <tr class="text-slate-400">
                <th class="px-4 py-3 font-medium">Inicio</th>
                <th class="px-4 py-3 font-medium">Usuario</th>
                <th class="px-4 py-3 font-medium">Modo</th>
                <th class="px-4 py-3 font-medium">Estado</th>
                <th class="px-4 py-3 font-medium">Avance</th>
                <th class="px-4 py-3 font-medium">Fin</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/80">
              <tr v-for="run in massScanHistoryNormalized" :key="run.run_id">
                <td class="px-4 py-3 text-slate-300">{{ formatDateTime(run.started_at) }}</td>
                <td class="px-4 py-3 text-slate-300">{{ run.initiated_by || 'Sistema' }}</td>
                <td class="px-4 py-3 text-slate-300">{{ run.trigger_mode === 'manual' ? 'Manual' : 'Programado' }}</td>
                <td class="px-4 py-3">
                  <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="massScanStatusClass(run.status)">
                    {{ massScanStatusLabel(run.status) }}
                  </span>
                </td>
                <td class="px-4 py-3 text-slate-300">{{ run.completed_tasks }}/{{ run.total_tasks }} · fallos {{ run.failed_tasks }}</td>
                <td class="px-4 py-3 text-slate-400">{{ formatDateTime(run.completed_at) }}</td>
              </tr>
              <tr v-if="massScanHistoryNormalized.length === 0">
                <td colspan="6" class="px-4 py-10 text-center text-sm text-slate-400">Sin ejecuciones registradas todavía.</td>
              </tr>
            </tbody>
          </table>
        </div>
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
  ssl_certificate?: {
    valid_until: string | null
    issuer: string | null
    days_remaining: number | null
    algorithm: string | null
    is_expired?: boolean
  } | null
  current_status: string
  current_status_code?: string
  display_status_code?: string
  last_checked_at: string | null
  diagnostic_bucket?: string | null
  diagnostic_label?: string | null
  diagnostic_reason?: string | null
  technology_name?: string | null
  technology_version?: string | null
  technology_label?: string | null
  technology_category_label?: string | null
  technology_confidence?: number | null
  technology_badge_state?: 'danger' | 'success' | null
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
  massScanProgress?: MassScanProgressPayload | null
  massScanHistory?: MassScanHistoryItem[]
  preventiveExpirations?: PreventiveExpirationItem[]
  scheduledScansEnabled?: boolean
  canManageSettings?: boolean
  refreshIntervalMs?: number
  updatedAt?: string
}

type MassScanStage = {
  completed: number
  failed?: number
  total: number
  remaining: number
  progress_pct: number
}

type MassScanProgressPayload = {
  run_id: string
  status: 'running' | 'completed_ok' | 'completed_with_errors' | 'incomplete'
  started_at: string
  last_progress_at?: string | null
  completed_at?: string | null
  total_sites: number
  total_tasks: number
  completed_tasks: number
  failed_tasks?: number
  remaining_tasks: number
  progress_pct: number
  stages: Record<'uptime' | 'ssl' | 'headers' | 'technology', MassScanStage>
}

type MassScanHistoryItem = {
  run_id: string
  trigger_mode: 'manual' | 'scheduled' | string
  status: 'running' | 'completed_ok' | 'completed_with_errors' | 'incomplete' | string
  total_sites: number
  total_tasks: number
  completed_tasks: number
  failed_tasks: number
  started_at: string | null
  last_progress_at: string | null
  completed_at: string | null
  initiated_by: string | null
}

type PreventiveExpirationItem = {
  id: number
  site_name: string
  domain: string
  valid_until: string | null
  days_remaining: number
  issuer: string | null
  month_label: string | null
}

type PreventiveCalendarGroup = {
  monthKey: string
  monthLabel: string
  items: PreventiveExpirationItem[]
}

const props = defineProps<DashboardProps>()

type DashboardSnapshot = {
  sites?: Paginated<SiteItem> | SiteItem[]
  statusCounts?: StatusCountPayload
  diagnosticBreakdown?: DashboardProps['diagnosticBreakdown']
  updatedAt?: string
}

const localSearch = ref(props.filters?.search ?? '')
const localStatus = ref((props.filters?.status ?? 'all').toString())
const actionMessage = ref('')
const massScanProgress = ref<MassScanProgressPayload | null>(props.massScanProgress ?? null)
const suggestionsState = ref<string[]>(Array.isArray(props.searchSuggestions) ? props.searchSuggestions : [])
const selectedSiteIdsSet = ref<Set<number>>(new Set())
const suggestions = computed(() => suggestionsState.value)
const canManageSettings = computed(() => Boolean(props.canManageSettings))
const scheduledScansEnabledLocal = ref(Boolean(props.scheduledScansEnabled ?? true))
const isUpdatingScheduledScans = ref(false)
const hasAnnouncedActiveRun = ref(false)
const dashboardSnapshot = ref<DashboardSnapshot | null>(null)

const massScanHistoryNormalized = computed<MassScanHistoryItem[]>(() => Array.isArray(props.massScanHistory) ? props.massScanHistory : [])
const preventiveExpirationsNormalized = computed<PreventiveExpirationItem[]>(() => Array.isArray(props.preventiveExpirations) ? props.preventiveExpirations : [])
const isExporting = ref(false)
const expandedCalendarMonths = ref<Set<string>>(new Set())
const preventiveCalendarGroups = computed<PreventiveCalendarGroup[]>(() => {
  const formatter = new Intl.DateTimeFormat('es-MX', { month: 'long', year: 'numeric' })
  const groups = new Map<string, PreventiveExpirationItem[]>()

  for (const item of preventiveExpirationsNormalized.value) {
    const monthKey = item.month_label || (item.valid_until ? item.valid_until.slice(0, 7) : 'sin-fecha')

    if (!groups.has(monthKey)) {
      groups.set(monthKey, [])
    }

    groups.get(monthKey)?.push(item)
  }

  return Array.from(groups.entries()).map(([monthKey, items]) => {
    const sortedItems = [...items].sort((left, right) => {
      const leftTime = left.valid_until ? new Date(left.valid_until).getTime() : 0
      const rightTime = right.valid_until ? new Date(right.valid_until).getTime() : 0
      return leftTime - rightTime
    })

    const referenceDate = sortedItems[0]?.valid_until ? new Date(sortedItems[0].valid_until) : new Date(`${monthKey}-01T00:00:00`)

    return {
      monthKey,
      monthLabel: Number.isNaN(referenceDate.getTime()) ? monthKey : formatter.format(referenceDate),
      items: sortedItems,
    }
  })
})

const visiblePreventiveCalendarGroups = computed<PreventiveCalendarGroup[]>(() => {
  return preventiveCalendarGroups.value.map((group) => ({
    ...group,
    items: expandedCalendarMonths.value.has(group.monthKey) ? group.items : group.items.slice(0, 3),
  }))
})

const preventiveCalendarHiddenCount = (group: PreventiveCalendarGroup) => Math.max(0, group.items.length - 3)

const isCalendarExpanded = (monthKey: string) => expandedCalendarMonths.value.has(monthKey)

const toggleCalendarMonth = (monthKey: string) => {
  const next = new Set(expandedCalendarMonths.value)

  if (next.has(monthKey)) {
    next.delete(monthKey)
  } else {
    next.add(monthKey)
  }

  expandedCalendarMonths.value = next
}

const isMassScanRunning = computed(() => massScanProgress.value?.status === 'running')
const isSnapshotFrozen = computed(() => isMassScanRunning.value)

const clonePayload = <T>(value: T): T => {
  if (value === undefined || value === null) {
    return value
  }

  return JSON.parse(JSON.stringify(value)) as T
}

const captureDashboardSnapshot = () => {
  dashboardSnapshot.value = {
    sites: clonePayload(props.sites),
    statusCounts: clonePayload(props.statusCounts),
    diagnosticBreakdown: clonePayload(props.diagnosticBreakdown),
    updatedAt: props.updatedAt,
  }
}

const clearDashboardSnapshot = () => {
  dashboardSnapshot.value = null
}

const effectiveSites = computed<Paginated<SiteItem> | SiteItem[] | undefined>(() => {
  if (isMassScanRunning.value && dashboardSnapshot.value?.sites !== undefined) {
    return dashboardSnapshot.value.sites
  }

  return props.sites
})

const effectiveStatusCounts = computed<StatusCountPayload | undefined>(() => {
  if (isMassScanRunning.value && dashboardSnapshot.value?.statusCounts !== undefined) {
    return dashboardSnapshot.value.statusCounts
  }

  return props.statusCounts
})

const effectiveDiagnosticBreakdown = computed<DashboardProps['diagnosticBreakdown']>(() => {
  if (isMassScanRunning.value && dashboardSnapshot.value?.diagnosticBreakdown !== undefined) {
    return dashboardSnapshot.value.diagnosticBreakdown
  }

  return props.diagnosticBreakdown
})

const effectiveUpdatedAt = computed<string | undefined>(() => {
  if (isMassScanRunning.value && dashboardSnapshot.value?.updatedAt !== undefined) {
    return dashboardSnapshot.value.updatedAt
  }

  return props.updatedAt
})

const showMassScanOverlay = computed(() => {
  if (!massScanProgress.value) {
    return false
  }

  return massScanProgress.value.status === 'running' || massScanProgress.value.remaining_tasks > 0
})

const massScanTotalTasks = computed(() => Number(massScanProgress.value?.total_tasks ?? 0))
const massScanCompletedTasks = computed(() => Number(massScanProgress.value?.completed_tasks ?? 0))
const massScanRemainingTasks = computed(() => Number(massScanProgress.value?.remaining_tasks ?? 0))
const massScanProgressPct = computed(() => Number(massScanProgress.value?.progress_pct ?? 0))
const massScanStartedAt = computed(() => {
  const value = massScanProgress.value?.started_at
  if (!value) {
    return 'Sin dato'
  }

  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? 'Sin dato' : parsed.toLocaleString('es-MX')
})

const massScanStageRows = computed(() => {
  const stages = massScanProgress.value?.stages

  if (!stages) {
    return []
  }

  return [
    {
      key: 'uptime',
      label: 'Disponibilidad',
      completed: Number(stages.uptime?.completed ?? 0),
      failed: Number(stages.uptime?.failed ?? 0),
      total: Number(stages.uptime?.total ?? 0),
      remaining: Number(stages.uptime?.remaining ?? 0),
      progressPct: Number(stages.uptime?.progress_pct ?? 0),
    },
    {
      key: 'ssl',
      label: 'Certificado SSL',
      completed: Number(stages.ssl?.completed ?? 0),
      failed: Number(stages.ssl?.failed ?? 0),
      total: Number(stages.ssl?.total ?? 0),
      remaining: Number(stages.ssl?.remaining ?? 0),
      progressPct: Number(stages.ssl?.progress_pct ?? 0),
    },
    {
      key: 'headers',
      label: 'Cabeceras de seguridad',
      completed: Number(stages.headers?.completed ?? 0),
      failed: Number(stages.headers?.failed ?? 0),
      total: Number(stages.headers?.total ?? 0),
      remaining: Number(stages.headers?.remaining ?? 0),
      progressPct: Number(stages.headers?.progress_pct ?? 0),
    },
    {
      key: 'technology',
      label: 'Tecnologías detectadas',
      completed: Number(stages.technology?.completed ?? 0),
      failed: Number(stages.technology?.failed ?? 0),
      total: Number(stages.technology?.total ?? 0),
      remaining: Number(stages.technology?.remaining ?? 0),
      progressPct: Number(stages.technology?.progress_pct ?? 0),
    },
  ]
})

const normalizedStatusCounts = computed<StatusCounts>(() => ({
  UP: Number(effectiveStatusCounts.value?.UP ?? effectiveStatusCounts.value?.up ?? 0),
  DEGRADED: Number(effectiveStatusCounts.value?.DEGRADED ?? effectiveStatusCounts.value?.degraded ?? 0),
  DOWN: Number(effectiveStatusCounts.value?.DOWN ?? effectiveStatusCounts.value?.down ?? 0),
  UNKNOWN: Number(effectiveStatusCounts.value?.UNKNOWN ?? effectiveStatusCounts.value?.unknown ?? 0),
}))

const normalizedSites = computed<SiteItem[]>(() => {
  if (Array.isArray(effectiveSites.value)) {
    return effectiveSites.value
  }

  return effectiveSites.value?.data ?? []
})

const visibleSiteIds = computed(() => normalizedSites.value.map((site) => site.id))

const selectedSiteIds = computed(() => Array.from(selectedSiteIdsSet.value))

const isAllVisibleSelected = computed(() => {
  if (visibleSiteIds.value.length === 0) {
    return false
  }

  return visibleSiteIds.value.every((siteId) => selectedSiteIdsSet.value.has(siteId))
})

const isSomeVisibleSelected = computed(() => visibleSiteIds.value.some((siteId) => selectedSiteIdsSet.value.has(siteId)))

const currentPage = computed(() => Array.isArray(effectiveSites.value) ? 1 : (effectiveSites.value?.current_page ?? 1))
const lastPage = computed(() => Array.isArray(effectiveSites.value) ? 1 : Math.max(1, effectiveSites.value?.last_page ?? 1))
const currentPerPage = computed(() => Array.isArray(effectiveSites.value) ? normalizedSites.value.length : (effectiveSites.value?.per_page ?? DEFAULT_PER_PAGE))
const totalSites = computed(() => Array.isArray(effectiveSites.value) ? normalizedSites.value.length : (effectiveSites.value?.total ?? normalizedSites.value.length))

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
  if (!effectiveUpdatedAt.value) {
    return 'Sin dato'
  }

  const parsed = new Date(effectiveUpdatedAt.value)
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

const certificateLabel = (site: SiteItem) => {
  const cert = site.ssl_certificate

  if (!cert) {
    return 'Sin certificado'
  }

  if (cert.days_remaining !== null) {
    if (cert.days_remaining < 0 || cert.is_expired) {
      return 'Expirado'
    }

    if (cert.days_remaining <= 30) {
      return `Vence en ${cert.days_remaining} días`
    }

    return 'Vigente'
  }

  if (cert.valid_until || cert.issuer || cert.algorithm) {
    return 'Vigente'
  }

  return 'Sin datos'
}

const certificateBadgeClass = (site: SiteItem) => {
  const cert = site.ssl_certificate

  if (!cert) {
    return 'bg-slate-700 text-slate-300'
  }

  if (cert.days_remaining !== null && (cert.days_remaining < 0 || cert.is_expired)) {
    return 'bg-rose-500/15 text-rose-200'
  }

  if (cert.days_remaining !== null && cert.days_remaining <= 30) {
    return 'bg-amber-500/15 text-amber-200'
  }

  return 'bg-emerald-500/15 text-emerald-200'
}

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
  if (isMassScanRunning.value) {
    actionMessage.value = 'Snapshot estable activo. Espera a que termine la corrida para aplicar filtros.'
    return
  }

  localStatus.value = status
  router.get('/monitoring/dashboard', buildDashboardQuery(1), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

const refreshDashboard = () => {
  if (document.activeElement instanceof HTMLInputElement || document.activeElement instanceof HTMLTextAreaElement) {
    return
  }

  router.get('/monitoring/dashboard', buildDashboardQuery(currentPage.value), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ['sites', 'statusCounts', 'diagnosticBreakdown', 'pipelineMetrics', 'massScanProgress', 'massScanHistory', 'scheduledScansEnabled', 'updatedAt'],
  })
}

const applySearch = () => {
  if (isMassScanRunning.value) {
    actionMessage.value = 'Snapshot estable activo. Espera a que termine la corrida para buscar.'
    return
  }

  router.get('/monitoring/dashboard', buildDashboardQuery(1), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

const clearSearch = () => {
  if (isMassScanRunning.value) {
    actionMessage.value = 'Snapshot estable activo. Espera a que termine la corrida para limpiar filtros.'
    return
  }

  localSearch.value = ''
  localStatus.value = 'all'
  applySearch()
}

const goToPage = (page: number) => {
  if (isMassScanRunning.value) {
    actionMessage.value = 'Snapshot estable activo. La paginación se habilita al finalizar la corrida.'
    return
  }

  if (page < 1 || page > lastPage.value) {
    return
  }

  router.get('/monitoring/dashboard', buildDashboardQuery(page), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ['sites', 'statusCounts', 'diagnosticBreakdown', 'pipelineMetrics', 'massScanProgress', 'massScanHistory', 'scheduledScansEnabled', 'updatedAt'],
  })
}

const openSiteDetail = (siteId: number) => {
  router.visit(`/monitoring/sites/${siteId}/detail`)
}

const isSiteSelected = (siteId: number) => selectedSiteIdsSet.value.has(siteId)

const toggleSelectedSite = (siteId: number) => {
  const next = new Set(selectedSiteIdsSet.value)

  if (next.has(siteId)) {
    next.delete(siteId)
  } else {
    next.add(siteId)
  }

  selectedSiteIdsSet.value = next
}

const toggleSelectVisible = () => {
  const next = new Set(selectedSiteIdsSet.value)

  if (isAllVisibleSelected.value) {
    for (const siteId of visibleSiteIds.value) {
      next.delete(siteId)
    }
  } else {
    for (const siteId of visibleSiteIds.value) {
      next.add(siteId)
    }
  }

  selectedSiteIdsSet.value = next
}

const scanSingleSite = (siteId: number) => {
  if (isMassScanRunning.value) {
    actionMessage.value = 'Ya existe un escaneo en curso. Espera a que finalice.'
    return
  }

  actionMessage.value = 'Iniciando escaneo del sitio seleccionado...'
  void startSingleSiteScanRequest(siteId)
}

const scanAllSites = () => {
  if (isMassScanRunning.value) {
    actionMessage.value = 'Ya existe un escaneo masivo en curso. Espera a que termine para iniciar otro.'
    return
  }

  actionMessage.value = 'Validando disponibilidad para lanzar escaneo masivo...'
  void startMassScanRequest()
}

const scanSelectedSites = () => {
  if (selectedSiteIds.value.length === 0) {
    actionMessage.value = 'Selecciona al menos un sitio para escanear.'
    return
  }

  if (isMassScanRunning.value) {
    actionMessage.value = 'Ya existe un escaneo en curso. Espera a que finalice.'
    return
  }

  actionMessage.value = `Iniciando escaneo de ${selectedSiteIds.value.length} sitio(s) seleccionados...`
  void startSelectedScanRequest()
}

const exportDashboardPdf = async () => {
  isExporting.value = true

  try {
    const response = await fetch('/monitoring/dashboard/export-report', {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/pdf',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`)
    }

    const blob = await response.blob()
    const objectUrl = window.URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = objectUrl
    anchor.download = `UDG_Sentinel_Reporte_General_${new Date().toISOString().slice(0, 10).replaceAll('-', '_')}.pdf`
    anchor.click()
    window.URL.revokeObjectURL(objectUrl)
  } catch {
    actionMessage.value = 'No se pudo exportar el PDF en este momento.'
  } finally {
    isExporting.value = false
  }
}

const getCsrfToken = () => {
  const meta = document.querySelector('meta[name="csrf-token"]')
  return meta instanceof HTMLMetaElement ? (meta.content || '') : ''
}

const startMassScanRequest = async () => {
  try {
    const response = await fetch('/monitoring/dashboard/scan-all', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({}),
    })

    if (response.status === 419) {
      actionMessage.value = 'La sesión expiró. Recarga la página para continuar.'
      return
    }

    if (!response.ok) {
      actionMessage.value = 'No se pudo iniciar el escaneo masivo. Intenta nuevamente.'
      return
    }

    const payload = await response.json() as {
      started?: boolean
      status?: string
      message?: string
      progress?: MassScanProgressPayload | null
      redirect_url?: string | null
    }

    if (payload.message) {
      actionMessage.value = payload.message
    }

    if (payload.progress) {
      massScanProgress.value = payload.progress
    }

    if (payload.started) {
      hasAnnouncedActiveRun.value = false
      if (typeof payload.redirect_url === 'string' && payload.redirect_url !== '') {
        router.visit(payload.redirect_url)
        return
      }

      void fetchMassScanProgress()
    } else if (payload.status === 'already_running' && payload.progress) {
      hasAnnouncedActiveRun.value = true
      actionMessage.value = 'Ya existe un escaneo en curso. Mostrando el progreso actual.'

      if (typeof payload.redirect_url === 'string' && payload.redirect_url !== '') {
        router.visit(payload.redirect_url)
        return
      }
    }

    refreshDashboard()
  } catch {
    actionMessage.value = 'No se pudo iniciar el escaneo masivo por un error de red.'
  }
}

const startSelectedScanRequest = async () => {
  try {
    const response = await fetch('/monitoring/dashboard/scan-selected', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({ site_ids: selectedSiteIds.value }),
    })

    if (response.status === 419) {
      actionMessage.value = 'La sesión expiró. Recarga la página para continuar.'
      return
    }

    if (!response.ok) {
      actionMessage.value = 'No se pudo iniciar el escaneo de sitios seleccionados.'
      return
    }

    const payload = await response.json() as {
      started?: boolean
      status?: string
      message?: string
      progress?: MassScanProgressPayload | null
      redirect_url?: string | null
    }

    if (payload.message) {
      actionMessage.value = payload.message
    }

    if (payload.progress) {
      massScanProgress.value = payload.progress
    }

    if (payload.started || payload.status === 'already_running') {
      if (typeof payload.redirect_url === 'string' && payload.redirect_url !== '') {
        router.visit(payload.redirect_url)
        return
      }
    }
  } catch {
    actionMessage.value = 'No se pudo iniciar el escaneo de sitios seleccionados por un error de red.'
  }
}

const startSingleSiteScanRequest = async (siteId: number) => {
  try {
    const response = await fetch(`/monitoring/sites/${siteId}/scan`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({}),
    })

    if (response.status === 419) {
      actionMessage.value = 'La sesión expiró. Recarga la página para continuar.'
      return
    }

    if (!response.ok) {
      actionMessage.value = 'No se pudo iniciar el escaneo del sitio.'
      return
    }

    const payload = await response.json() as {
      started?: boolean
      status?: string
      message?: string
      progress?: MassScanProgressPayload | null
      redirect_url?: string | null
    }

    if (payload.message) {
      actionMessage.value = payload.message
    }

    if (payload.progress) {
      massScanProgress.value = payload.progress
    }

    if (payload.started || payload.status === 'already_running') {
      if (typeof payload.redirect_url === 'string' && payload.redirect_url !== '') {
        router.visit(payload.redirect_url)
        return
      }
    }
  } catch {
    actionMessage.value = 'No se pudo iniciar el escaneo del sitio por un error de red.'
  }
}

const fetchMassScanProgress = async () => {
  try {
    const response = await fetch('/monitoring/dashboard/scan-progress', {
      method: 'GET',
      headers: {
        Accept: 'application/json',
      },
      credentials: 'same-origin',
    })

    if (!response.ok) {
      return
    }

    const payload = await response.json() as { active?: boolean; progress?: MassScanProgressPayload | null }

    if (!payload.progress) {
      const hadRun = massScanProgress.value !== null
      massScanProgress.value = null

      if (hadRun) {
        hasAnnouncedActiveRun.value = false
        refreshDashboard()
      }

      return
    }

    const previousStatus = massScanProgress.value?.status ?? null
    massScanProgress.value = payload.progress

    if (payload.progress.status === 'running' && !hasAnnouncedActiveRun.value) {
      actionMessage.value = 'Escaneo en ejecución. Redirigiendo a la página de progreso...'
      hasAnnouncedActiveRun.value = true

      if (payload.progress.run_id) {
        router.visit(`/monitoring/scans/${payload.progress.run_id}`)
        return
      }
    }

    if (payload.progress.status === 'completed_ok') {
      actionMessage.value = 'Escaneo masivo completado correctamente. Los datos ya fueron revalidados.'
    } else if (payload.progress.status === 'completed_with_errors') {
      actionMessage.value = 'Escaneo masivo completado con errores parciales. Revisa el histórico para detalles.'
    } else if (payload.progress.status === 'incomplete') {
      actionMessage.value = 'Escaneo masivo marcado como incompleto por inactividad del proceso.'
    }

    if (previousStatus === 'running' && payload.progress.status !== 'running') {
      hasAnnouncedActiveRun.value = false
      refreshDashboard()
    }
  } catch {
    // El dashboard sigue operativo aunque falle la consulta de progreso.
  }
}

const fetchSearchSuggestions = async (query: string) => {
  try {
    const params = new URLSearchParams()
    if (query.trim() !== '') {
      params.set('q', query.trim())
    }

    const response = await fetch(`/monitoring/dashboard/search-suggestions?${params.toString()}`, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
      },
      credentials: 'same-origin',
    })

    if (!response.ok) {
      return
    }

    const payload = await response.json() as { items?: string[] }
    suggestionsState.value = Array.isArray(payload.items) ? payload.items : []
  } catch {
    // El autocompletado es auxiliar; no bloquea flujo principal.
  }
}

const toggleScheduledScans = () => {
  if (isUpdatingScheduledScans.value) {
    return
  }

  void submitScheduledScansToggle()
}

const submitScheduledScansToggle = async () => {
  isUpdatingScheduledScans.value = true
  const nextValue = !scheduledScansEnabledLocal.value

  try {
    const response = await fetch('/monitoring/dashboard/scheduled-scans', {
      method: 'PATCH',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({ enabled: nextValue }),
    })

    if (response.status === 403) {
      actionMessage.value = 'No tienes permisos para cambiar esta configuración.'
      return
    }

    if (response.status === 419) {
      actionMessage.value = 'La sesión expiró. Recarga la página para continuar.'
      return
    }

    if (!response.ok) {
      actionMessage.value = 'No se pudo actualizar la configuración de escaneos programados.'
      return
    }

    const payload = await response.json() as { ok?: boolean; enabled?: boolean; message?: string }
    scheduledScansEnabledLocal.value = Boolean(payload.enabled)
    actionMessage.value = payload.message
      ?? (scheduledScansEnabledLocal.value
        ? 'Escaneos programados activados correctamente.'
        : 'Escaneos programados desactivados. Solo queda el modo manual.')

    refreshDashboard()
  } catch {
    actionMessage.value = 'No se pudo actualizar la configuración de escaneos programados.'
  } finally {
    isUpdatingScheduledScans.value = false
  }
}

const formatDateTime = (value: string | null) => {
  if (!value) {
    return '-'
  }

  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? '-' : parsed.toLocaleString('es-MX')
}

const formatDate = (value: string | null) => formatDateTime(value)

const massScanStatusLabel = (status: string) => {
  if (status === 'running') return 'En ejecución'
  if (status === 'completed_ok') return 'Finalizado OK'
  if (status === 'completed_with_errors') return 'Finalizado con errores'
  if (status === 'incomplete') return 'Incompleto'
  return 'Desconocido'
}

const massScanStatusClass = (status: string) => {
  if (status === 'running') return 'bg-cyan-500/15 text-cyan-200'
  if (status === 'completed_ok') return 'bg-emerald-500/15 text-emerald-200'
  if (status === 'completed_with_errors') return 'bg-amber-500/15 text-amber-200'
  if (status === 'incomplete') return 'bg-rose-500/15 text-rose-200'
  return 'bg-slate-700 text-slate-200'
}

const formatCheckTime = (value: string | null, status: ReturnType<typeof resolveStatusCode>) => {
  if (!value) {
    return status === 'unknown' ? 'Escaneo en proceso...' : 'Nunca'
  }

  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? 'Nunca' : parsed.toLocaleString('es-MX')
}

const technologyTooltip = (site: SiteItem) => {
  const category = site.technology_category_label?.trim() || 'Sin categoria'
  const confidence = site.technology_confidence !== null && site.technology_confidence !== undefined
    ? `${site.technology_confidence}%`
    : 'Sin confianza'
  const version = site.technology_version?.trim() || 'Sin versión'

  return `${site.technology_label || 'No identificada'} · ${category} · ${confidence} · ${version}`
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
    Number(effectiveDiagnosticBreakdown.value?.operativo ?? 0),
    Number(effectiveDiagnosticBreakdown.value?.respuesta_lenta ?? 0),
    Number(effectiveDiagnosticBreakdown.value?.responde_con_errores ?? 0),
    Number(effectiveDiagnosticBreakdown.value?.inestable ?? 0),
    Number(effectiveDiagnosticBreakdown.value?.no_responde ?? 0),
    Number(effectiveDiagnosticBreakdown.value?.sin_actualizar ?? 0),
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
let massScanPollingInterval: ReturnType<typeof setInterval> | null = null
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null

watch(localSearch, (value, previousValue) => {
  if (value === previousValue) {
    return
  }

  if (searchDebounceTimer) {
    clearTimeout(searchDebounceTimer)
  }

  searchDebounceTimer = setTimeout(() => {
    void fetchSearchSuggestions(value)
  }, 300)
})

watch(() => props.scheduledScansEnabled, (value) => {
  if (typeof value === 'boolean') {
    scheduledScansEnabledLocal.value = value
  }
})

watch(normalizedSites, () => {
  const visible = new Set(normalizedSites.value.map((site) => site.id))
  const next = new Set<number>()

  for (const siteId of selectedSiteIdsSet.value) {
    if (visible.has(siteId)) {
      next.add(siteId)
    }
  }

  selectedSiteIdsSet.value = next
})

watch(isMassScanRunning, (running, previousRunning) => {
  if (running && !previousRunning) {
    captureDashboardSnapshot()
    return
  }

  if (!running && previousRunning) {
    clearDashboardSnapshot()
  }
})

onMounted(() => {
  if (isMassScanRunning.value) {
    captureDashboardSnapshot()
  }

  pollingInterval = setInterval(() => {
    if (!isMassScanRunning.value) {
      refreshDashboard()
    }
  }, 15000)
  massScanPollingInterval = setInterval(() => {
    void fetchMassScanProgress()
  }, 2500)

  void fetchMassScanProgress()
  void fetchSearchSuggestions(localSearch.value)

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
    if (!isMassScanRunning.value) {
      refreshDashboard()
    }
  })
})

onBeforeUnmount(() => {
  if (pollingInterval) {
    clearInterval(pollingInterval)
    pollingInterval = null
  }

  if (massScanPollingInterval) {
    clearInterval(massScanPollingInterval)
    massScanPollingInterval = null
  }

  channel?.stopListening('.site.status.changed')
  channel?.unsubscribe()

  if (searchDebounceTimer) {
    clearTimeout(searchDebounceTimer)
    searchDebounceTimer = null
  }
})
</script>
