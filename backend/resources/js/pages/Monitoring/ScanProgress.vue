<template>
  <main class="min-h-screen bg-slate-950 text-slate-100">
    <section class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
      <section class="rounded-3xl border border-cyan-500/40 bg-slate-900/95 p-6 shadow-[0_15px_45px_rgba(8,145,178,0.15)]">
        <p class="text-xs uppercase tracking-[0.22em] text-cyan-300">Escaneo masivo en curso</p>
        <h1 class="mt-2 text-2xl font-semibold text-white sm:text-3xl">Revalidando todos los sitios y tecnologías</h1>
        <p class="mt-2 text-sm text-slate-300">Durante esta ejecución la navegación queda bloqueada para evitar estados inconsistentes.</p>

        <p v-if="message" class="mt-4 rounded-xl border border-cyan-500/40 bg-cyan-500/10 px-4 py-3 text-sm text-cyan-100">
          {{ message }}
        </p>

        <div class="mt-6 flex flex-wrap items-start justify-between gap-4">
          <div>
            <p class="text-sm text-slate-300">Completado {{ completedTasks }} de {{ totalTasks }} tareas · Restantes {{ remainingTasks }}</p>
            <p class="mt-1 text-xs text-slate-400">Inicio: {{ startedAtLabel }}</p>
          </div>
          <div class="text-right">
            <p class="text-4xl font-semibold text-cyan-200">{{ progressPct.toFixed(1) }}%</p>
            <p class="mt-1 text-xs text-slate-400">Estado: {{ statusLabel }}</p>
          </div>
        </div>

        <div class="mt-4 h-3 w-full overflow-hidden rounded-full bg-slate-800">
          <div class="h-full rounded-full bg-gradient-to-r from-cyan-400 via-emerald-300 to-cyan-200 transition-all duration-300" :style="{ width: `${progressPct}%` }" />
        </div>

        <div class="mt-6 grid gap-3 md:grid-cols-2">
          <article v-for="stage in stageRows" :key="stage.key" class="rounded-xl border border-slate-700/80 bg-slate-950/60 p-3">
            <div class="mb-2 flex items-center justify-between text-xs">
              <span class="font-semibold uppercase tracking-[0.16em] text-slate-300">{{ stage.label }}</span>
              <span class="text-slate-400">{{ stage.completed }}/{{ stage.total }} · faltan {{ stage.remaining }}</span>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-slate-800">
              <div class="h-full rounded-full bg-cyan-400 transition-all duration-300" :style="{ width: `${stage.progressPct}%` }" />
            </div>
          </article>
        </div>

        <p class="mt-6 text-xs text-slate-400">Cuando termine se te redirigirá automáticamente al dashboard con los datos actualizados.</p>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'

type MassScanStage = {
  completed: number
  failed?: number
  total: number
  remaining: number
  progress_pct: number
}

type MassScanProgressPayload = {
  run_id: string
  status: 'running' | 'completed_ok' | 'completed_with_errors' | 'incomplete'
  started_at: string
  last_progress_at?: string | null
  completed_at?: string | null
  total_sites: number
  total_tasks: number
  completed_tasks: number
  failed_tasks?: number
  remaining_tasks: number
  progress_pct: number
  stages: Record<'uptime' | 'ssl' | 'headers' | 'technology', MassScanStage>
}

type Props = {
  runId: string
  progress?: MassScanProgressPayload | null
  dashboardUrl: string
}

const props = defineProps<Props>()

const progress = ref<MassScanProgressPayload | null>(props.progress ?? null)
const message = ref('')
let pollingInterval: ReturnType<typeof setInterval> | null = null
let redirectTimeout: ReturnType<typeof setTimeout> | null = null

const totalTasks = computed(() => Number(progress.value?.total_tasks ?? 0))
const completedTasks = computed(() => Number(progress.value?.completed_tasks ?? 0))
const remainingTasks = computed(() => Number(progress.value?.remaining_tasks ?? 0))
const progressPct = computed(() => Number(progress.value?.progress_pct ?? 0))

const startedAtLabel = computed(() => {
  const value = progress.value?.started_at
  if (!value) return 'Sin dato'
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? 'Sin dato' : parsed.toLocaleString('es-MX')
})

const statusLabel = computed(() => {
  const status = progress.value?.status
  if (status === 'running') return 'En ejecución'
  if (status === 'completed_ok') return 'Finalizado OK'
  if (status === 'completed_with_errors') return 'Finalizado con errores'
  if (status === 'incomplete') return 'Incompleto'
  return 'Desconocido'
})

const stageRows = computed(() => {
  const stages = progress.value?.stages
  if (!stages) {
    return [] as Array<{ key: string; label: string; completed: number; total: number; remaining: number; progressPct: number }>
  }

  return [
    {
      key: 'uptime',
      label: 'Disponibilidad',
      completed: Number(stages.uptime?.completed ?? 0),
      total: Number(stages.uptime?.total ?? 0),
      remaining: Number(stages.uptime?.remaining ?? 0),
      progressPct: Number(stages.uptime?.progress_pct ?? 0),
    },
    {
      key: 'ssl',
      label: 'Certificado SSL',
      completed: Number(stages.ssl?.completed ?? 0),
      total: Number(stages.ssl?.total ?? 0),
      remaining: Number(stages.ssl?.remaining ?? 0),
      progressPct: Number(stages.ssl?.progress_pct ?? 0),
    },
    {
      key: 'headers',
      label: 'Cabeceras de seguridad',
      completed: Number(stages.headers?.completed ?? 0),
      total: Number(stages.headers?.total ?? 0),
      remaining: Number(stages.headers?.remaining ?? 0),
      progressPct: Number(stages.headers?.progress_pct ?? 0),
    },
    {
      key: 'technology',
      label: 'Tecnologías detectadas',
      completed: Number(stages.technology?.completed ?? 0),
      total: Number(stages.technology?.total ?? 0),
      remaining: Number(stages.technology?.remaining ?? 0),
      progressPct: Number(stages.technology?.progress_pct ?? 0),
    },
  ]
})

const scheduleRedirectToDashboard = () => {
  if (redirectTimeout) {
    return
  }

  redirectTimeout = setTimeout(() => {
    router.visit(props.dashboardUrl, {
      replace: true,
      preserveScroll: false,
    })
  }, 1800)
}

const fetchProgress = async () => {
  try {
    const response = await fetch(`/monitoring/scans/${props.runId}/progress`, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })

    if (!response.ok) {
      message.value = 'No se pudo consultar el estado del escaneo. Reintentando...'
      return
    }

    const payload = await response.json() as {
      exists?: boolean
      active?: boolean
      progress?: MassScanProgressPayload | null
    }

    if (!payload.exists || !payload.progress) {
      message.value = 'No se encontró información del escaneo. Regresando al dashboard...'
      scheduleRedirectToDashboard()
      return
    }

    progress.value = payload.progress

    if (payload.progress.status !== 'running') {
      message.value = payload.progress.status === 'completed_ok'
        ? 'Escaneo finalizado correctamente. Redirigiendo al dashboard...'
        : 'Escaneo finalizado. Redirigiendo al dashboard...'
      scheduleRedirectToDashboard()
    }
  } catch {
    message.value = 'Error de red consultando avance del escaneo. Reintentando...'
  }
}

onMounted(() => {
  pollingInterval = setInterval(() => {
    void fetchProgress()
  }, 2000)

  void fetchProgress()
})

onBeforeUnmount(() => {
  if (pollingInterval) {
    clearInterval(pollingInterval)
    pollingInterval = null
  }

  if (redirectTimeout) {
    clearTimeout(redirectTimeout)
    redirectTimeout = null
  }
})
</script>
