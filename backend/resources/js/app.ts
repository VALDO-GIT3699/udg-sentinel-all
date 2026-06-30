import '../css/app.css'

import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { ZiggyVue } from '../../vendor/tightenco/ziggy'
import type { DefineComponent } from 'vue'

// Progreso de navegación
import { InertiaProgress } from '@inertiajs/progress'

InertiaProgress.init({
  color: '#3B82F6',
  includeCSS: true,
  showSpinner: false,
  delay: 150,
})

const appName = (window as Window & typeof globalThis & { appName?: string }).appName
  ?? document.title

createInertiaApp({
  title: (title) => `${title} — ${appName}`,

  resolve: (name) =>
    resolvePageComponent(
      `./pages/${name}.vue`,
      import.meta.glob<DefineComponent>('./pages/**/*.vue'),
    ),

  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .use(ZiggyVue)
      .mount(el)
  },
})
