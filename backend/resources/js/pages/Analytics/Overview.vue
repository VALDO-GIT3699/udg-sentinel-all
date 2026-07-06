<template>
  <main class="min-h-screen bg-slate-950 text-slate-100">
    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <header class="mb-8 flex items-end justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-cyan-300">UDG Sentinel</p>
          <h1 class="mt-2 text-3xl font-semibold text-white">Centro Analítico Ejecutivo</h1>
          <p class="mt-2 text-sm text-slate-300">Tendencias, cobertura y riesgo institucional sobre inventario y observabilidad.</p>
        </div>
        <p class="text-xs text-slate-400">Actualizado: {{ formatDate(summary.generated_at) }}</p>
      </header>

      <section class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
        <article class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4" v-for="kpi in kpiCards" :key="kpi.label">
          <p class="text-[11px] uppercase tracking-[0.16em] text-emerald-200">{{ kpi.label }}</p>
          <p class="mt-2 text-2xl font-semibold text-white">{{ kpi.value }}</p>
        </article>
      </section>

      <section class="mt-6 grid gap-6 xl:grid-cols-2">
        <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
          <h2 class="text-lg font-semibold text-white">Top tecnologías</h2>
          <ul class="mt-4 space-y-2 text-sm">
            <li v-for="item in summary.technology_distribution" :key="item.key" class="flex items-center justify-between rounded-lg border border-slate-800 px-3 py-2">
              <span>{{ labelize(item.key) }}</span>
              <span class="font-semibold text-cyan-200">{{ item.total }}</span>
            </li>
          </ul>
        </article>

        <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
          <h2 class="text-lg font-semibold text-white">Top activos críticos</h2>
          <ul class="mt-4 space-y-2 text-sm">
            <li v-for="item in summary.top_critical_assets" :key="item.id" class="rounded-lg border border-slate-800 px-3 py-2">
              <a :href="`/monitoring/sites/${item.id}/detail`" class="font-semibold text-cyan-200 hover:text-cyan-100">{{ item.name }}</a>
              <p class="text-xs text-slate-400">{{ item.domain }} · {{ labelize(item.status) }} · {{ labelize(item.asset_type) }}</p>
            </li>
          </ul>
        </article>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed } from 'vue'

type DistributionItem = { key: string; total: number }
type CriticalAsset = {
  id: number
  name: string
  domain: string
  status: string
  asset_type: string
  asset_role: string
  confidence_pct: number
}

type Summary = {
  kpis: {
    institutional_health_pct: number
    availability_24h_pct: number
    inventory_coverage_pct: number
    open_alerts: number
    open_incidents: number
    assets_total: number
  }
  technology_distribution: DistributionItem[]
  top_critical_assets: CriticalAsset[]
  generated_at: string
}

const props = defineProps<{ summary: Summary }>()

const summary = props.summary

const kpiCards = computed(() => [
  { label: 'Salud institucional', value: `${summary.kpis.institutional_health_pct}%` },
  { label: 'Disponibilidad 24h', value: `${summary.kpis.availability_24h_pct}%` },
  { label: 'Cobertura inventario', value: `${summary.kpis.inventory_coverage_pct}%` },
  { label: 'Alertas abiertas', value: summary.kpis.open_alerts },
  { label: 'Incidentes abiertos', value: summary.kpis.open_incidents },
  { label: 'Activos totales', value: summary.kpis.assets_total },
])

const labelize = (value: string) => value.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())

const formatDate = (value: string) => {
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? 'Sin dato' : parsed.toLocaleString('es-MX')
}
</script>
