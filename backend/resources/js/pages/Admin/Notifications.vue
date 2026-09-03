<template>
  <main class="relative min-h-screen overflow-x-hidden bg-sky-50 text-slate-900">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden" aria-hidden="true">
      <div class="absolute -left-40 -top-48 h-[34rem] w-[34rem] rounded-full bg-sky-300/25 blur-[130px]" />
      <div class="absolute -right-32 top-1/4 h-[30rem] w-[30rem] rounded-full bg-blue-300/20 blur-[130px]" />
    </div>

    <section class="relative z-10 mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
      <TopNav current="admin" />
      <Breadcrumbs :items="[{ label: 'Administración', href: '/monitoring/admin/users' }, { label: 'Notificaciones' }]" />

      <header class="mb-8">
        <p class="text-xs uppercase tracking-[0.22em] text-cyan-600">UDG Sentinel</p>
        <h1 class="mt-2 text-fluid-2xl font-semibold text-slate-900">Notificaciones de incidentes</h1>
        <p class="mt-2 max-w-2xl text-sm text-slate-700">
          Cuando un sitio prioritario cae o se degrada de forma crítica, Sentinel puede avisar por correo o
          Slack además de mostrarlo en el dashboard.
        </p>
      </header>

      <p v-if="statusMessage" class="glass-panel mb-4 rounded-xl bg-white/50 px-4 py-2.5 text-sm text-slate-800">
        {{ statusMessage }}
      </p>

      <section class="glass-panel mb-6 rounded-2xl bg-white/70 p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-slate-900">Envío de notificaciones externas</h2>
            <p class="mt-1 text-sm text-slate-600">
              Interruptor general. Si está apagado, ningún canal envía nada — los incidentes se siguen
              registrando en el dashboard y la auditoría de todas formas.
            </p>
          </div>
          <button
            type="button"
            class="glass-btn h-10 whitespace-nowrap rounded-xl px-4 text-sm font-semibold"
            :class="globalEnabled
              ? 'border-emerald-500/50 text-emerald-700 hover:border-emerald-400'
              : 'border-slate-400/50 text-slate-700 hover:border-slate-600'"
            :disabled="isTogglingGlobal"
            @click="toggleGlobal"
          >
            {{ globalEnabled ? 'Activado — apagar' : 'Desactivado — encender' }}
          </button>
        </div>
      </section>

      <section class="glass-panel mb-6 rounded-2xl bg-white/70 p-6">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold text-slate-900">Canales</h2>
          <button
            type="button"
            class="glass-btn h-9 whitespace-nowrap rounded-xl border-cyan-400/50 px-3 text-xs font-semibold text-cyan-700 hover:border-cyan-300"
            @click="isFormOpen = !isFormOpen"
          >
            {{ isFormOpen ? 'Cancelar' : 'Agregar canal' }}
          </button>
        </div>

        <form v-if="isFormOpen" class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto_1fr_auto]" @submit.prevent="createChannel">
          <input
            v-model="newChannel.name"
            type="text"
            required
            placeholder="Nombre (ej. Correo de guardia)"
            class="glass-input h-10 rounded-xl px-3 text-sm text-slate-900 placeholder:text-slate-500 focus:border-cyan-400 focus:outline-none"
          >
          <select
            v-model="newChannel.type"
            class="glass-input h-10 rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
          >
            <option value="email">Correo</option>
            <option value="slack">Slack</option>
            <option value="webhook">Webhook</option>
          </select>
          <input
            v-model="newChannel.destination"
            type="text"
            required
            :placeholder="newChannel.type === 'email' ? 'destino@udg.mx' : 'https://hooks.slack.com/...'"
            class="glass-input h-10 rounded-xl px-3 text-sm text-slate-900 placeholder:text-slate-500 focus:border-cyan-400 focus:outline-none"
          >
          <button
            type="submit"
            class="glass-btn h-10 whitespace-nowrap rounded-xl border-cyan-300/40 bg-cyan-400 px-4 text-sm font-semibold text-slate-50 hover:bg-cyan-300"
            :disabled="isSavingChannel"
          >
            {{ isSavingChannel ? 'Guardando...' : 'Guardar' }}
          </button>
        </form>
        <p v-if="formError" class="mt-2 text-xs text-rose-600">{{ formError }}</p>

        <div class="mt-4 overflow-x-auto">
          <table class="min-w-full divide-y divide-sky-200 text-left text-sm">
            <thead>
              <tr class="text-slate-600">
                <th class="px-3 py-2 font-medium">Nombre</th>
                <th class="px-3 py-2 font-medium">Tipo</th>
                <th class="px-3 py-2 font-medium">Destino</th>
                <th class="px-3 py-2 font-medium">Estado</th>
                <th class="px-3 py-2 font-medium">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-sky-200/80">
              <tr v-for="channel in localChannels" :key="channel.id">
                <td class="px-3 py-2 text-slate-900">{{ channel.name }}</td>
                <td class="px-3 py-2 text-slate-700 capitalize">{{ channel.type }}</td>
                <td class="px-3 py-2 text-slate-700">{{ channel.destination ?? '—' }}</td>
                <td class="px-3 py-2">
                  <span
                    class="glass-badge rounded-full px-2.5 py-1 text-[11px] font-semibold"
                    :class="channel.is_active ? 'text-emerald-700' : 'text-slate-500'"
                  >
                    {{ channel.is_active ? 'Activo' : 'Pausado' }}
                  </span>
                </td>
                <td class="px-3 py-2">
                  <div class="flex gap-2">
                    <button
                      type="button"
                      class="rounded-lg border border-sky-400/50 px-2.5 py-1 text-xs text-slate-800 hover:border-sky-600"
                      @click="toggleChannel(channel)"
                    >
                      {{ channel.is_active ? 'Pausar' : 'Activar' }}
                    </button>
                    <button
                      type="button"
                      class="rounded-lg border border-rose-500/50 px-2.5 py-1 text-xs text-rose-700 hover:border-rose-400"
                      @click="deleteChannel(channel)"
                    >
                      Eliminar
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="localChannels.length === 0">
                <td colspan="5" class="px-3 py-6 text-center text-slate-600">
                  Sin canales configurados todavía.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="glass-panel rounded-2xl bg-white/70 p-6">
        <h2 class="text-lg font-semibold text-slate-900">Últimos envíos</h2>
        <div class="mt-4 overflow-x-auto">
          <table class="min-w-full divide-y divide-sky-200 text-left text-sm">
            <thead>
              <tr class="text-slate-600">
                <th class="px-3 py-2 font-medium">Fecha</th>
                <th class="px-3 py-2 font-medium">Canal</th>
                <th class="px-3 py-2 font-medium">Alerta</th>
                <th class="px-3 py-2 font-medium">Resultado</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-sky-200/80">
              <tr v-for="entry in recentDeliveries" :key="entry.id">
                <td class="px-3 py-2 text-slate-600">{{ formatDate(entry.sent_at) }}</td>
                <td class="px-3 py-2 text-slate-800">{{ entry.channel_name }}</td>
                <td class="px-3 py-2 text-slate-700">{{ entry.alert_title }}</td>
                <td class="px-3 py-2">
                  <span
                    class="glass-badge rounded-full px-2.5 py-1 text-[11px] font-semibold"
                    :class="entry.status === 'sent' ? 'text-emerald-700' : 'text-rose-700'"
                    :title="entry.error_message ?? ''"
                  >
                    {{ entry.status === 'sent' ? 'Enviado' : 'Falló' }}
                  </span>
                </td>
              </tr>
              <tr v-if="recentDeliveries.length === 0">
                <td colspan="4" class="px-3 py-6 text-center text-slate-600">Sin envíos registrados aún.</td>
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

type Channel = {
  id: number
  name: string
  type: 'email' | 'slack' | 'webhook'
  is_active: boolean
  destination: string | null
}

type Delivery = {
  id: number
  channel_name: string
  alert_title: string
  status: string
  error_message: string | null
  sent_at: string | null
}

const props = defineProps<{
  globalEnabled: boolean
  channels: Channel[]
  recentDeliveries: Delivery[]
}>()

const globalEnabled = ref(props.globalEnabled)
const localChannels = ref<Channel[]>([...props.channels])
const recentDeliveries = ref<Delivery[]>([...props.recentDeliveries])
const isTogglingGlobal = ref(false)
const isSavingChannel = ref(false)
const isFormOpen = ref(false)
const formError = ref('')
const statusMessage = ref('')
const newChannel = ref({ name: '', type: 'email' as Channel['type'], destination: '' })

const getCsrfToken = () => {
  const meta = document.querySelector('meta[name="csrf-token"]')
  return meta instanceof HTMLMetaElement ? meta.content || '' : ''
}

const formatDate = (value: string | null) => {
  if (!value) return '—'
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? '—' : parsed.toLocaleString('es-MX')
}

async function toggleGlobal() {
  isTogglingGlobal.value = true
  try {
    const response = await fetch('/monitoring/admin/notifications/global', {
      method: 'PATCH',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({ enabled: !globalEnabled.value }),
    })
    if (response.ok) {
      globalEnabled.value = !globalEnabled.value
      statusMessage.value = globalEnabled.value
        ? 'Notificaciones externas activadas.'
        : 'Notificaciones externas desactivadas.'
    }
  } finally {
    isTogglingGlobal.value = false
  }
}

async function createChannel() {
  isSavingChannel.value = true
  formError.value = ''
  try {
    const response = await fetch('/monitoring/admin/notifications/channels', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify(newChannel.value),
    })
    const raw = await response.text()
    const data = raw.trim() !== '' ? JSON.parse(raw) : {}

    if (!response.ok) {
      formError.value = data?.errors
        ? Object.values(data.errors as Record<string, string[]>).flat().join(' ')
        : data?.message ?? 'No se pudo crear el canal.'
      return
    }

    localChannels.value.push({
      id: data.id ?? Date.now(),
      name: newChannel.value.name,
      type: newChannel.value.type,
      is_active: true,
      destination: newChannel.value.type === 'email' ? newChannel.value.destination : null,
    })
    newChannel.value = { name: '', type: 'email', destination: '' }
    isFormOpen.value = false
    statusMessage.value = 'Canal creado.'
  } finally {
    isSavingChannel.value = false
  }
}

async function toggleChannel(channel: Channel) {
  const response = await fetch(`/monitoring/admin/notifications/channels/${channel.id}/toggle`, {
    method: 'PATCH',
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
    credentials: 'same-origin',
  })
  if (response.ok) {
    channel.is_active = !channel.is_active
  }
}

async function deleteChannel(channel: Channel) {
  const response = await fetch(`/monitoring/admin/notifications/channels/${channel.id}`, {
    method: 'DELETE',
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
    credentials: 'same-origin',
  })
  if (response.ok) {
    localChannels.value = localChannels.value.filter((item) => item.id !== channel.id)
  }
}
</script>
