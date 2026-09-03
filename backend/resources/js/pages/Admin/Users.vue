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
      <Breadcrumbs :items="[{ label: 'Administración' }]" />

      <header class="mb-8 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.22em] text-cyan-600">UDG Sentinel</p>
          <h1 class="mt-2 text-fluid-2xl font-semibold text-slate-900">Administración de usuarios</h1>
          <p class="mt-2 max-w-2xl text-sm text-slate-700">
            Crea cuentas, asigna un rol de partida y personaliza permisos individuales por usuario.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2.5">
          <a
            href="/monitoring/trash"
            class="glass-btn inline-flex h-11 items-center whitespace-nowrap rounded-xl border-sky-400/50 px-4 text-sm text-slate-800 hover:border-sky-600"
          >
            Papelera
          </a>
          <a
            v-if="canManageNotificationSettings"
            href="/monitoring/admin/notifications"
            class="glass-btn inline-flex h-11 items-center whitespace-nowrap rounded-xl border-sky-400/50 px-4 text-sm text-slate-800 hover:border-sky-600"
          >
            Notificaciones
          </a>
          <button
            type="button"
            class="glass-btn h-11 whitespace-nowrap rounded-xl border-cyan-400/50 px-4 text-sm font-semibold text-cyan-700 hover:border-cyan-300"
            @click="openCreateModal"
          >
            Nuevo usuario
          </button>
        </div>
      </header>

      <p v-if="actionMessage" class="glass-panel mb-4 rounded-xl bg-white/50 px-4 py-2.5 text-sm text-slate-800">
        {{ actionMessage }}
      </p>

      <div class="glass-panel overflow-hidden rounded-2xl bg-white/40">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-sky-200 text-left text-sm">
            <thead>
              <tr class="text-slate-600">
                <th class="px-4 py-4 font-medium">Nombre</th>
                <th class="px-4 py-4 font-medium">Usuario / correo</th>
                <th class="px-4 py-4 font-medium">Departamento</th>
                <th class="px-4 py-4 font-medium">Rol</th>
                <th class="px-4 py-4 font-medium">Estatus</th>
                <th class="px-4 py-4 font-medium">Último acceso</th>
                <th class="px-4 py-4 font-medium">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-sky-200/60">
              <tr v-for="user in localUsers" :key="user.id" class="hover:bg-white/40">
                <td class="px-4 py-4 font-medium text-slate-900">{{ user.name }}</td>
                <td class="px-4 py-4 text-slate-700">{{ user.email }}</td>
                <td class="px-4 py-4 text-slate-600">{{ user.department || '-' }}</td>
                <td class="px-4 py-4">
                  <span class="glass-badge rounded-full px-2.5 py-1 text-[11px] font-semibold text-slate-800">
                    {{ roleLabel(user.role) }}
                  </span>
                </td>
                <td class="px-4 py-4">
                  <span
                    class="glass-badge rounded-full px-2.5 py-1 text-[11px] font-semibold"
                    :class="user.is_active ? 'text-emerald-600' : 'text-rose-600'"
                  >
                    {{ user.is_active ? 'Activo' : 'Inactivo' }}
                  </span>
                </td>
                <td class="px-4 py-4 text-slate-600">{{ formatDate(user.last_login_at) }}</td>
                <td class="px-4 py-4">
                  <div class="flex flex-wrap gap-2">
                    <button
                      type="button"
                      class="rounded-lg border border-cyan-600/60 px-3 py-1.5 text-xs font-semibold text-cyan-700 hover:border-cyan-400"
                      @click="openEditModal(user)"
                    >
                      Editar
                    </button>
                    <button
                      type="button"
                      class="rounded-lg border border-rose-600/60 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:border-rose-400"
                      :disabled="user.id === currentUserId"
                      :class="{ 'cursor-not-allowed opacity-40': user.id === currentUserId }"
                      @click="openDeleteModal(user)"
                    >
                      Eliminar
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="localUsers.length === 0">
                <td colspan="7" class="px-4 py-12 text-center text-sm text-slate-600">
                  No hay usuarios registrados todavía.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <AppFooter />
    </section>

    <CookieNotice />

    <!-- Modal: crear / editar usuario -->
    <section
      v-if="isFormModalOpen"
      class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-sky-50/60 px-4 py-8 backdrop-blur-sm"
      @click.self="closeFormModal"
    >
      <div class="glass-panel w-full max-w-lg rounded-2xl bg-white/70 p-5">
        <header class="mb-4">
          <h3 class="text-lg font-semibold text-slate-900">
            {{ editingUser ? 'Editar usuario' : 'Nuevo usuario' }}
          </h3>
          <p class="mt-1 text-xs text-slate-600">
            El rol define un preset de permisos; puedes personalizarlos abajo sin cambiar el rol.
          </p>
        </header>

        <form class="space-y-3" @submit.prevent="submitForm">
          <div>
            <label class="mb-1.5 block text-xs text-slate-600">Nombre</label>
            <input
              v-model="form.name"
              type="text"
              required
              class="glass-input h-10 w-full rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
            />
          </div>

          <div>
            <label class="mb-1.5 block text-xs text-slate-600">Correo / usuario</label>
            <input
              v-model="form.email"
              type="text"
              required
              autocomplete="off"
              class="glass-input h-10 w-full rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
            />
          </div>

          <div>
            <label class="mb-1.5 block text-xs text-slate-600">
              {{ editingUser ? 'Nueva contraseña (dejar en blanco para no cambiarla)' : 'Contraseña' }}
            </label>
            <div class="relative">
              <input
                v-model="form.password"
                :type="showPassword ? 'text' : 'password'"
                :required="!editingUser"
                autocomplete="new-password"
                class="glass-input h-10 w-full rounded-xl px-3 pr-11 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
              />
              <button
                type="button"
                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-600 hover:text-slate-800"
                @click="showPassword = !showPassword"
              >
                {{ showPassword ? 'Ocultar' : 'Ver' }}
              </button>
            </div>
            <p class="mt-1 text-[11px] text-slate-500">
              Mínimo 10 caracteres, con mayúsculas, minúsculas, números y símbolos.
            </p>
          </div>

          <div>
            <label class="mb-1.5 block text-xs text-slate-600">Departamento</label>
            <input
              v-model="form.department"
              type="text"
              class="glass-input h-10 w-full rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
            />
          </div>

          <div>
            <label class="mb-1.5 block text-xs text-slate-600">Rol</label>
            <select
              v-model="form.role"
              class="glass-input h-10 w-full rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
              @change="applyRolePreset"
            >
              <option v-for="(label, slug) in props.roles" :key="slug" :value="slug">{{ label }}</option>
            </select>
          </div>

          <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600">
            <input
              v-model="form.is_active"
              type="checkbox"
              class="h-4 w-4 rounded border-slate-300 bg-white text-cyan-600 focus:ring-cyan-400/40"
            />
            Cuenta activa
          </label>

          <details class="glass-panel rounded-xl bg-sky-50/40 p-3">
            <summary class="cursor-pointer text-sm font-medium text-slate-800">Personalizar permisos</summary>
            <div class="mt-3 grid gap-2 sm:grid-cols-2">
              <label
                v-for="(label, slug) in props.permissions"
                :key="slug"
                class="flex cursor-pointer items-start gap-2 text-xs text-slate-700"
              >
                <input
                  type="checkbox"
                  :value="slug"
                  v-model="form.permissions"
                  class="mt-0.5 h-4 w-4 rounded border-slate-300 bg-white text-cyan-600 focus:ring-cyan-400/40"
                />
                {{ label }}
              </label>
            </div>
          </details>

          <p v-if="formMessage" class="glass-panel rounded-lg bg-sky-50/50 px-3 py-2 text-xs text-slate-800">
            {{ formMessage }}
          </p>

          <div class="flex justify-end gap-2 pt-2">
            <button
              type="button"
              class="glass-btn h-10 whitespace-nowrap rounded-xl border-sky-400/50 px-4 text-xs text-slate-800 hover:border-sky-600"
              :disabled="isSaving"
              @click="closeFormModal"
            >
              Cancelar
            </button>
            <button
              type="submit"
              class="glass-btn h-10 whitespace-nowrap rounded-xl border-cyan-400/50 px-4 text-xs font-semibold text-cyan-700 hover:border-cyan-300"
              :disabled="isSaving"
            >
              {{ isSaving ? 'Guardando...' : 'Guardar' }}
            </button>
          </div>
        </form>
      </div>
    </section>

    <!-- Modal: confirmar eliminación -->
    <section
      v-if="isDeleteModalOpen"
      class="fixed inset-0 z-50 flex items-center justify-center bg-sky-50/60 px-4 backdrop-blur-sm"
      @click.self="closeDeleteModal"
    >
      <div class="glass-panel w-full max-w-md rounded-2xl bg-white/70 p-5">
        <header class="mb-4">
          <h3 class="text-lg font-semibold text-slate-900">Eliminar usuario</h3>
          <p class="mt-1 text-xs text-slate-600">
            Esta a punto de eliminar a
            <span class="text-rose-600">{{ deleteTarget?.name }}</span>. La cuenta se puede restaurar después solo
            por un administrador con acceso a la base de datos.
          </p>
        </header>

        <p v-if="deleteMessage" class="glass-panel mb-3 rounded-lg bg-sky-50/50 px-3 py-2 text-xs text-slate-800">
          {{ deleteMessage }}
        </p>

        <div class="flex justify-end gap-2">
          <button
            type="button"
            class="glass-btn h-10 whitespace-nowrap rounded-xl border-sky-400/50 px-4 text-xs text-slate-800 hover:border-sky-600"
            :disabled="isDeleting"
            @click="closeDeleteModal"
          >
            Cancelar
          </button>
          <button
            type="button"
            class="glass-btn h-10 whitespace-nowrap rounded-xl border-rose-400/50 px-4 text-xs font-semibold text-rose-700 hover:border-rose-300"
            :disabled="isDeleting"
            @click="confirmDelete"
          >
            {{ isDeleting ? 'Eliminando...' : 'Eliminar' }}
          </button>
        </div>
      </div>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import TopNav from '@/components/layout/TopNav.vue'
import AppFooter from '@/components/layout/AppFooter.vue'
import CookieNotice from '@/components/ui/CookieNotice.vue'
import Breadcrumbs from '@/components/layout/Breadcrumbs.vue'

type AdminUser = {
  id: number
  name: string
  email: string
  department: string | null
  is_active: boolean
  role: string | null
  permissions: string[]
  last_login_at: string | null
  created_at: string | null
}

const props = defineProps<{
  users: AdminUser[]
  roles: Record<string, string>
  permissions: Record<string, string>
  rolePermissionPresets: Record<string, string[]>
  defaultRole: string
}>()

const localUsers = ref<AdminUser[]>([...props.users])
const actionMessage = ref('')

const currentUserId = computed(
  () => (usePage().props as { auth?: { user?: { id?: number } } }).auth?.user?.id ?? null,
)

const canManageNotificationSettings = computed(() => {
  const permissions = (usePage().props as { auth?: { permissions?: string[] } }).auth?.permissions ?? []
  return permissions.includes('monitoring.manage_settings')
})

const roleLabel = (role: string | null) => (role ? props.roles[role] ?? role : 'Sin rol')

const formatDate = (value: string | null) => {
  if (!value) {
    return 'Nunca'
  }
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? 'Nunca' : parsed.toLocaleString('es-MX')
}

const getCsrfToken = () => {
  const meta = document.querySelector('meta[name="csrf-token"]')
  return meta instanceof HTMLMetaElement ? meta.content || '' : ''
}

// ── Modal crear/editar ──────────────────────────────────────
const isFormModalOpen = ref(false)
const editingUser = ref<AdminUser | null>(null)
const isSaving = ref(false)
const formMessage = ref('')
const showPassword = ref(false)

// El rol de partida al crear un usuario nunca debe ser Admin por accidente:
// viene explicito del backend (Viewer) en vez de adivinar la primera llave
// del objeto de roles, que dependia del orden de declaracion de la matriz.
const defaultRole = props.defaultRole

const form = reactive({
  name: '',
  email: '',
  password: '',
  department: '',
  is_active: true,
  role: defaultRole,
  permissions: [] as string[],
})

const applyRolePreset = () => {
  form.permissions = [...(props.rolePermissionPresets[form.role] ?? [])]
}

const openCreateModal = () => {
  editingUser.value = null
  form.name = ''
  form.email = ''
  form.password = ''
  form.department = ''
  form.is_active = true
  form.role = defaultRole
  form.permissions = [...(props.rolePermissionPresets[defaultRole] ?? [])]
  formMessage.value = ''
  showPassword.value = false
  isFormModalOpen.value = true
}

const openEditModal = (user: AdminUser) => {
  editingUser.value = user
  form.name = user.name
  form.email = user.email
  form.password = ''
  form.department = user.department ?? ''
  form.is_active = user.is_active
  form.role = user.role ?? defaultRole
  form.permissions = [...user.permissions]
  formMessage.value = ''
  showPassword.value = false
  isFormModalOpen.value = true
}

const closeFormModal = () => {
  isFormModalOpen.value = false
  editingUser.value = null
  formMessage.value = ''
}

const submitForm = async () => {
  if (isSaving.value) {
    return
  }

  isSaving.value = true
  formMessage.value = editingUser.value ? 'Guardando cambios...' : 'Creando usuario...'

  const payload: Record<string, unknown> = {
    name: form.name.trim(),
    email: form.email.trim(),
    department: form.department.trim() || null,
    is_active: form.is_active,
    role: form.role,
    permissions: form.permissions,
  }

  if (form.password.trim() !== '') {
    payload.password = form.password
  }

  const url = editingUser.value ? `/monitoring/admin/users/${editingUser.value.id}` : '/monitoring/admin/users'
  const method = editingUser.value ? 'PATCH' : 'POST'

  try {
    const response = await fetch(url, {
      method,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    })

    // fetch() sigue redirecciones por defecto: si la sesion se cerro a medio
    // vuelo (p. ej. otro admin te desactivo), el servidor responde 302 a
    // /login y aqui llega un 200 con el HTML del login, no un error real.
    if (response.redirected) {
      formMessage.value = 'Tu sesión ya no es válida. Recarga la página e inicia sesión de nuevo.'
      return
    }

    let body: { message?: string; errors?: Record<string, string[]> } = {}
    const rawBody = await response.text()
    if (rawBody.trim() !== '') {
      try {
        body = JSON.parse(rawBody) as typeof body
      } catch {
        body = {}
      }
    }

    if (!response.ok) {
      if (response.status === 422 && body.errors) {
        formMessage.value = Object.values(body.errors).flat().join(' ')
        return
      }

      formMessage.value = body.message ?? 'No fue posible guardar el usuario.'
      return
    }

    actionMessage.value = body.message ?? 'Usuario guardado correctamente.'
    closeFormModal()
    // Recarga solo la prop "users" via Inertia (sin recargar el documento
    // completo) — la lista se refresca al instante, sin el parpadeo de un
    // reload de pagina completa.
    router.reload({
      only: ['users'],
      onSuccess: () => {
        localUsers.value = [...props.users]
      },
    })
  } catch {
    formMessage.value = 'Error de red al guardar el usuario.'
  } finally {
    isSaving.value = false
  }
}

// ── Modal eliminar ───────────────────────────────────────────
const isDeleteModalOpen = ref(false)
const deleteTarget = ref<AdminUser | null>(null)
const isDeleting = ref(false)
const deleteMessage = ref('')

const openDeleteModal = (user: AdminUser) => {
  if (user.id === currentUserId.value) {
    return
  }
  deleteTarget.value = user
  deleteMessage.value = ''
  isDeleteModalOpen.value = true
}

const closeDeleteModal = () => {
  isDeleteModalOpen.value = false
  deleteTarget.value = null
  deleteMessage.value = ''
}

const confirmDelete = async () => {
  const user = deleteTarget.value
  if (!user || isDeleting.value) {
    return
  }

  isDeleting.value = true
  deleteMessage.value = 'Eliminando...'

  try {
    const response = await fetch(`/monitoring/admin/users/${user.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
    })

    if (response.redirected) {
      deleteMessage.value = 'Tu sesión ya no es válida. Recarga la página e inicia sesión de nuevo.'
      return
    }

    if (!response.ok) {
      let body: { message?: string } = {}
      const rawBody = await response.text()
      if (rawBody.trim() !== '') {
        try {
          body = JSON.parse(rawBody) as typeof body
        } catch {
          body = {}
        }
      }
      deleteMessage.value = body.message ?? 'No fue posible eliminar el usuario.'
      return
    }

    actionMessage.value = 'Usuario eliminado correctamente.'
    closeDeleteModal()
    localUsers.value = localUsers.value.filter((item) => item.id !== user.id)
  } catch {
    deleteMessage.value = 'Error de red al eliminar el usuario.'
  } finally {
    isDeleting.value = false
  }
}
</script>
