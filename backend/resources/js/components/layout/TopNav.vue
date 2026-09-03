<template>
  <nav
    ref="navEl"
    class="glass-panel sticky top-0 z-30 mb-6 flex flex-wrap items-center gap-1 rounded-2xl bg-white/50 p-1.5 backdrop-blur-xl relative"
    aria-label="Navegación principal"
  >
    <div
      v-if="indicator.width > 0"
      class="pointer-events-none absolute rounded-xl bg-cyan-500/15 ring-1 ring-cyan-400/50 transition-all duration-300 ease-out"
      :style="{ left: indicator.left + 'px', width: indicator.width + 'px', top: '6px', bottom: '6px' }"
      aria-hidden="true"
    />
    <Link
      v-for="item in visibleItems"
      :key="item.key"
      :ref="(el) => setTabRef(item.key, el)"
      :href="item.href"
      class="glass-btn relative z-10 rounded-xl border-transparent px-3.5 py-2 text-sm font-medium hover:-translate-y-0.5"
      :class="item.key === current
        ? 'text-cyan-800'
        : 'text-slate-700 hover:border-sky-300/50 hover:bg-white/60 hover:text-slate-900'"
    >
      {{ item.label }}
    </Link>
  </nav>
</template>

<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'

const props = defineProps<{
  current: 'dashboard' | 'analytics' | 'assets' | 'reports' | 'admin' | 'audit' | 'account'
}>()

type SharedAuth = {
  auth?: {
    permissions?: string[]
  }
}

const page = usePage<SharedAuth>()
const permissions = computed(() => page.props.auth?.permissions ?? [])
const has = (permission: string) => permissions.value.includes(permission)

const items = computed(() => [
  { key: 'dashboard', label: 'Dashboard', href: '/monitoring/dashboard', visible: true },
  { key: 'analytics', label: 'Analíticas', href: '/analytics/overview', visible: true },
  { key: 'assets', label: 'Catálogo de activos', href: '/monitoring/assets/intelligence', visible: true },
  { key: 'reports', label: 'Reportes', href: '/dashboards', visible: true },
  { key: 'admin', label: 'Administración', href: '/monitoring/admin/users', visible: has('monitoring.manage_users') },
  { key: 'audit', label: 'Auditoría', href: '/monitoring/audit', visible: has('monitoring.view_audit_log') },
  { key: 'account', label: 'Mi cuenta', href: '/account/security', visible: true },
])

const visibleItems = computed(() => items.value.filter((item) => item.visible))

const navEl = ref<HTMLElement | null>(null)
const tabEls = new Map<string, HTMLElement>()
const indicator = reactive({ left: 0, width: 0 })

function setTabRef(key: string, el: unknown) {
  const componentOrEl = el as { $el?: HTMLElement } | HTMLElement | null
  if (!componentOrEl) {
    tabEls.delete(key)
    return
  }
  const domEl = '$el' in componentOrEl && componentOrEl.$el ? componentOrEl.$el : (componentOrEl as HTMLElement)
  tabEls.set(key, domEl)
}

function updateIndicator() {
  const activeEl = tabEls.get(props.current)
  const navRect = navEl.value?.getBoundingClientRect()
  if (!activeEl || !navRect) {
    indicator.width = 0
    return
  }
  const rect = activeEl.getBoundingClientRect()
  indicator.left = rect.left - navRect.left
  indicator.width = rect.width
}

let resizeHandler: (() => void) | null = null

onMounted(() => {
  nextTick(updateIndicator)
  resizeHandler = () => updateIndicator()
  window.addEventListener('resize', resizeHandler)
  router.on('finish', () => nextTick(updateIndicator))
})

onUnmounted(() => {
  if (resizeHandler) window.removeEventListener('resize', resizeHandler)
})

watch(() => props.current, () => nextTick(updateIndicator))
watch(visibleItems, () => nextTick(updateIndicator))
</script>
