<template>
  <div
    v-if="visible"
    class="glass-panel fixed inset-x-4 bottom-4 z-40 mx-auto flex max-w-2xl flex-col gap-3 rounded-2xl bg-white/90 p-4 text-sm text-slate-700 shadow-lg sm:flex-row sm:items-center sm:justify-between"
    role="status"
  >
    <p>
      Este sitio usa únicamente una cookie técnica de sesión, necesaria para mantener tu inicio de sesión. No
      usamos cookies de rastreo ni publicidad.
      <a href="/legal/privacidad" class="text-cyan-600 hover:text-cyan-700">Ver aviso de privacidad</a>.
    </p>
    <button
      type="button"
      class="glass-btn h-9 shrink-0 whitespace-nowrap rounded-lg border-cyan-400/50 px-4 text-xs font-semibold text-cyan-700 hover:border-cyan-300"
      @click="dismiss"
    >
      Entendido
    </button>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'

const STORAGE_KEY = 'udg-sentinel:cookie-notice-dismissed'
const visible = ref(false)

onMounted(() => {
  try {
    visible.value = window.localStorage.getItem(STORAGE_KEY) !== '1'
  } catch {
    visible.value = true
  }
})

function dismiss() {
  visible.value = false
  try {
    window.localStorage.setItem(STORAGE_KEY, '1')
  } catch {
    // localStorage no disponible (modo privado, etc.) — el aviso reaparecera
    // en la siguiente carga, lo cual es un fallback aceptable.
  }
}
</script>
