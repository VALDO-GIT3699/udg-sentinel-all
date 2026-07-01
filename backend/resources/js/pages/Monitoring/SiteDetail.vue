<template>
  <main class="min-h-screen bg-slate-950 text-slate-100">
    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <header class="mb-6 flex flex-col gap-3 border-b border-slate-800 pb-6 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <a href="/monitoring/dashboard" class="text-sm text-cyan-300 hover:text-cyan-200">Volver al dashboard</a>
          <h1 class="mt-2 text-3xl font-semibold text-white sm:text-4xl">{{ site.name }}</h1>
          <p class="mt-2 text-sm text-slate-300">
            {{ site.url }} · Estado actual: <span class="font-semibold" :class="statusTextClass(site.current_status)">{{ statusLabel(site.current_status) }}</span>
          </p>
        </div>
        <p class="text-xs text-slate-400">Actualizado: {{ formatDate(updatedAt) }}</p>
      </header>

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border border-emerald-500/25 bg-emerald-500/10 p-5">
          <p class="text-xs uppercase tracking-[0.18em] text-emerald-300">Uptime 24h</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ uptime24h.toFixed(2) }}%</p>
        </article>
        <article class="rounded-2xl border border-sky-500/25 bg-sky-500/10 p-5">
          <p class="text-xs uppercase tracking-[0.18em] text-sky-300">TTFB promedio 24h</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ avgResponse24h !== null ? `${avgResponse24h} ms` : 'Sin datos' }}</p>
        </article>
        <article class="rounded-2xl border border-rose-500/25 bg-rose-500/10 p-5">
          <p class="text-xs uppercase tracking-[0.18em] text-rose-300">Alertas abiertas</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ openAlerts.length }}</p>
        </article>
        <article class="rounded-2xl border border-slate-500/35 bg-slate-800/80 p-5">
          <p class="text-xs uppercase tracking-[0.18em] text-slate-300">Eventos recientes</p>
          <p class="mt-3 text-4xl font-semibold text-white">{{ events.length }}</p>
        </article>
      </section>

      <section class="mt-7 grid gap-4 md:grid-cols-3">
        <article class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4">
          <p class="text-xs uppercase tracking-[0.18em] text-emerald-300">Checks UP</p>
          <p class="mt-2 text-3xl font-semibold text-white">{{ statusBreakdown24h.up ?? 0 }}</p>
        </article>
        <article class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4">
          <p class="text-xs uppercase tracking-[0.18em] text-amber-300">Checks degradados</p>
          <p class="mt-2 text-3xl font-semibold text-white">{{ statusBreakdown24h.degraded ?? 0 }}</p>
        </article>
        <article class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4">
          <p class="text-xs uppercase tracking-[0.18em] text-rose-300">Checks DOWN</p>
          <p class="mt-2 text-3xl font-semibold text-white">{{ statusBreakdown24h.down ?? 0 }}</p>
        </article>
      </section>

      <section
        v-if="isTelemetryInitializing"
        class="mt-6 rounded-2xl border border-cyan-400/35 bg-cyan-500/10 p-4"
      >
        <p class="text-xs uppercase tracking-[0.18em] text-cyan-200">Estado de telemetria</p>
        <p class="mt-2 text-sm font-medium text-cyan-100">
          Telemetria en proceso de inicializacion: Esperando la primera ronda de escaneos masivos.
        </p>
      </section>

      <section class="mt-8 grid gap-6">
        <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
          <header class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-white">Línea de tiempo TTFB (latencia)</h2>
            <p class="text-xs text-slate-400">Ventana de 24 horas</p>
          </header>
          <VueApexCharts
            v-if="!isTelemetryInitializing"
            type="line"
            height="320"
            :options="ttfbChartOptions"
            :series="ttfbSeries"
          />
          <div v-else class="space-y-3 rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="h-3 w-56 animate-pulse rounded bg-slate-700/80" />
            <div class="h-40 animate-pulse rounded-xl bg-slate-800/90" />
            <p class="text-xs text-slate-400">La linea de tiempo aparecera automaticamente cuando se registren checks.</p>
          </div>
        </article>

        <section class="grid gap-6 xl:grid-cols-2">
          <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
            <header class="mb-4 flex items-center justify-between">
              <h2 class="text-lg font-semibold text-white">Picos de tráfico</h2>
              <p class="text-xs text-slate-400">Últimas 24 horas</p>
            </header>
            <VueApexCharts
              v-if="!isTelemetryInitializing"
              type="area"
              height="300"
              :options="traffic24hOptions"
              :series="traffic24hSeries"
            />
            <div v-else class="space-y-3 rounded-2xl border border-slate-800 bg-slate-900 p-4">
              <div class="h-3 w-44 animate-pulse rounded bg-slate-700/80" />
              <div class="h-36 animate-pulse rounded-xl bg-slate-800/90" />
            </div>
          </article>

          <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
            <header class="mb-4 flex items-center justify-between">
              <h2 class="text-lg font-semibold text-white">Picos de tráfico</h2>
              <p class="text-xs text-slate-400">Última hora</p>
            </header>
            <VueApexCharts
              v-if="!isTelemetryInitializing"
              type="bar"
              height="300"
              :options="traffic1hOptions"
              :series="traffic1hSeries"
            />
            <div v-else class="space-y-3 rounded-2xl border border-slate-800 bg-slate-900 p-4">
              <div class="h-3 w-44 animate-pulse rounded bg-slate-700/80" />
              <div class="h-36 animate-pulse rounded-xl bg-slate-800/90" />
            </div>
          </article>
        </section>

        <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
          <header class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-white">Disponibilidad de uptime</h2>
            <p class="text-xs text-slate-400">Estados por chequeo en 24 horas</p>
          </header>
          <VueApexCharts
            v-if="!isTelemetryInitializing"
            type="line"
            height="300"
            :options="uptimeOptions"
            :series="uptimeSeries"
          />
          <div v-else class="space-y-3 rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="h-3 w-48 animate-pulse rounded bg-slate-700/80" />
            <div class="h-36 animate-pulse rounded-xl bg-slate-800/90" />
          </div>
        </article>
      </section>

      <section class="mt-8 grid gap-6 lg:grid-cols-2">
        <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
          <h2 class="text-lg font-semibold text-white">Certificado SSL</h2>
          <div
            v-if="isTelemetryInitializing && !hasSslTelemetry"
            class="mt-4 space-y-3 rounded-2xl border border-cyan-400/25 bg-cyan-500/5 p-4"
          >
            <p class="text-sm text-cyan-100">Aun no hay metadatos SSL disponibles para este sitio.</p>
            <div class="grid gap-3 sm:grid-cols-2">
              <div class="h-16 animate-pulse rounded-xl bg-slate-800/90" />
              <div class="h-16 animate-pulse rounded-xl bg-slate-800/90" />
              <div class="h-16 animate-pulse rounded-xl bg-slate-800/90" />
              <div class="h-16 animate-pulse rounded-xl bg-slate-800/90" />
            </div>
          </div>
          <div v-else class="mt-4 grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-slate-700 bg-slate-900 p-4">
              <p class="text-xs uppercase tracking-[0.18em] text-slate-300">Fecha de expiración</p>
              <p class="mt-2 text-lg font-semibold text-white">{{ site.ssl_certificate?.valid_until ? formatDate(site.ssl_certificate.valid_until) : 'Sin datos' }}</p>
            </div>
            <div class="rounded-xl border border-slate-700 bg-slate-900 p-4">
              <p class="text-xs uppercase tracking-[0.18em] text-slate-300">Emisor</p>
              <p class="mt-2 text-lg font-semibold text-white">{{ site.ssl_certificate?.issuer || 'Sin datos' }}</p>
            </div>
            <div class="rounded-xl border border-slate-700 bg-slate-900 p-4">
              <p class="text-xs uppercase tracking-[0.18em] text-slate-300">Días restantes</p>
              <p class="mt-2 text-lg font-semibold text-white">{{ site.ssl_certificate?.days_remaining ?? 'Sin datos' }}</p>
            </div>
            <div class="rounded-xl border border-slate-700 bg-slate-900 p-4">
              <p class="text-xs uppercase tracking-[0.18em] text-slate-300">Algoritmo</p>
              <p class="mt-2 text-lg font-semibold text-white">{{ site.ssl_certificate?.algorithm || 'Sin datos' }}</p>
            </div>
          </div>
        </article>

        <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
          <h2 class="text-lg font-semibold text-white">Cabeceras de seguridad</h2>
          <div
            v-if="isTelemetryInitializing && !hasSecurityHeadersTelemetry"
            class="mt-4 space-y-3 rounded-2xl border border-cyan-400/25 bg-cyan-500/5 p-4"
          >
            <p class="text-sm text-cyan-100">Aun no se han detectado cabeceras durante los escaneos iniciales.</p>
            <div class="space-y-3">
              <div class="h-14 animate-pulse rounded-xl bg-slate-800/90" />
              <div class="h-14 animate-pulse rounded-xl bg-slate-800/90" />
              <div class="h-14 animate-pulse rounded-xl bg-slate-800/90" />
            </div>
          </div>
          <ul v-else class="mt-4 space-y-3">
            <li
              v-for="header in securityHeaders"
              :key="header.key"
              class="rounded-xl border border-slate-700 bg-slate-900 p-4"
            >
              <div class="flex items-start justify-between gap-3">
                <div>
                  <p class="text-sm font-semibold text-white">{{ header.label }}</p>
                  <p class="mt-1 text-xs text-slate-400">{{ header.value || 'Sin valor reportado' }}</p>
                </div>
                <span
                  class="rounded-full px-3 py-1 text-xs font-semibold"
                  :class="header.present ? 'bg-emerald-500/15 text-emerald-300' : 'bg-rose-500/15 text-rose-300'"
                >
                  {{ header.present ? 'Presente' : 'Ausente' }}
                </span>
              </div>
            </li>
            <li v-if="securityHeaders.length === 0" class="rounded-xl border border-slate-700 bg-slate-900 p-4 text-sm text-slate-400">
              No hay registros de cabeceras para este sitio.
            </li>
          </ul>
        </article>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import VueApexCharts from 'vue3-apexcharts'

type TimelinePoint = {
  id: number
  checked_at: string
  status: 'up' | 'down' | 'degraded' | 'timeout' | 'unknown'
  http_code: number | null
  response_time_ms: number | null
}

type TrafficPoint = {
  at: string | null
  rpm: number
  error_rate_pct: number
}

type SecurityHeaderPoint = {
  key: string
  label: string
  present: boolean
  value: string
}

type SiteDetail = {
  id: number
  name: string
  url: string
  current_status: 'up' | 'down' | 'degraded' | 'unknown'
  ssl_certificate: {
    valid_until: string | null
    issuer: string | null
    days_remaining: number | null
    algorithm: string | null
  } | null
}

type EventItem = {
  id: number
}

type AlertItem = {
  id: number
}

const props = defineProps<{
  site: SiteDetail
  timeline: TimelinePoint[]
  statusBreakdown24h: Record<string, number>
  uptime24h: number
  avgResponse24h: number | null
  openAlerts: AlertItem[]
  events: EventItem[]
  trafficSeries24h: TrafficPoint[]
  trafficSeries1h: TrafficPoint[]
  securityHeaders: SecurityHeaderPoint[]
  updatedAt: string
}>()

const safeTimeline = computed(() => Array.isArray(props.timeline) ? props.timeline : [])
const safeTraffic24h = computed(() => Array.isArray(props.trafficSeries24h) ? props.trafficSeries24h : [])
const safeTraffic1h = computed(() => Array.isArray(props.trafficSeries1h) ? props.trafficSeries1h : [])
const securityHeaders = computed(() => Array.isArray(props.securityHeaders) ? props.securityHeaders : [])
const totalChecks24h = computed(() => {
  const breakdown = props.statusBreakdown24h ?? {}
  return Number(breakdown.up ?? 0) + Number(breakdown.down ?? 0) + Number(breakdown.degraded ?? 0) + Number(breakdown.timeout ?? 0)
})
const isTelemetryInitializing = computed(() => props.site.current_status === 'unknown' || totalChecks24h.value === 0)
const hasSslTelemetry = computed(() => {
  const cert = props.site.ssl_certificate
  if (!cert) {
    return false
  }

  return Boolean(cert.valid_until || cert.issuer || cert.days_remaining !== null || cert.algorithm)
})
const hasSecurityHeadersTelemetry = computed(() => securityHeaders.value.some((header) => header.present || header.value.trim() !== ''))

const ttfbSeries = computed(() => [{
  name: 'TTFB (ms)',
  data: safeTimeline.value
    .filter((point) => point.checked_at !== null)
    .map((point) => ({ x: point.checked_at, y: point.response_time_ms ?? 0 })),
}])

const uptimeSeries = computed(() => [{
  name: 'Disponibilidad',
  data: safeTimeline.value
    .filter((point) => point.checked_at !== null)
    .map((point) => ({
      x: point.checked_at,
      y: point.status === 'up' ? 100 : point.status === 'degraded' ? 50 : 0,
    })),
}])

const traffic24hSeries = computed(() => [{
  name: 'Requests por minuto',
  data: safeTraffic24h.value
    .filter((point) => point.at !== null)
    .map((point) => ({ x: point.at, y: point.rpm })),
}])

const traffic1hSeries = computed(() => [{
  name: 'Requests por minuto',
  data: safeTraffic1h.value
    .filter((point) => point.at !== null)
    .map((point) => ({ x: point.at, y: point.rpm })),
}])

const baseChartOptions = {
  chart: {
    toolbar: { show: false },
    animations: { enabled: true },
    foreColor: '#CBD5E1',
  },
  noData: {
    text: 'Sin muestras en este periodo',
    style: {
      color: '#94A3B8',
    },
  },
  tooltip: {
    theme: 'dark',
    x: {
      format: 'dd/MM HH:mm',
    },
  },
  stroke: {
    curve: 'smooth',
    width: 3,
  },
  xaxis: {
    type: 'datetime',
    labels: {
      datetimeUTC: false,
      style: { colors: '#94A3B8' },
    },
  },
  yaxis: {
    labels: { style: { colors: '#94A3B8' } },
  },
  grid: {
    borderColor: 'rgba(148,163,184,0.2)',
  },
}

const ttfbChartOptions = computed(() => ({
  ...baseChartOptions,
  colors: ['#22D3EE'],
  yaxis: {
    labels: { style: { colors: '#94A3B8' } },
    title: {
      text: 'Milisegundos',
      style: { color: '#94A3B8' },
    },
  },
}))

const traffic24hOptions = computed(() => ({
  ...baseChartOptions,
  colors: ['#60A5FA'],
  fill: {
    type: 'gradient',
    gradient: {
      shadeIntensity: 0.45,
      opacityFrom: 0.55,
      opacityTo: 0.05,
      stops: [0, 90, 100],
    },
  },
  stroke: {
    curve: 'smooth',
    width: 2,
  },
}))

const traffic1hOptions = computed(() => ({
  ...baseChartOptions,
  colors: ['#F59E0B'],
  plotOptions: {
    bar: {
      borderRadius: 6,
      columnWidth: '45%',
    },
  },
  stroke: {
    width: 0,
  },
}))

const uptimeOptions = computed(() => ({
  ...baseChartOptions,
  colors: ['#34D399'],
  yaxis: {
    min: 0,
    max: 100,
    tickAmount: 4,
    labels: {
      formatter: (value: number) => `${value}%`,
      style: { colors: '#94A3B8' },
    },
  },
}))

const statusLabel = (status: SiteDetail['current_status']) => {
  if (status === 'up') return 'ACTIVO'
  if (status === 'degraded') return 'DEGRADADO'
  if (status === 'down') return 'CAÍDO'
  return 'DESCONOCIDO'
}

const statusTextClass = (status: SiteDetail['current_status']) => {
  if (status === 'up') return 'text-emerald-300'
  if (status === 'degraded') return 'text-amber-300'
  if (status === 'down') return 'text-rose-300'
  return 'text-slate-300'
}

const formatDate = (value: string | null) => {
  if (!value) {
    return 'Sin datos'
  }

  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? 'Sin datos' : parsed.toLocaleString('es-MX')
}
</script>
