<template>
  <main class="relative min-h-screen overflow-x-hidden bg-sky-50 text-slate-900">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden" aria-hidden="true">
      <div class="absolute -left-40 -top-48 h-[34rem] w-[34rem] rounded-full bg-sky-300/25 blur-[130px]" />
      <div class="absolute -right-32 top-1/4 h-[30rem] w-[30rem] rounded-full bg-blue-300/20 blur-[130px]" />
      <div class="absolute bottom-[-10rem] left-1/3 h-[28rem] w-[28rem] rounded-full bg-emerald-300/15 blur-[130px]" />
      <div class="absolute left-1/2 top-0 h-[24rem] w-[50rem] -translate-x-1/2 rounded-full bg-sky-200/25 blur-[150px]" />
    </div>

    <section class="relative z-10 mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <TopNav current="admin" />
      <Breadcrumbs :items="[{ label: 'Administración', href: '/monitoring/admin/users' }, { label: 'Papelera' }]" />

      <header class="mb-8">
        <p class="text-xs uppercase tracking-[0.22em] text-cyan-600">UDG Sentinel</p>
        <h1 class="mt-2 text-fluid-2xl font-semibold text-slate-900">Papelera</h1>
        <p class="mt-2 max-w-2xl text-sm text-slate-700">
          Sitios y usuarios eliminados. Nada aquí se borra de verdad hasta que lo decidas: restaura lo que
          se haya quitado por error.
        </p>
      </header>

      <p v-if="actionMessage" class="glass-panel mb-4 rounded-xl bg-white/50 px-4 py-2.5 text-sm text-slate-800">
        {{ actionMessage }}
      </p>

      <section class="glass-panel mb-8 rounded-2xl bg-white/50 p-5">
        <h2 class="text-lg font-semibold text-slate-900">Sitios eliminados</h2>
        <div class="mt-4 overflow-x-auto">
          <table class="min-w-full divide-y divide-sky-200 text-left text-sm">
            <thead>
              <tr class="text-slate-600">
                <th class="px-3 py-2 font-medium">Sitio</th>
                <th class="px-3 py-2 font-medium">Dominio</th>
                <th class="px-3 py-2 font-medium">Eliminado</th>
                <th class="px-3 py-2 font-medium">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-sky-200/80">
              <tr v-for="site in localSites" :key="site.id">
                <td class="px-3 py-2 text-slate-900">{{ site.name }}</td>
                <td class="px-3 py-2 text-slate-700">{{ site.domain }}</td>
                <td class="px-3 py-2 text-slate-600">{{ formatDate(site.deleted_at) }}</td>
                <td class="px-3 py-2">
                  <button
                    type="button"
                    class="rounded-lg border border-emerald-600/60 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:border-emerald-400 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="restoringSiteId === site.id"
                    @click="restoreSite(site.id)"
                  >
                    {{ restoringSiteId === site.id ? 'Restaurando...' : 'Restaurar' }}
                  </button>
                </td>
              </tr>
              <tr v-if="localSites.length === 0">
                <td colspan="4" class="px-3 py-6 text-center text-slate-600">No hay sitios eliminados.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="glass-panel rounded-2xl bg-white/50 p-5">
        <h2 class="text-lg font-semibold text-slate-900">Usuarios eliminados</h2>
        <div class="mt-4 overflow-x-auto">
          <table class="min-w-full divide-y divide-sky-200 text-left text-sm">
            <thead>
              <tr class="text-slate-600">
                <th class="px-3 py-2 font-medium">Nombre</th>
                <th class="px-3 py-2 font-medium">Usuario / correo</th>
                <th class="px-3 py-2 font-medium">Eliminado</th>
                <th class="px-3 py-2 font-medium">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-sky-200/80">
              <tr v-for="user in localUsers" :key="user.id">
                <td class="px-3 py-2 text-slate-900">{{ user.name }}</td>
                <td class="px-3 py-2 text-slate-700">{{ user.email }}</td>
                <td class="px-3 py-2 text-slate-600">{{ formatDate(user.deleted_at) }}</td>
                <td class="px-3 py-2">
                  <button
                    type="button"
                    class="rounded-lg border border-emerald-600/60 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:border-emerald-400 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="restoringUserId === user.id"
                    @click="restoreUser(user.id)"
                  >
                    {{ restoringUserId === user.id ? 'Restaurando...' : 'Restaurar' }}
                  </button>
                </td>
              </tr>
              <tr v-if="localUsers.length === 0">
                <td colspan="4" class="px-3 py-6 text-center text-slate-600">No hay usuarios eliminados.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <AppFooter />
    </section>

    <CookieNotice />
  </main>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import TopNav from '@/components/layout/TopNav.vue'
import AppFooter from '@/components/layout/AppFooter.vue'
import CookieNotice from '@/components/ui/CookieNotice.vue'
import Breadcrumbs from '@/components/layout/Breadcrumbs.vue'

type TrashedSite = { id: number; name: string; domain: string; deleted_at: string | null }
type TrashedUser = { id: number; name: string; email: string; department: string | null; deleted_at: string | null }

const props = defineProps<{
  sites: TrashedSite[]
  users: TrashedUser[]
}>()

const localSites = ref<TrashedSite[]>([...props.sites])
const localUsers = ref<TrashedUser[]>([...props.users])
const restoringSiteId = ref<number | null>(null)
const restoringUserId = ref<number | null>(null)
const actionMessage = ref('')

const getCsrfToken = () => {
  const meta = document.querySelector('meta[name="csrf-token"]')
  return meta instanceof HTMLMetaElement ? meta.content || '' : ''
}

const formatDate = (value: string | null) => {
  if (!value) return '—'
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? '—' : parsed.toLocaleString('es-MX')
}

async function restoreSite(id: number) {
  restoringSiteId.value = id
  try {
    const response = await fetch(`/monitoring/trash/sites/${id}/restore`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
      credentials: 'same-origin',
    })
    if (response.ok) {
      localSites.value = localSites.value.filter((site) => site.id !== id)
      actionMessage.value = 'Sitio restaurado correctamente.'
    } else {
      actionMessage.value = 'No fue posible restaurar el sitio.'
    }
  } finally {
    restoringSiteId.value = null
  }
}

async function restoreUser(id: number) {
  restoringUserId.value = id
  try {
    const response = await fetch(`/monitoring/trash/users/${id}/restore`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
      credentials: 'same-origin',
    })
    if (response.ok) {
      localUsers.value = localUsers.value.filter((user) => user.id !== id)
      actionMessage.value = 'Usuario restaurado correctamente.'
    } else {
      actionMessage.value = 'No fue posible restaurar el usuario.'
    }
  } finally {
    restoringUserId.value = null
  }
}
</script>
