<template>
  <main class="min-h-screen bg-sky-50 text-slate-900">
    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <TopNav current="assets" />
      <header class="mb-8 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-cyan-600">UDG Sentinel</p>
          <h1 class="mt-2 text-fluid-2xl font-semibold text-slate-900">Catálogo de activos</h1>
          <p class="mt-2 max-w-2xl text-sm text-slate-700">
            Clasifica automáticamente cada sitio monitoreado por <strong>qué es</strong> (sitio web, correo
            institucional, API, VPN, DNS...) y <strong>para qué se usa</strong> (sistema escolar/SIIAU, LMS,
            centro universitario...), a partir de su dominio y tecnología detectada. Sirve para saber de un
            vistazo qué tipo de infraestructura tiene la universidad, sin revisar sitio por sitio.
          </p>
        </div>
        <p class="text-xs text-slate-600">Actualizado: {{ formatDate(updatedAt) }}</p>
      </header>

      <section v-if="!enabled" class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-5 text-amber-800">
        Asset Intelligence no esta habilitado en la base de datos. Ejecuta migraciones para activar esta seccion.
      </section>

      <template v-else>
        <section class="grid gap-4 md:grid-cols-4">
          <article class="rounded-2xl border border-cyan-500/30 bg-cyan-500/10 p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-cyan-700">Activos clasificados</p>
            <p class="mt-2 text-fluid-2xl font-semibold text-slate-900">{{ metrics.classified_pct }}%</p>
          </article>
          <article class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-amber-700">Sin clasificar</p>
            <p class="mt-2 text-fluid-2xl font-semibold text-slate-900">{{ metrics.unknown_pct }}%</p>
          </article>
          <article class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-emerald-700">Confianza promedio</p>
            <p class="mt-2 text-fluid-2xl font-semibold text-slate-900">{{ metrics.avg_confidence }}%</p>
          </article>
          <article class="rounded-2xl border border-fuchsia-500/30 bg-fuchsia-500/10 p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-fuchsia-700">Overrides manuales</p>
            <p class="mt-2 text-fluid-2xl font-semibold text-slate-900">{{ metrics.manual_overrides }}</p>
          </article>
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-2">
          <article class="rounded-3xl border border-sky-200 bg-white/80 p-5">
            <h2 class="text-lg font-semibold text-slate-900">Qué es cada activo</h2>
            <p class="mt-1 text-xs text-slate-500">Naturaleza técnica: sitio web, API, correo, VPN, DNS...</p>
            <ul class="mt-4 space-y-2 text-sm">
              <li v-for="item in typeDistribution" :key="`type-${item.key}`" class="flex items-center justify-between rounded-lg border border-sky-200 px-3 py-2">
                <span>{{ labelize(item.key) }}</span>
                <span class="font-semibold text-cyan-700">{{ item.total }}</span>
              </li>
            </ul>
          </article>

          <article class="rounded-3xl border border-sky-200 bg-white/80 p-5">
            <h2 class="text-lg font-semibold text-slate-900">Para qué se usa</h2>
            <p class="mt-1 text-xs text-slate-500">Función institucional: sistema escolar, LMS, centro universitario...</p>
            <ul class="mt-4 space-y-2 text-sm">
              <li v-for="item in roleDistribution" :key="`role-${item.key}`" class="flex items-center justify-between rounded-lg border border-sky-200 px-3 py-2">
                <span>{{ labelize(item.key) }}</span>
                <span class="font-semibold text-cyan-700">{{ item.total }}</span>
              </li>
            </ul>
          </article>
        </section>

        <section class="mt-6 rounded-3xl border border-sky-200 bg-white/80 p-5">
          <h2 class="text-lg font-semibold text-slate-900">Pendientes de revisar a mano</h2>
          <p class="mt-1 text-sm text-slate-600">
            Sitios que la clasificación automática no pudo identificar con seguridad (sin datos suficientes o
            confianza baja) — vale la pena que alguien los revise y confirme manualmente.
          </p>
          <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-sky-200 text-left text-sm">
              <thead>
                <tr class="text-slate-600">
                  <th class="px-3 py-2">Activo</th>
                  <th class="px-3 py-2">Qué es</th>
                  <th class="px-3 py-2">Para qué se usa</th>
                  <th class="px-3 py-2">Confianza</th>
                  <th class="px-3 py-2">Origen</th>
                  <th class="px-3 py-2">Accion</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-sky-200/80">
                <tr v-for="item in reviewQueue" :key="`queue-${item.id}`">
                  <td class="px-3 py-2">
                    <a :href="`/monitoring/sites/${item.id}/detail`" class="text-cyan-600 hover:text-cyan-700">{{ item.name }}</a>
                    <p class="text-xs text-slate-500">{{ item.domain }}</p>
                  </td>
                  <td class="px-3 py-2">{{ labelize(item.asset_type) }}</td>
                  <td class="px-3 py-2">{{ labelize(item.asset_role) }}</td>
                  <td class="px-3 py-2">{{ item.confidence_pct }}%</td>
                  <td class="px-3 py-2">{{ item.source === 'manual' ? 'Manual' : 'Automática' }}</td>
                  <td class="px-3 py-2">
                    <button
                      v-if="canManageSites"
                      type="button"
                      class="rounded-lg border border-cyan-600/60 px-3 py-1.5 text-xs font-semibold text-cyan-700 hover:border-cyan-400"
                      :disabled="isApprovingId === item.id"
                      @click="approveClassification(item.id)"
                    >
                      {{ isApprovingId === item.id ? 'Aprobando...' : 'Aprobar' }}
                    </button>
                    <span v-else class="text-xs text-slate-400">Sin permiso</span>
                  </td>
                </tr>
                <tr v-if="reviewQueue.length === 0">
                  <td colspan="6" class="px-3 py-6 text-center text-slate-600">No hay pendientes por revisar.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>

      <AppFooter />
    </section>

    <CookieNotice />
  </main>
</template>

<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import TopNav from '@/components/layout/TopNav.vue'
import AppFooter from '@/components/layout/AppFooter.vue'
import CookieNotice from '@/components/ui/CookieNotice.vue'

const canManageSites = computed(() =>
  ((usePage().props as { auth?: { permissions?: string[] } }).auth?.permissions ?? []).includes('monitoring.manage_sites'),
)

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
