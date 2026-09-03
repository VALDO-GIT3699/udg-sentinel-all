<template>
  <nav class="mb-4 flex items-center gap-1.5 text-xs text-slate-500" aria-label="Ruta de navegación">
    <a href="/monitoring/dashboard" class="hover:text-cyan-700">Dashboard</a>
    <span v-for="(item, index) in items" :key="index" class="flex items-center gap-1.5">
      <span aria-hidden="true">/</span>
      <a v-if="item.href" :href="item.href" class="hover:text-cyan-700">{{ item.label }}</a>
      <span v-else class="text-slate-700">{{ item.label }}</span>
    </span>
  </nav>
</template>

<script setup lang="ts">
defineProps<{
  items: Array<{ label: string; href?: string }>
}>()
</script>
