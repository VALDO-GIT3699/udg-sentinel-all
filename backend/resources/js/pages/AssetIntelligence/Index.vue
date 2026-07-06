<template>
  <main class="min-h-screen bg-slate-950 text-slate-100">
    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <header class="mb-8 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-cyan-300">UDG Sentinel</p>
          <h1 class="mt-2 text-3xl font-semibold text-white">Asset Intelligence</h1>
          <p class="mt-2 text-sm text-slate-300">Gobierno de inventario, calidad de clasificación y cola de revisión.</p>
        </div>
        <p class="text-xs text-slate-400">Actualizado: {{ formatDate(updatedAt) }}</p>
      </header>

      <section v-if="!enabled" class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-5 text-amber-100">
        Asset Intelligence no esta habilitado en la base de datos. Ejecuta migraciones para activar esta seccion.
      </section>

      <template v-else>
        <section class="grid gap-4 md:grid-cols-4">
          <article class="rounded-2xl border border-cyan-500/30 bg-cyan-500/10 p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-cyan-200">Activos clasificados</p>
            <p class="mt-2 text-3xl font-semibold text-white">{{ metrics.classified_pct }}%</p>
          </article>
          <article class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-amber-200">Unknown</p>
            <p class="mt-2 text-3xl font-semibold text-white">{{ metrics.unknown_pct }}%</p>
          </article>
          <article class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-emerald-200">Confianza promedio</p>
            <p class="mt-2 text-3xl font-semibold text-white">{{ metrics.avg_confidence }}%</p>
          </article>
          <article class="rounded-2xl border border-fuchsia-500/30 bg-fuchsia-500/10 p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-fuchsia-200">Overrides manuales</p>
            <p class="mt-2 text-3xl font-semibold text-white">{{ metrics.manual_overrides }}</p>
          </article>
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-2">
          <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
            <h2 class="text-lg font-semibold text-white">Distribucion por Asset Type</h2>
            <ul class="mt-4 space-y-2 text-sm">
              <li v-for="item in typeDistribution" :key="`type-${item.key}`" class="flex items-center justify-between rounded-lg border border-slate-800 px-3 py-2">
                <span>{{ labelize(item.key) }}</span>
                <span class="font-semibold text-cyan-200">{{ item.total }}</span>
              </li>
            </ul>
          </article>

          <article class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
            <h2 class="text-lg font-semibold text-white">Distribucion por Asset Role</h2>
            <ul class="mt-4 space-y-2 text-sm">
              <li v-for="item in roleDistribution" :key="`role-${item.key}`" class="flex items-center justify-between rounded-lg border border-slate-800 px-3 py-2">
                <span>{{ labelize(item.key) }}</span>
                <span class="font-semibold text-cyan-200">{{ item.total }}</span>
              </li>
            </ul>
          </article>
        </section>

        <section class="mt-6 rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
          <h2 class="text-lg font-semibold text-white">Cola de revision manual</h2>
          <p class="mt-1 text-sm text-slate-400">Activos unknown, baja confianza o clasificacion automatica fragil.</p>
          <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
              <thead>
                <tr class="text-slate-400">
                  <th class="px-3 py-2">Activo</th>
                  <th class="px-3 py-2">Type</th>
                  <th class="px-3 py-2">Role</th>
                  <th class="px-3 py-2">Confianza</th>
                  <th class="px-3 py-2">Origen</th>
                  <th class="px-3 py-2">Accion</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-800/80">
                <tr v-for="item in reviewQueue" :key="`queue-${item.id}`">
                  <td class="px-3 py-2">
                    <a :href="`/monitoring/sites/${item.id}/detail`" class="text-cyan-300 hover:text-cyan-200">{{ item.name }}</a>
                    <p class="text-xs text-slate-500">{{ item.domain }}</p>
                  </td>
                  <td class="px-3 py-2">{{ labelize(item.asset_type) }}</td>
                  <td class="px-3 py-2">{{ labelize(item.asset_role) }}</td>
                  <td class="px-3 py-2">{{ item.confidence_pct }}%</td>
                  <td class="px-3 py-2">{{ item.source === 'manual' ? 'Manual' : 'Automática' }}</td>
                  <td class="px-3 py-2">
                    <button
                      type="button"
                      class="rounded-lg border border-cyan-600/60 px-3 py-1.5 text-xs font-semibold text-cyan-200 hover:border-cyan-400"
                      :disabled="isApprovingId === item.id"
                      @click="approveClassification(item.id)"
                    >
                      {{ isApprovingId === item.id ? 'Aprobando...' : 'Aprobar' }}
                    </button>
                  </td>
                </tr>
                <tr v-if="reviewQueue.length === 0">
                  <td colspan="6" class="px-3 py-6 text-center text-slate-400">Sin pendientes de revision.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>
    </section>
  </main>
</template>

<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { ref } from 'vue'

type DistributionItem = { key: string; total: number }
type QueueItem = {
  id: number
  name: string
  domain: string
  asset_type: string
  asset_role: string
  confidence_pct: number
  source: string
}

const props = defineProps<{
  enabled: boolean
  metrics: {
    total_assets?: number
    classified_assets?: number
    classified_pct?: number
    unknown_assets?: number
    unknown_pct?: number
    avg_confidence?: number
    manual_overrides?: number
  }
  typeDistribution: DistributionItem[]
  roleDistribution: DistributionItem[]
  reviewQueue: QueueItem[]
  recentChanges: Array<Record<string, unknown>>
  updatedAt: string
}>()

const isApprovingId = ref<number | null>(null)

const labelize = (value: string) => value.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())

const formatDate = (value: string) => {
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? 'Sin dato' : parsed.toLocaleString('es-MX')
}

const metrics = {
  classified_pct: Number(props.metrics?.classified_pct ?? 0),
  unknown_pct: Number(props.metrics?.unknown_pct ?? 0),
  avg_confidence: Number(props.metrics?.avg_confidence ?? 0),
  manual_overrides: Number(props.metrics?.manual_overrides ?? 0),
}

const approveClassification = (siteId: number) => {
  isApprovingId.value = siteId

  router.post(`/monitoring/sites/${siteId}/classification/approve`, {}, {
    preserveScroll: true,
    onFinish: () => {
      isApprovingId.value = null
    },
  })
}
</script>
