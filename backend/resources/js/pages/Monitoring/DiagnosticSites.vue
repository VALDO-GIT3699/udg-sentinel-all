<template>
  <main class="min-h-screen bg-slate-950 text-slate-100">
    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <header class="mb-6 flex flex-col gap-3 border-b border-slate-800 pb-5 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <a href="/monitoring/dashboard" class="text-sm text-cyan-300 hover:text-cyan-200">Volver al dashboard</a>
          <h1 class="mt-2 text-3xl font-semibold text-white sm:text-4xl">Sitios por diagnóstico: {{ bucketLabel }}</h1>
          <p class="mt-2 text-sm text-slate-300">Listado detallado de los sitios incluidos en la barra seleccionada.</p>
        </div>
        <p class="text-xs text-slate-400">Actualizado: {{ formattedUpdatedAt }}</p>
      </header>

      <form class="mb-5 flex w-full max-w-xl gap-2" @submit.prevent="applySearch">
        <input
          v-model="localSearch"
          type="search"
          placeholder="Buscar sitio, dominio o URL"
          class="h-11 flex-1 rounded-xl border border-slate-700 bg-slate-950 px-4 text-sm text-slate-100 placeholder:text-slate-500 focus:border-cyan-400 focus:outline-none"
        />
        <button type="submit" class="h-11 rounded-xl bg-cyan-400 px-4 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">Buscar</button>
        <button type="button" class="h-11 rounded-xl border border-slate-700 px-4 text-sm text-slate-200 transition hover:border-slate-500" @click="clearSearch">Limpiar</button>
      </form>

      <section class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
            <thead>
              <tr class="text-slate-400">
                <th class="px-4 py-4 font-medium">Sitio</th>
                <th class="px-4 py-4 font-medium">Dominio</th>
                <th class="px-4 py-4 font-medium">CMS</th>
                <th class="px-4 py-4 font-medium">IP servidor</th>
                <th class="px-4 py-4 font-medium">Certificado</th>
                <th class="px-4 py-4 font-medium">Estado operativo</th>
                <th class="px-4 py-4 font-medium">Estatus proyecto</th>
                <th class="px-4 py-4 font-medium">Comentarios</th>
                <th class="px-4 py-4 font-medium">Último check</th>
                <th class="px-4 py-4 font-medium">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/90">
              <tr v-for="site in rows" :key="site.id" class="cursor-pointer transition hover:bg-slate-800/70" @click="openSiteDetail(site.id)">
                <td class="px-4 py-4 align-middle">
                  <p class="font-medium text-white">{{ site.name || `Sitio #${site.id}` }}</p>
                  <p class="mt-1 text-xs text-slate-500">Abrir detalle</p>
                </td>
                <td class="px-4 py-4 text-slate-300">
                  <a :href="safeSiteUrl(site)" target="_blank" rel="noopener noreferrer" class="text-cyan-300 hover:text-cyan-200" @click.stop>
                    {{ displayDomain(site) }}
                  </a>
                </td>
                <td class="px-4 py-4 text-slate-300">{{ labelize(site.cms || 'Sin dato') }}</td>
                <td class="px-4 py-4 text-slate-300">{{ site.server_ip || 'Externo' }}</td>
                <td class="px-4 py-4 text-slate-300">{{ site.certificate_label || 'No' }}</td>
                <td class="px-4 py-4 text-slate-300">{{ statusLabel(resolveStatusCode(site)) }}</td>
                <td class="px-4 py-4 text-slate-300">{{ site.project_status || '-' }}</td>
                <td class="px-4 py-4 text-slate-300">{{ site.comments || '-' }}</td>
                <td class="px-4 py-4 text-slate-400">{{ formatCheckTime(site.last_checked_at) }}</td>
                <td class="px-4 py-4" @click.stop>
                  <button type="button" class="rounded-lg border border-cyan-600/60 px-3 py-1.5 text-xs font-semibold text-cyan-200 hover:border-cyan-400" @click="scanSingleSite(site.id)">
                    Reescanear
                  </button>
                </td>
              </tr>
              <tr v-if="rows.length === 0">
                <td :colspan="10" class="px-4 py-12 text-center text-sm text-slate-400">No hay sitios en este diagnóstico con el filtro actual.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <footer class="mt-5 flex flex-col gap-3 border-t border-slate-800 pt-5 sm:flex-row sm:items-center sm:justify-between">
          <div class="text-sm text-slate-400">Mostrando {{ firstVisibleItem }}-{{ lastVisibleItem }} de {{ total }} sitios.</div>
          <div class="flex items-center gap-3">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Página {{ currentPage }} de {{ lastPage }}</p>
            <button type="button" class="rounded-xl border border-slate-700 px-4 py-2 text-sm text-slate-200 transition hover:border-slate-500 disabled:cursor-not-allowed disabled:opacity-50" :disabled="currentPage <= 1" @click="goToPage(currentPage - 1)">Anterior</button>
            <button type="button" class="rounded-xl border border-slate-700 px-4 py-2 text-sm text-slate-200 transition hover:border-slate-500 disabled:cursor-not-allowed disabled:opacity-50" :disabled="currentPage >= lastPage" @click="goToPage(currentPage + 1)">Siguiente</button>
          </div>
        </footer>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'

type SiteItem = {
  id: number
  name: string | null
  domain: string | null
  url?: string | null
  cms?: string
  server_ip?: string
  certificate_label?: string
  project_status?: string
  comments?: string
  current_status?: string
  current_status_code?: string
  display_status_code?: string
  last_checked_at: string | null
}

type Paginated<T> = {
  data: T[]
  current_page?: number
  last_page?: number
  per_page?: number
  total?: number
}

const props = defineProps<{
  bucket: string
  bucketLabel: string
  search: string
  sites: Paginated<SiteItem>
  updatedAt: string
}>()

const localSearch = ref(props.search || '')

const rows = computed(() => props.sites?.data ?? [])
const currentPage = computed(() => props.sites?.current_page ?? 1)
const lastPage = computed(() => Math.max(1, props.sites?.last_page ?? 1))
const perPage = computed(() => props.sites?.per_page ?? 50)
const total = computed(() => props.sites?.total ?? rows.value.length)

const firstVisibleItem = computed(() => {
  if (total.value === 0) return 0
  return ((currentPage.value - 1) * perPage.value) + 1
})

const lastVisibleItem = computed(() => {
  if (total.value === 0) return 0
  return Math.min(total.value, firstVisibleItem.value + rows.value.length - 1)
})

const formattedUpdatedAt = computed(() => {
  const parsed = new Date(props.updatedAt)
  return Number.isNaN(parsed.getTime()) ? 'Sin dato' : parsed.toLocaleString('es-MX')
})

const resolveStatusCode = (site: SiteItem): 'up' | 'down' | 'degraded' | 'unknown' => {
  const display = (site.display_status_code ?? '').toLowerCase()
  if (display === 'up' || display === 'down' || display === 'degraded' || display === 'unknown') {
    return display
  }

  const raw = (site.current_status_code ?? site.current_status ?? '').toLowerCase()
  if (raw === 'up') return 'up'
  if (raw === 'down') return 'down'
  if (raw === 'degraded') return 'degraded'
  return 'unknown'
}

const statusLabel = (status: ReturnType<typeof resolveStatusCode>) => {
  if (status === 'up') return 'OPERATIVO'
  if (status === 'degraded') return 'CON INCIDENCIAS'
  if (status === 'down') return 'NO RESPONDE'
  return 'EN LA COLA'
}

const labelize = (value: string) => value.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())

const safeSiteUrl = (site: SiteItem) => {
  const candidate = site.url?.trim() || (site.domain ? `https://${site.domain}` : '')
  if (candidate === '') return '#'
  return candidate.startsWith('http://') || candidate.startsWith('https://') ? candidate : `https://${candidate}`
}

const displayDomain = (site: SiteItem) => {
  const domain = (site.domain ?? '').trim()
  const isIpDomain = /^(?:\d{1,3}\.){3}\d{1,3}$/.test(domain)

  if (isIpDomain) {
    const url = (site.url ?? '').trim()
    return url !== '' ? url.replace(/^https?:\/\//i, '') : domain
  }

  return domain !== '' ? domain : ((site.url ?? '').trim().replace(/^https?:\/\//i, '') || 'Sin dominio')
}

const formatCheckTime = (value: string | null) => {
  if (!value) return 'Nunca'
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? 'Nunca' : parsed.toLocaleString('es-MX')
}

const openSiteDetail = (siteId: number) => {
  router.visit(`/monitoring/sites/${siteId}/detail`)
}

const scanSingleSite = (siteId: number) => {
  router.post(`/monitoring/sites/${siteId}/scan`, {}, {
    preserveScroll: true,
  })
}

const goToPage = (page: number) => {
  if (page < 1 || page > lastPage.value) return

  router.get(`/monitoring/diagnostic/${props.bucket}`, {
    page,
    per_page: perPage.value,
    search: localSearch.value.trim() || undefined,
  }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

const applySearch = () => {
  router.get(`/monitoring/diagnostic/${props.bucket}`, {
    page: 1,
    per_page: perPage.value,
    search: localSearch.value.trim() || undefined,
  }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

const clearSearch = () => {
  localSearch.value = ''
  applySearch()
}
</script>
