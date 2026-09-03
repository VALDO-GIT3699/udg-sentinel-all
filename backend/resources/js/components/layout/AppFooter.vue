<template>
  <footer class="mt-10 border-t border-slate-900/10 pt-5 pb-8 text-xs text-slate-500">
    <div class="flex flex-col items-center gap-2 sm:flex-row sm:justify-between">
      <p>© {{ year }} Universidad de Guadalajara — Coordinación General de Tecnologías Administrativas</p>
      <nav class="flex items-center gap-4" aria-label="Enlaces legales">
        <a href="/legal/privacidad" class="hover:text-slate-700">Aviso de privacidad</a>
        <a href="/legal/terminos" class="hover:text-slate-700">Términos y cookies</a>
      </nav>
    </div>
  </footer>
</template>

<script setup lang="ts">
const year = new Date().getFullYear()
</script>
