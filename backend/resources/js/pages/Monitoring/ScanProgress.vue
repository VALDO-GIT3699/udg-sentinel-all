<template>
  <main class="min-h-screen bg-sky-50 text-slate-900">
    <section class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
      <section
        class="rounded-3xl border border-cyan-500/40 bg-white/95 p-6 shadow-[0_15px_45px_rgba(8,145,178,0.15)]"
      >
        <p class="text-xs uppercase tracking-[0.22em] text-cyan-600">Escaneo masivo en curso</p>
        <h1 class="mt-2 text-fluid-xl font-semibold text-slate-900">
          Revalidando todos los sitios y tecnologías
        </h1>
        <p class="mt-2 text-sm text-slate-700">
          Durante esta ejecución la navegación queda bloqueada para evitar estados inconsistentes.
        </p>

        <p
          v-if="message"
          class="mt-4 rounded-xl border border-cyan-500/40 bg-cyan-500/10 px-4 py-3 text-sm text-cyan-800"
        >
          {{ message }}
        </p>

        <div class="mt-6 flex flex-wrap items-start justify-between gap-4">
          <div>
            <p class="text-sm text-slate-700">
              Completado {{ completedTasks }} de {{ totalTasks }} tareas · Restantes
              {{ remainingTasks }}
            </p>
            <p class="mt-1 text-xs text-slate-600">Inicio: {{ startedAtLabel }}</p>
          </div>
          <div class="text-right">
            <p class="text-4xl font-semibold text-cyan-700">{{ progressPct.toFixed(1) }}%</p>
            <p class="mt-1 text-xs text-slate-600">Estado: {{ statusLabel }}</p>
            <button
              v-if="canShowCancelButton"
              type="button"
              class="mt-2 rounded-lg border border-rose-600/50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:border-rose-400 disabled:cursor-not-allowed disabled:opacity-60"
              :disabled="isCancelling"
              @click="cancelScan"
            >
              {{ isCancelling ? 'Cancelando...' : 'Cancelar escaneo' }}
            </button>
          </div>
        </div>

        <div class="mt-4 h-3 w-full overflow-hidden rounded-full bg-sky-200">
          <div
            class="h-full rounded-full bg-gradient-to-r from-cyan-400 via-emerald-300 to-cyan-200 transition-all duration-300"
            :style="{ width: `${progressPct}%` }"
          />
        </div>

        <p class="mt-6 text-xs text-slate-600">
          Cuando termine se te redirigirá automáticamente al dashboard con los datos actualizados.
        </p>
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
    status: 'running' | 'completed_ok' | 'completed_with_errors' | 'incomplete' | 'cancelled'
    started_at: string
    last_progress_at?: string | null
    completed_at?: string | null
    total_sites: number
    total_tasks: number
    completed_tasks: number
    failed_tasks?: number
    remaining_tasks: number
    progress_pct: number
    stages: Record<string, MassScanStage>
  }

  type Props = {
    runId: string
    progress?: MassScanProgressPayload | null
    dashboardUrl: string
    canCancelScan?: boolean
  }

  const props = defineProps<Props>()

  const progress = ref<MassScanProgressPayload | null>(props.progress ?? null)
  const message = ref('')
  const isCancelling = ref(false)
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
    if (status === 'cancelled') return 'Cancelado'
    return 'Desconocido'
  })

  const canShowCancelButton = computed(
    () => Boolean(props.canCancelScan) && progress.value?.status === 'running',
  )

  const stageRows = computed(() => {
    const stages = progress.value?.stages
    if (!stages) {
      return [] as Array<{
        key: string
        label: string
        completed: number
        total: number
        remaining: number
        progressPct: number
      }>
    }

    const labels: Record<string, string> = {
      inspection: 'Inspección consolidada',
      uptime: 'Disponibilidad',
      ssl: 'Certificado SSL',
      headers: 'Cabeceras de seguridad',
      technology: 'Tecnologías detectadas',
    }

    return Object.entries(stages).map(([key, stage]) => ({
      key,
      label: labels[key] ?? key,
      completed: Number(stage?.completed ?? 0),
      total: Number(stage?.total ?? 0),
      remaining: Number(stage?.remaining ?? 0),
      progressPct: Number(stage?.progress_pct ?? 0),
    }))
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

      const payload = (await response.json()) as {
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
        message.value =
          payload.progress.status === 'completed_ok'
            ? 'Escaneo finalizado correctamente. Redirigiendo al dashboard...'
            : payload.progress.status === 'cancelled'
              ? 'Escaneo cancelado. Los sitios sin re-inspeccionar conservan su última tecnología detectada, marcados como interrumpidos. Redirigiendo al dashboard...'
              : 'Escaneo finalizado. Redirigiendo al dashboard...'
        scheduleRedirectToDashboard()
      }
    } catch {
      message.value = 'Error de red consultando avance del escaneo. Reintentando...'
    }
  }

  const getCsrfToken = () => {
    const meta = document.querySelector('meta[name="csrf-token"]')
    return meta instanceof HTMLMetaElement ? meta.content || '' : ''
  }

  const cancelScan = async () => {
    if (isCancelling.value) {
      return
    }

    const confirmed = window.confirm(
      'Se cancelará el escaneo masivo en curso. Los sitios que aún no fueron re-inspeccionados conservarán su última tecnología detectada, marcados como "Interrumpido". ¿Continuar?',
    )

    if (!confirmed) {
      return
    }

    isCancelling.value = true

    try {
      const response = await fetch(`/monitoring/scans/${props.runId}/cancel`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({}),
      })

      if (response.status === 419) {
        message.value = 'La sesión expiró. Recarga la página para continuar.'
        return
      }

      const payload = (await response.json()) as {
        cancelled?: boolean
        message?: string
        progress?: MassScanProgressPayload | null
      }

      if (payload.progress) {
        progress.value = payload.progress
      }

      if (payload.cancelled) {
        message.value =
          payload.message ??
          'Escaneo cancelado. Los sitios sin re-inspeccionar conservan su última tecnología detectada, marcados como interrumpidos.'
        scheduleRedirectToDashboard()
      } else if (payload.message) {
        message.value = payload.message
      }
    } catch {
      message.value = 'No se pudo cancelar el escaneo por un error de red.'
    } finally {
      isCancelling.value = false
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
