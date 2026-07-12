<template>
  <main class="min-h-screen bg-slate-950 text-slate-100">
    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <header class="mb-8 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.22em] text-cyan-300">UDG Sentinel</p>
          <h1 class="mt-2 text-3xl font-semibold text-white sm:text-4xl">Tablero ejecutivo</h1>
          <p class="mt-2 max-w-3xl text-sm text-slate-300">
            Visión consolidada de disponibilidad, rendimiento, seguridad y tecnología detectada.
          </p>
        </div>
        <div class="flex items-center gap-3">
          <a
            :href="reportUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="rounded-xl border border-cyan-400/40 bg-cyan-500/10 px-4 py-2 text-sm font-semibold text-cyan-100 transition hover:border-cyan-300"
          >
            Abrir reporte ejecutivo
          </a>
          <p class="text-xs text-slate-400">Actualizado: {{ formatDate(updatedAt) }}</p>
        </div>
      </header>

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border border-emerald-500/25 bg-emerald-500/10 p-5">
          <p class="text-xs uppercase tracking-[0.18em] text-emerald-300">Disponibilidad 7 días</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ summary.kpis.uptime_7d_pct.toFixed(2) }}%</p>
          <p class="mt-2 text-xs text-emerald-100/80">Promedio del parque monitoreado</p>
        </article>
        <article class="rounded-2xl border border-amber-500/25 bg-amber-500/10 p-5">
          <p class="text-xs uppercase tracking-[0.18em] text-amber-300">Disponibilidad 30 días</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ summary.kpis.uptime_30d_pct.toFixed(2) }}%</p>
          <p class="mt-2 text-xs text-amber-100/80">Base mensual para dirección</p>
        </article>
        <article class="rounded-2xl border border-sky-500/25 bg-sky-500/10 p-5">
          <p class="text-xs uppercase tracking-[0.18em] text-sky-300">Tiempo de respuesta</p>
          <p class="mt-3 text-4xl font-semibold text-white">
            {{ summary.kpis.avg_response_time_7d_ms !== null ? `${summary.kpis.avg_response_time_7d_ms} ms` : 'Sin datos' }}
          </p>
          <p class="mt-2 text-xs text-sky-100/80">Promedio de los últimos 7 días</p>
        </article>
        <article class="rounded-2xl border border-rose-500/25 bg-rose-500/10 p-5">
          <p class="text-xs uppercase tracking-[0.18em] text-rose-300">Tecnologías obsoletas</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ summary.kpis.obsolete_technologies }}</p>
          <p class="mt-2 text-xs text-rose-100/80">Heurística operativa de legado</p>
        </article>
      </section>

      <section class="mt-6 grid gap-6 xl:grid-cols-2">
        <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
          <header class="mb-4 flex items-end justify-between gap-4">
            <div>
              <h2 class="text-lg font-semibold text-white">Disponibilidad por día</h2>
              <p class="text-sm text-slate-400">Serie de 30 días con datos condensados donde aplica.</p>
            </div>
            <p class="text-xs text-slate-500">Últimos 30 días</p>
          </header>

          <div v-if="!chartData" class="space-y-3 rounded-2xl border border-slate-800 bg-slate-950/50 p-4">
            <div class="h-3 w-48 animate-pulse rounded bg-slate-700/80" />
            <div class="h-64 animate-pulse rounded-xl bg-slate-800/90" />
          </div>
          <VueApexCharts
            v-else
            type="line"
            height="320"
            :options="availabilityChartOptions"
            :series="availabilitySeries"
          />
        </article>

        <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
          <header class="mb-4 flex items-end justify-between gap-4">
            <div>
              <h2 class="text-lg font-semibold text-white">Rendimiento de servidores</h2>
              <p class="text-sm text-slate-400">Promedio diario de CPU, RAM y disco.</p>
            </div>
            <p class="text-xs text-slate-500">Últimos 30 días</p>
          </header>

          <div v-if="!chartData" class="space-y-3 rounded-2xl border border-slate-800 bg-slate-950/50 p-4">
            <div class="h-3 w-52 animate-pulse rounded bg-slate-700/80" />
            <div class="h-64 animate-pulse rounded-xl bg-slate-800/90" />
          </div>
          <VueApexCharts
            v-else
            type="area"
            height="320"
            :options="serverChartOptions"
            :series="serverSeries"
          />
        </article>
      </section>

      <section class="mt-6 grid gap-6 xl:grid-cols-2">
        <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
          <header class="mb-4 flex items-end justify-between gap-4">
            <div>
              <h2 class="text-lg font-semibold text-white">Puntaje de seguridad</h2>
              <p class="text-sm text-slate-400">Promedio diario y cantidad de sitios críticos.</p>
            </div>
            <p class="text-xs text-slate-500">Últimos 30 días</p>
          </header>

          <div v-if="!chartData" class="space-y-3 rounded-2xl border border-slate-800 bg-slate-950/50 p-4">
            <div class="h-3 w-52 animate-pulse rounded bg-slate-700/80" />
            <div class="h-64 animate-pulse rounded-xl bg-slate-800/90" />
          </div>
          <VueApexCharts
            v-else
            type="bar"
            height="320"
            :options="securityChartOptions"
            :series="securitySeries"
          />
        </article>

        <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
          <header class="mb-4 flex items-end justify-between gap-4">
            <div>
              <h2 class="text-lg font-semibold text-white">Alertas prioritarias</h2>
              <p class="text-sm text-slate-400">Acciones abiertas que merecen revisión ejecutiva.</p>
            </div>
            <p class="text-xs text-slate-500">Actualizado al momento</p>
          </header>

          <div class="space-y-3">
            <article
              v-for="alertItem in summary.top_alerts"
              :key="alertItem.id"
              class="rounded-2xl border border-slate-800 bg-slate-950/50 p-4"
            >
              <div class="flex items-start justify-between gap-4">
                <div>
                  <p class="text-sm font-semibold text-white">{{ alertItem.title }}</p>
                  <p class="mt-1 text-xs text-slate-400">
                    {{ alertItem.site?.name ?? 'Sin sitio' }} · {{ alertItem.site?.domain ?? '' }}
                  </p>
                </div>
                <span class="rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-[0.12em]" :class="severityClass(alertItem.severity)">
                  {{ alertItem.severity }}
                </span>
              </div>
            </article>
          </div>
        </article>
      </section>

      <section class="mt-6 rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
        <header class="mb-4 flex items-end justify-between gap-4">
          <div>
            <h2 class="text-lg font-semibold text-white">Tecnologías obsoletas detectadas</h2>
            <p class="text-sm text-slate-400">Se muestran las detecciones con heurística de legado o versión vacía.</p>
          </div>
          <p class="text-xs text-slate-500">Top {{ summary.obsolete_technologies.length }}</p>
        </header>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          <article
            v-for="item in summary.obsolete_technologies"
            :key="`${item.site.domain}-${item.technology.slug ?? item.technology.name}`"
            class="rounded-2xl border border-slate-800 bg-slate-950/50 p-4"
          >
            <p class="text-sm font-semibold text-white">{{ item.technology.display_name }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ item.site.name }} · {{ item.site.domain }}</p>
            <p class="mt-3 text-xs uppercase tracking-[0.16em] text-rose-300">{{ item.technology.category_label }}</p>
          </article>
        </div>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { ApexOptions } from 'apexcharts'
import VueApexCharts from 'vue3-apexcharts'

type DailySiteCheckPoint = {
  day: string
  total_checks: number
  up_checks: number
  down_checks: number
  degraded_checks: number
  timeout_checks: number
  avg_response_time_ms: number | null
}

type DailyServerPoint = {
  day: string
  avg_cpu_usage_pct: number | null
  avg_ram_usage_pct: number | null
  avg_disk_usage_pct: number | null
}

type DailySecurityPoint = {
  day: string
  avg_score: number | null
  critical_sites: number
}

type AlertItem = {
  id: number
  title: string
  severity: string
  site: { name: string; domain: string } | null
}

type ObsoleteTechnology = {
  site: { name: string; domain: string }
  technology: {
    name: string
    display_name: string
    category_label: string
    category: string
    slug?: string | null
  }
}

type Summary = {
  generated_at: string
  kpis: {
    active_sites: number
    uptime_7d_pct: number
    uptime_30d_pct: number
    avg_response_time_7d_ms: number | null
    avg_cpu_7d_pct: number | null
    avg_security_score_30d: number | null
    open_alerts: number
    obsolete_technologies: number
  }
  top_alerts: AlertItem[]
  obsolete_technologies: ObsoleteTechnology[]
}

type ChartData = {
  site_checks: { last_30_days: DailySiteCheckPoint[] }
  server_metrics: { last_30_days: DailyServerPoint[] }
  security_scores: { last_30_days: DailySecurityPoint[] }
  traffic_metrics: { last_30_days: Array<{ day: string; avg_requests_per_min: number | null }> }
}

const props = defineProps<{
  summary: Summary
  chartDataUrl: string
  reportUrl: string
  updatedAt: string
}>()

const chartData = ref<ChartData | null>(null)

const formatDate = (value: string): string => {
  return new Intl.DateTimeFormat('es-MX', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

const fetchChartData = async (): Promise<void> => {
  const response = await fetch(props.chartDataUrl, {
    headers: {
      Accept: 'application/json',
    },
  })

  if (!response.ok) {
    throw new Error('No fue posible cargar las series históricas.')
  }

  chartData.value = await response.json() as ChartData
}

onMounted(() => {
  void fetchChartData().catch(() => {
    chartData.value = null
  })
})

const availabilitySeries = computed(() => [{
  name: 'Disponibilidad %',
  data: chartData.value?.site_checks.last_30_days.map((point) => ({
    x: point.day,
    y: point.total_checks > 0 ? Number(((point.up_checks / point.total_checks) * 100).toFixed(2)) : 0,
  })) ?? [],
}])

const serverSeries = computed(() => [{
  name: 'CPU %',
  data: chartData.value?.server_metrics.last_30_days.map((point) => ({ x: point.day, y: point.avg_cpu_usage_pct ?? 0 })) ?? [],
}, {
  name: 'RAM %',
  data: chartData.value?.server_metrics.last_30_days.map((point) => ({ x: point.day, y: point.avg_ram_usage_pct ?? 0 })) ?? [],
}, {
  name: 'Disco %',
  data: chartData.value?.server_metrics.last_30_days.map((point) => ({ x: point.day, y: point.avg_disk_usage_pct ?? 0 })) ?? [],
}])

const securitySeries = computed(() => [{
  name: 'Puntaje',
  data: chartData.value?.security_scores.last_30_days.map((point) => ({ x: point.day, y: point.avg_score ?? 0 })) ?? [],
}])

const chartTheme: ApexOptions = {
  chart: {
    foreColor: '#cbd5e1',
    toolbar: { show: false },
    zoom: { enabled: false },
    fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
  },
  grid: {
    borderColor: 'rgba(148, 163, 184, 0.18)',
  },
  dataLabels: {
    enabled: false,
  },
  stroke: {
    width: 3,
    curve: 'smooth',
  },
  tooltip: {
    theme: 'dark',
  },
  xaxis: {
    type: 'category',
    labels: {
      rotate: -45,
    },
  },
}

const availabilityChartOptions = computed<ApexOptions>(() => ({
  ...chartTheme,
  colors: ['#22c55e'],
  yaxis: {
    min: 0,
    max: 100,
    labels: {
      formatter: (value: number) => `${value.toFixed(0)}%`,
    },
  },
}))

const serverChartOptions = computed<ApexOptions>(() => ({
  ...chartTheme,
  colors: ['#38bdf8', '#f59e0b', '#a78bfa'],
  yaxis: {
    labels: {
      formatter: (value: number) => `${value.toFixed(0)}%`,
    },
  },
}))

const securityChartOptions = computed<ApexOptions>(() => ({
  ...chartTheme,
  colors: ['#f97316'],
  yaxis: {
    min: 0,
    max: 100,
  },
}))

const severityClass = (severity: string): string => {
  const normalized = severity.toLowerCase()

  if (normalized === 'critical') {
    return 'border-rose-400/40 bg-rose-500/10 text-rose-200'
  }

  if (normalized === 'high') {
    return 'border-amber-400/40 bg-amber-500/10 text-amber-200'
  }

  if (normalized === 'medium') {
    return 'border-yellow-400/40 bg-yellow-500/10 text-yellow-100'
  }

  return 'border-slate-500/40 bg-slate-500/10 text-slate-200'
}
</script>