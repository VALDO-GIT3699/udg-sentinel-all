<template>
  <main class="relative min-h-screen overflow-x-hidden bg-sky-50 text-slate-900">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden" aria-hidden="true">
      <div class="absolute -left-40 -top-48 h-[34rem] w-[34rem] rounded-full bg-sky-300/25 blur-[130px]" />
      <div class="absolute -right-32 top-1/4 h-[30rem] w-[30rem] rounded-full bg-blue-300/20 blur-[130px]" />
      <div class="absolute bottom-[-10rem] left-1/3 h-[28rem] w-[28rem] rounded-full bg-emerald-300/15 blur-[130px]" />
    </div>

    <section class="relative z-10 mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
      <TopNav current="dashboard" />
      <Breadcrumbs :items="[{ label: 'Mi cuenta' }]" />

      <header class="glass-panel mb-8 flex flex-wrap items-center gap-4 rounded-2xl bg-white/60 p-5">
        <img
          src="/images/universidad-de-guadalajara-logo-png_seeklogo-617642.png"
          alt="Universidad de Guadalajara"
          class="h-14 w-14 shrink-0 object-contain"
          width="56"
          height="56"
        >
        <div class="h-11 w-px bg-slate-900/10" />
        <div>
          <p class="text-xs uppercase tracking-[0.22em] text-cyan-600">UDG Sentinel</p>
          <h1 class="mt-1 text-fluid-2xl font-semibold text-slate-900">Mi cuenta</h1>
          <p class="mt-1 max-w-2xl text-sm text-slate-700">
            Datos de acceso y verificación en dos pasos para tu propia cuenta.
          </p>
        </div>
      </header>

      <p v-if="statusMessage" class="glass-panel mb-4 rounded-xl bg-white/50 px-4 py-2.5 text-sm text-slate-800">
        {{ statusMessage }}
      </p>

      <div class="glass-panel rounded-2xl bg-white/70 p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-cyan-500/15 text-cyan-700" aria-hidden="true">
              <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" /></svg>
            </span>
            <div>
              <h2 class="text-lg font-semibold text-slate-900">Datos de acceso</h2>
              <p class="mt-1 text-sm text-slate-600">
                Cambia tu nombre de usuario o tu contraseña. Necesitas confirmar tu contraseña actual.
              </p>
            </div>
          </div>
        </div>

        <form class="mt-5 grid gap-4 sm:grid-cols-2" @submit.prevent="updateCredentials">
          <div class="sm:col-span-2">
            <label class="mb-1.5 block text-xs text-slate-600">Nombre de usuario / correo</label>
            <input
              v-model="credentialsForm.username"
              type="text"
              required
              autocomplete="off"
              class="glass-input h-10 w-full rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
            >
          </div>
          <div>
            <label class="mb-1.5 block text-xs text-slate-600">Nueva contraseña (opcional)</label>
            <input
              v-model="credentialsForm.password"
              type="password"
              autocomplete="new-password"
              placeholder="Dejar en blanco para no cambiarla"
              class="glass-input h-10 w-full rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
            >
          </div>
          <div>
            <label class="mb-1.5 block text-xs text-slate-600">Confirmar nueva contraseña</label>
            <input
              v-model="credentialsForm.passwordConfirmation"
              type="password"
              autocomplete="new-password"
              class="glass-input h-10 w-full rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
            >
          </div>
          <div class="sm:col-span-2">
            <label class="mb-1.5 block text-xs text-slate-600">Confirma tu contraseña actual</label>
            <input
              v-model="credentialsForm.currentPassword"
              type="password"
              required
              autocomplete="current-password"
              class="glass-input h-10 w-full rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
            >
          </div>

          <div class="sm:col-span-2 flex flex-wrap items-center justify-between gap-3">
            <p v-if="credentialsUpdatedAtLabel" class="text-xs text-slate-500">
              Última actualización: {{ credentialsUpdatedAtLabel }}
            </p>
            <p v-else class="text-xs text-slate-500">Sin cambios registrados todavía.</p>
            <button
              type="submit"
              class="glass-btn h-10 whitespace-nowrap rounded-xl border-cyan-300/40 bg-cyan-400 px-5 text-sm font-semibold text-slate-50 hover:bg-cyan-300 disabled:cursor-not-allowed disabled:opacity-60"
              :disabled="isSavingCredentials"
            >
              {{ isSavingCredentials ? 'Guardando...' : 'Guardar cambios' }}
            </button>
          </div>
          <p v-if="credentialsError" class="sm:col-span-2 text-xs text-rose-600">{{ credentialsError }}</p>
        </form>
      </div>

      <div class="glass-panel mt-6 rounded-2xl bg-white/70 p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-700" aria-hidden="true">
              <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
            </span>
            <div>
              <h2 class="text-lg font-semibold text-slate-900">Verificación en dos pasos (2FA)</h2>
              <p class="mt-1 text-sm text-slate-600">
                Pide un código de tu app autenticadora (Google Authenticator, Authy, etc.) además de tu
                contraseña al iniciar sesión.
              </p>
            </div>
          </div>
          <span
            class="glass-badge rounded-full px-3 py-1 text-xs font-semibold"
            :class="isEnabled ? 'bg-emerald-500/15 text-emerald-700' : 'bg-slate-500/15 text-slate-600'"
          >
            {{ isEnabled ? 'Activada' : 'Desactivada' }}
          </span>
        </div>

        <!-- Estado: desactivada, sin flujo de activación iniciado -->
        <div v-if="!isEnabled && !setup" class="mt-5">
          <button
            type="button"
            class="glass-btn h-11 rounded-xl border-cyan-400/50 px-4 text-sm font-semibold text-cyan-700 hover:border-cyan-300"
            :disabled="isBusy"
            @click="startEnable"
          >
            Activar verificación en dos pasos
          </button>
        </div>

        <!-- Flujo: escanear QR + confirmar primer código -->
        <div v-else-if="!isEnabled && setup" class="mt-5 grid gap-5 sm:grid-cols-[auto_1fr]">
          <div class="flex items-center justify-center rounded-xl border border-sky-200 bg-white p-3" v-html="setup.qr_code_svg" />
          <div>
            <p class="text-sm text-slate-700">
              1. Escanea este código con tu app autenticadora.<br>
              2. O ingresa esta clave manualmente:
              <code class="mt-1 block rounded-lg bg-sky-50 px-2 py-1 text-xs text-slate-800">{{ setup.secret }}</code>
            </p>
            <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="confirmEnable">
              <div>
                <label class="mb-1.5 block text-xs text-slate-600">Código de 6 dígitos</label>
                <input
                  v-model="confirmCode"
                  type="text"
                  inputmode="numeric"
                  required
                  placeholder="123456"
                  class="glass-input h-10 w-36 rounded-xl px-3 text-center tracking-[0.2em] text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
                >
              </div>
              <button
                type="submit"
                class="glass-btn h-10 whitespace-nowrap rounded-xl border-cyan-300/40 bg-cyan-400 px-4 text-sm font-semibold text-slate-50 hover:bg-cyan-300"
                :disabled="isBusy"
              >
                {{ isBusy ? 'Verificando...' : 'Confirmar y activar' }}
              </button>
              <button
                type="button"
                class="glass-btn h-10 whitespace-nowrap rounded-xl border-sky-400/50 px-4 text-sm text-slate-800 hover:border-sky-600"
                @click="cancelEnable"
              >
                Cancelar
              </button>
            </form>
            <p v-if="formError" class="mt-2 text-xs text-rose-600">{{ formError }}</p>
          </div>
        </div>

        <!-- Estado: activada -->
        <div v-else class="mt-5 space-y-4">
          <p class="text-sm text-slate-700">
            <span v-if="recoveryCodesRemaining !== null">
              Te quedan <strong>{{ recoveryCodesRemaining }}</strong> códigos de recuperación sin usar.
            </span>
          </p>

          <div v-if="newRecoveryCodes" class="glass-panel rounded-xl bg-amber-500/10 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">
              Guarda estos códigos — no se volverán a mostrar
            </p>
            <div class="mt-2 grid grid-cols-2 gap-1.5 font-mono text-sm text-slate-800 sm:grid-cols-4">
              <span v-for="code in newRecoveryCodes" :key="code">{{ code }}</span>
            </div>
          </div>

          <details class="glass-panel rounded-xl bg-sky-50/40 p-3">
            <summary class="cursor-pointer text-sm font-medium text-slate-800">Regenerar códigos de recuperación</summary>
            <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="regenerateRecoveryCodes">
              <div>
                <label class="mb-1.5 block text-xs text-slate-600">Confirma tu contraseña</label>
                <input
                  v-model="regeneratePassword"
                  type="password"
                  required
                  class="glass-input h-10 rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
                >
              </div>
              <button
                type="submit"
                class="glass-btn h-10 whitespace-nowrap rounded-xl border-sky-400/50 px-4 text-sm text-slate-800 hover:border-sky-600"
                :disabled="isBusy"
              >
                Regenerar
              </button>
            </form>
          </details>

          <details class="glass-panel rounded-xl bg-rose-500/5 p-3">
            <summary class="cursor-pointer text-sm font-medium text-rose-700">Desactivar verificación en dos pasos</summary>
            <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="disable">
              <div>
                <label class="mb-1.5 block text-xs text-slate-600">Confirma tu contraseña</label>
                <input
                  v-model="disablePassword"
                  type="password"
                  required
                  class="glass-input h-10 rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
                >
              </div>
              <button
                type="submit"
                class="glass-btn h-10 whitespace-nowrap rounded-xl border-rose-500/50 px-4 text-sm font-semibold text-rose-700 hover:border-rose-400"
                :disabled="isBusy"
              >
                Desactivar
              </button>
            </form>
          </details>
        </div>
      </div>

      <div v-if="isAdmin" class="glass-panel mt-6 rounded-2xl bg-white/70 p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500/15 text-amber-700" aria-hidden="true">
              <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" /></svg>
            </span>
            <div>
              <h2 class="text-lg font-semibold text-slate-900">Correo de incidentes críticos</h2>
              <p class="mt-1 text-sm text-slate-600">
                Como administrador, Sentinel te avisa por correo cuando un sitio prioritario cae o se
                degrada de forma crítica. Puedes desactivar esto para tu cuenta sin afectar a los demás
                administradores.
              </p>
            </div>
          </div>
          <button
            type="button"
            class="glass-btn h-10 whitespace-nowrap rounded-xl px-4 text-sm font-semibold"
            :class="notifyOnCriticalIncidents
              ? 'border-emerald-500/50 text-emerald-700 hover:border-emerald-400'
              : 'border-slate-400/50 text-slate-700 hover:border-slate-600'"
            :disabled="isUpdatingNotificationPreference"
            @click="toggleNotificationPreference"
          >
            {{ notifyOnCriticalIncidents ? 'Recibiendo — desactivar' : 'Desactivado — activar' }}
          </button>
        </div>
      </div>

      <AppFooter />
    </section>
  </main>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import TopNav from '@/components/layout/TopNav.vue'
import AppFooter from '@/components/layout/AppFooter.vue'
import Breadcrumbs from '@/components/layout/Breadcrumbs.vue'

const props = defineProps<{
  twoFactorEnabled: boolean
  recoveryCodesRemaining: number | null
  isAdmin: boolean
  notifyOnCriticalIncidents: boolean
  username: string
  credentialsUpdatedAt: string | null
}>()

const isEnabled = ref(props.twoFactorEnabled)
const recoveryCodesRemaining = ref(props.recoveryCodesRemaining)
const isBusy = ref(false)
const statusMessage = ref('')
const formError = ref('')
const notifyOnCriticalIncidents = ref(props.notifyOnCriticalIncidents)
const isUpdatingNotificationPreference = ref(false)

// Formato DD/MM/YYYY HH:MM, igual que en "Agregar sitio".
const formatDateDDMMYYYY = (value: string | null): string | null => {
  if (!value) {
    return null
  }

  const parsed = new Date(value)

  if (Number.isNaN(parsed.getTime())) {
    return null
  }

  const pad = (num: number) => String(num).padStart(2, '0')

  return `${pad(parsed.getDate())}/${pad(parsed.getMonth() + 1)}/${parsed.getFullYear()} ${pad(parsed.getHours())}:${pad(parsed.getMinutes())}`
}

const credentialsUpdatedAtLabel = ref(formatDateDDMMYYYY(props.credentialsUpdatedAt))
const isSavingCredentials = ref(false)
const credentialsError = ref('')
const credentialsForm = ref({
  username: props.username,
  password: '',
  passwordConfirmation: '',
  currentPassword: '',
})

const setup = ref<{ secret: string; qr_code_svg: string } | null>(null)
const confirmCode = ref('')
const newRecoveryCodes = ref<string[] | null>(null)
const regeneratePassword = ref('')
const disablePassword = ref('')

const getCsrfToken = () => {
  const meta = document.querySelector('meta[name="csrf-token"]')
  return meta instanceof HTMLMetaElement ? meta.content || '' : ''
}

async function postJson(url: string, body: Record<string, unknown> = {}, method = 'POST') {
  const response = await fetch(url, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': getCsrfToken(),
    },
    credentials: 'same-origin',
    body: JSON.stringify(body),
  })

  const raw = await response.text()
  const data = raw.trim() !== '' ? JSON.parse(raw) : {}

  if (!response.ok) {
    const message = data?.errors
      ? Object.values(data.errors as Record<string, string[]>).flat().join(' ')
      : data?.message ?? 'Ocurrió un error.'
    throw new Error(message)
  }

  return data
}

async function startEnable() {
  isBusy.value = true
  formError.value = ''

  try {
    setup.value = await postJson('/account/two-factor/enable')
  } catch (error) {
    formError.value = error instanceof Error ? error.message : 'No se pudo iniciar la activación.'
  } finally {
    isBusy.value = false
  }
}

function cancelEnable() {
  setup.value = null
  confirmCode.value = ''
  formError.value = ''
}

async function confirmEnable() {
  isBusy.value = true
  formError.value = ''

  try {
    const data = await postJson('/account/two-factor/confirm', { code: confirmCode.value })
    isEnabled.value = true
    newRecoveryCodes.value = data.recovery_codes
    recoveryCodesRemaining.value = data.recovery_codes.length
    setup.value = null
    confirmCode.value = ''
    statusMessage.value = 'Verificación en dos pasos activada correctamente.'
  } catch (error) {
    formError.value = error instanceof Error ? error.message : 'Código inválido.'
  } finally {
    isBusy.value = false
  }
}

async function regenerateRecoveryCodes() {
  isBusy.value = true

  try {
    const data = await postJson('/account/two-factor/recovery-codes', { password: regeneratePassword.value })
    newRecoveryCodes.value = data.recovery_codes
    recoveryCodesRemaining.value = data.recovery_codes.length
    regeneratePassword.value = ''
    statusMessage.value = 'Códigos de recuperación regenerados.'
  } catch (error) {
    statusMessage.value = error instanceof Error ? error.message : 'No se pudo regenerar los códigos.'
  } finally {
    isBusy.value = false
  }
}

async function disable() {
  isBusy.value = true

  try {
    await postJson('/account/two-factor', { password: disablePassword.value }, 'DELETE')
    isEnabled.value = false
    recoveryCodesRemaining.value = null
    newRecoveryCodes.value = null
    disablePassword.value = ''
    statusMessage.value = 'Verificación en dos pasos desactivada.'
  } catch (error) {
    statusMessage.value = error instanceof Error ? error.message : 'No se pudo desactivar.'
  } finally {
    isBusy.value = false
  }
}

async function updateCredentials() {
  credentialsError.value = ''

  if (credentialsForm.value.password !== '' && credentialsForm.value.password !== credentialsForm.value.passwordConfirmation) {
    credentialsError.value = 'La confirmación de la nueva contraseña no coincide.'

    return
  }

  isSavingCredentials.value = true

  try {
    const data = await postJson(
      '/account/credentials',
      {
        username: credentialsForm.value.username,
        password: credentialsForm.value.password || undefined,
        password_confirmation: credentialsForm.value.password ? credentialsForm.value.passwordConfirmation : undefined,
        current_password: credentialsForm.value.currentPassword,
      },
      'PATCH',
    )

    credentialsForm.value.password = ''
    credentialsForm.value.passwordConfirmation = ''
    credentialsForm.value.currentPassword = ''
    credentialsForm.value.username = data.username ?? credentialsForm.value.username
    credentialsUpdatedAtLabel.value = formatDateDDMMYYYY(data.updated_at ?? null)
    statusMessage.value = data.message ?? 'Datos de acceso actualizados.'
  } catch (error) {
    credentialsError.value = error instanceof Error ? error.message : 'No se pudieron guardar los cambios.'
  } finally {
    isSavingCredentials.value = false
  }
}

async function toggleNotificationPreference() {
  isUpdatingNotificationPreference.value = true

  try {
    await postJson(
      '/account/notification-preference',
      { enabled: !notifyOnCriticalIncidents.value },
      'PATCH',
    )
    notifyOnCriticalIncidents.value = !notifyOnCriticalIncidents.value
    statusMessage.value = notifyOnCriticalIncidents.value
      ? 'Volverás a recibir el correo de incidentes críticos.'
      : 'Ya no recibirás el correo de incidentes críticos.'
  } catch (error) {
    statusMessage.value = error instanceof Error ? error.message : 'No se pudo actualizar la preferencia.'
  } finally {
    isUpdatingNotificationPreference.value = false
  }
}
</script>
