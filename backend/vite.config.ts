import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { readFileSync } from 'fs'
import { fileURLToPath, URL } from 'node:url'

// Carga automáticamente los entry points de todos los módulos
let moduleEntries: string[] = []
try {
    const loader = JSON.parse(readFileSync('./vite-module-loader.js', 'utf-8').replace(/^[^{]+/, ''))
    if (loader?.input) {
        moduleEntries = loader.input
    }
} catch {
    // No hay módulos con assets propios aún
}

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.ts',
                ...moduleEntries,
            ],
            refresh: true,
            ssr: 'resources/js/ssr.ts',
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            '@types': fileURLToPath(new URL('./resources/js/types', import.meta.url)),
            '@components': fileURLToPath(new URL('./resources/js/components', import.meta.url)),
            '@composables': fileURLToPath(new URL('./resources/js/composables', import.meta.url)),
            '@utils': fileURLToPath(new URL('./resources/js/utils', import.meta.url)),
        },
    },
    build: {
        sourcemap: false,
        rollupOptions: {
            output: {
                manualChunks: {
                    'vendor-vue': ['vue', '@inertiajs/vue3'],
                    'vendor-charts': ['apexcharts', 'vue3-apexcharts'],
                    'vendor-ui': ['@headlessui/vue', 'lucide-vue-next'],
                },
            },
        },
    },
})

