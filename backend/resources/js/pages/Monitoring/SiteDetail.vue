<template>
  <main class="relative min-h-screen overflow-x-hidden bg-sky-50 text-slate-900">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden" aria-hidden="true">
      <div class="absolute -left-40 -top-48 h-[34rem] w-[34rem] rounded-full bg-sky-300/25 blur-[130px]" />
      <div class="absolute -right-32 top-1/4 h-[30rem] w-[30rem] rounded-full bg-blue-300/20 blur-[130px]" />
      <div class="absolute bottom-[-10rem] left-1/3 h-[28rem] w-[28rem] rounded-full bg-emerald-300/15 blur-[130px]" />
      <div class="absolute left-1/2 top-0 h-[24rem] w-[50rem] -translate-x-1/2 rounded-full bg-sky-200/25 blur-[150px]" />
    </div>
    <section class="relative z-10 mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <TopNav current="dashboard" />
      <Breadcrumbs :items="[{ label: site.name }]" />
      <header
        class="mb-6 flex flex-col gap-3 border-b border-slate-900/10 pb-6 lg:flex-row lg:items-end lg:justify-between"
      >
        <div>
          <a href="/monitoring/dashboard" class="text-sm text-cyan-600 hover:text-cyan-700"
            >Volver al dashboard</a
          >
          <h1 class="mt-2 text-fluid-2xl font-semibold text-slate-900">{{ site.name }}</h1>
          <p class="mt-2 text-sm text-slate-700">
            <a
              :href="safeSiteUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="text-cyan-600 hover:text-cyan-700"
              >{{ site.url }}</a
            >
            · Estado actual:
            <span class="font-semibold" :class="statusTextClass(site.current_status)">{{
              statusLabel(site.current_status)
            }}</span>
          </p>
        </div>
        <p class="text-xs text-slate-600">Actualizado: {{ formatDate(updatedAt) }}</p>
      </header>

      <section class="grid gap-6 xl:grid-cols-[2fr_1fr]">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <article
            class="glass-panel rounded-2xl bg-emerald-500/10 p-5"
            title="Porcentaje de tiempo en el que el sitio respondió correctamente durante las últimas 24 horas."
          >
            <p class="text-xs uppercase tracking-[0.18em] text-emerald-600">
              Disponibilidad ultimas 24 horas
            </p>
            <p class="mt-3 text-4xl font-semibold text-slate-900">{{ uptime24h.toFixed(2) }}%</p>
          </article>
          <article
            class="glass-panel rounded-2xl bg-sky-500/10 p-5"
            title="Promedio de milisegundos que tarda la primera respuesta del sitio en 24 horas."
          >
            <p class="text-xs uppercase tracking-[0.18em] text-sky-600">
              Tiempo de primera respuesta promedio (24 horas)
            </p>
            <p class="mt-3 text-4xl font-semibold text-slate-900">
              {{ avgResponse24h !== null ? `${avgResponse24h} ms` : 'Sin datos' }}
            </p>
          </article>
          <article
            class="glass-panel rounded-2xl bg-rose-500/10 p-5"
            title="Incidencias abiertas que siguen activas y requieren seguimiento."
          >
            <p class="text-xs uppercase tracking-[0.18em] text-rose-600">Alertas abiertas</p>
            <p class="mt-3 text-4xl font-semibold text-slate-900">{{ openAlerts.length }}</p>
          </article>
          <article
            class="glass-panel rounded-2xl bg-sky-200/50 p-5"
            title="Eventos recientes registrados en el timeline operativo del sitio."
          >
            <p class="text-xs uppercase tracking-[0.18em] text-slate-700">Eventos recientes</p>
            <p class="mt-3 text-4xl font-semibold text-slate-900">{{ events.length }}</p>
          </article>

          <article class="glass-panel rounded-2xl bg-white/50 p-5 sm:col-span-2 xl:col-span-4">
            <div class="flex items-center justify-between">
              <h2 class="text-sm font-semibold text-slate-900">Latencia y estado en vivo de este sitio</h2>
              <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-emerald-600">
                <span class="relative flex h-2 w-2">
                  <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                  <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                </span>
                En vivo
              </span>
            </div>
            <p class="text-xs text-slate-500">Cada punto es un chequeo real; el color marca su código HTTP.</p>
            <VueApexCharts type="scatter" height="220" :options="siteLatencyChartOptions" :series="siteLatencySeries" />
          </article>
        </div>

        <aside class="glass-panel rounded-3xl bg-white/50 p-5">
          <header class="mb-4">
            <p class="text-xs uppercase tracking-[0.2em] text-cyan-600">Comentarios operativos</p>
            <h2 class="mt-1 text-lg font-semibold text-slate-900">Registro y seguimiento</h2>
          </header>

          <form class="space-y-3" @submit.prevent="submitNote">
            <textarea
              v-model="newNoteText"
              rows="3"
              maxlength="3000"
              placeholder="Registrar comentario operativo"
              class="glass-input w-full rounded-xl px-3 py-2 text-sm text-slate-900 placeholder:text-slate-500 focus:border-cyan-400 focus:outline-none"
            />
            <div class="flex items-center gap-2">
              <select
                v-model="newNoteStatus"
                class="glass-input h-10 rounded-xl px-3 text-sm text-slate-900"
              >
                <option value="en_curso">Incompleta</option>
                <option value="resuelta">Completada</option>
              </select>
              <button
                type="submit"
                class="glass-btn h-10 whitespace-nowrap rounded-xl border-cyan-300/40 bg-cyan-400 px-4 text-sm font-semibold text-slate-50 hover:bg-cyan-300"
                :disabled="isSubmittingNote"
              >
                {{ isSubmittingNote ? 'Guardando...' : 'Agregar' }}
              </button>
            </div>
          </form>

          <div class="mt-4 space-y-3">
            <article
              v-for="note in notesTimeline"
              :key="note.id"
              class="glass-panel rounded-2xl bg-sky-50/40 p-3"
            >
              <div class="flex items-start justify-between gap-2">
                <span
                  class="glass-badge rounded-full px-2.5 py-1 text-[11px] font-semibold"
                  :class="noteStatusClass(note.status)"
                  >{{ noteStatusLabel(note.status) }}</span
                >
                <p class="text-[11px] text-slate-600">{{ formatDate(note.created_at) }}</p>
              </div>
              <p v-if="!note.is_hidden" class="mt-2 text-sm text-slate-800">{{ note.note }}</p>
              <p v-else class="mt-2 text-xs text-slate-500">Comentario oculto</p>
              <p class="mt-1 text-[11px] text-slate-500">por {{ note.created_by_name || 'Sistema' }}</p>
              <div class="mt-3 flex flex-wrap gap-2">
                <button
                  type="button"
                  class="glass-btn whitespace-nowrap rounded-lg border-emerald-500/40 px-2 py-1 text-[11px] font-semibold text-emerald-700"
                  @click="updateNoteStatus(note.id, 'resuelta')"
                >
                  Completada
                </button>
                <button
                  type="button"
                  class="glass-btn whitespace-nowrap rounded-lg border-amber-500/40 px-2 py-1 text-[11px] font-semibold text-amber-700"
                  @click="updateNoteStatus(note.id, 'en_curso')"
                >
                  Incompleta
                </button>
                <button
                  type="button"
                  class="glass-btn whitespace-nowrap rounded-lg border-sky-500/40 px-2 py-1 text-[11px] font-semibold text-slate-800"
                  @click="toggleNoteHidden(note)"
                >
                  {{ note.is_hidden ? 'Mostrar' : 'Ocultar' }}
                </button>
                <button
                  type="button"
                  class="glass-btn whitespace-nowrap rounded-lg border-rose-500/50 px-2 py-1 text-[11px] font-semibold text-rose-600"
                  @click="deleteNote(note.id)"
                >
                  Eliminar
                </button>
              </div>
            </article>

            <p
              v-if="notesTimeline.length === 0"
              class="glass-panel rounded-xl bg-sky-50/40 p-3 text-xs text-slate-600"
            >
              No hay comentarios registrados para este sitio.
            </p>
          </div>
        </aside>
      </section>

      <section class="glass-panel mt-8 rounded-3xl bg-white/50 p-5">
        <header class="mb-4 flex flex-col gap-2 border-b border-slate-900/10 pb-4">
          <p class="text-xs uppercase tracking-[0.22em] text-cyan-600">Ficha técnica consolidada</p>
          <h2 class="text-xl font-semibold text-slate-900">
            Resultado íntegro del último escaneo del sitio
          </h2>
          <p class="text-sm text-slate-600">
            La información mostrada proviene directamente del perfil consolidado guardado por el
            motor.
          </p>
        </header>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">URL</p>
            <p class="mt-1 text-sm text-slate-900 break-all">{{ site.url || 'Sin dato registrado' }}</p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">Dominio</p>
            <p class="mt-1 text-sm text-slate-900 break-all">
              {{ site.domain || 'Sin dato registrado' }}
            </p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">Último escaneo</p>
            <p class="mt-1 text-sm text-slate-900">
              {{
                inspectionProfile.inspected_at
                  ? formatDate(inspectionProfile.inspected_at)
                  : 'Sin dato registrado'
              }}
            </p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">Estado operativo</p>
            <p class="mt-1 text-sm text-slate-900">{{ statusLabel(site.current_status) }}</p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">HTTP Status</p>
            <p class="mt-1 text-sm text-slate-900">{{ displayField(inspectionProfile.http_status) }}</p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">HTTPS Status</p>
            <p class="mt-1 text-sm text-slate-900">
              {{ displayField(inspectionProfile.https_status) }}
            </p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">Tiempo de respuesta</p>
            <p class="mt-1 text-sm text-slate-900">
              {{
                inspectionProfile.response_time_ms !== null
                  ? `${inspectionProfile.response_time_ms} ms`
                  : 'Sin dato registrado'
              }}
            </p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4 sm:col-span-2">
            <p class="text-xs text-slate-600">DNS (A / AAAA / CNAME)</p>
            <p class="mt-1 text-sm text-slate-900 break-all">{{ dnsSummary }}</p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">SSL válido</p>
            <p class="mt-1 text-sm text-slate-900">
              {{ inspectionProfile.ssl.valid === true ? 'Sí' : 'No' }}
            </p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">SSL emisor</p>
            <p class="mt-1 text-sm text-slate-900">{{ displayField(inspectionProfile.ssl.issuer) }}</p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">SSL expiración</p>
            <p class="mt-1 text-sm text-slate-900">
              {{
                inspectionProfile.ssl.expires_at
                  ? formatDate(inspectionProfile.ssl.expires_at)
                  : 'Sin dato registrado'
              }}
            </p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">SSL días restantes</p>
            <p class="mt-1 text-sm text-slate-900">
              {{ displayField(inspectionProfile.ssl.expires_in_days) }}
            </p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">Certificado autofirmado</p>
            <p class="mt-1 text-sm text-slate-900">
              {{
                inspectionProfile.ssl.self_signed === true
                  ? 'Sí'
                  : inspectionProfile.ssl.self_signed === false
                    ? 'No'
                    : 'Sin dato registrado'
              }}
            </p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">CMS</p>
            <p class="mt-1 text-sm text-slate-900">{{ displayField(inspectionProfile.cms_name) }}</p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">Versión CMS</p>
            <p class="mt-1 text-sm text-slate-900">{{ displayField(inspectionProfile.cms_version) }}</p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">Servidor Web</p>
            <p class="mt-1 text-sm text-slate-900 break-all">
              {{ displayField(inspectionProfile.server_signature) }}
            </p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">Lenguaje runtime</p>
            <p class="mt-1 text-sm text-slate-900">
              {{ displayField(inspectionProfile.runtime_name) }}
            </p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4">
            <p class="text-xs text-slate-600">Versión runtime</p>
            <p class="mt-1 text-sm text-slate-900">
              {{ displayField(inspectionProfile.runtime_version) }}
            </p>
          </article>
          <article class="glass-panel rounded-xl bg-sky-50/40 p-4 sm:col-span-2">
            <p class="text-xs text-slate-600">Frameworks / librerías JS</p>
            <p class="mt-1 text-sm text-slate-900 break-all">{{ inspectionFrameworksLabel }}</p>
          </article>
        </div>

        <div class="glass-panel mt-4 rounded-xl bg-sky-50/40 p-4">
          <p class="text-xs text-slate-600">Errores detectados durante el análisis</p>
          <ul v-if="inspectionErrors.length > 0" class="mt-2 space-y-1 text-sm text-amber-700">
            <li v-for="error in inspectionErrors" :key="error">• {{ error }}</li>
          </ul>
          <p v-else class="mt-2 text-sm text-emerald-700">
            Sin errores reportados en el último análisis.
          </p>
        </div>
      </section>

      <section class="mt-8 grid gap-6 xl:grid-cols-2">
        <article class="glass-panel rounded-3xl bg-white/50 p-5">
          <header class="mb-4 flex items-center justify-between gap-3">
            <div>
              <p class="text-xs uppercase tracking-[0.22em] text-rose-600">
                ALERTAS ABIERTAS ACTIVAS
              </p>
              <h2 class="mt-1 text-lg font-semibold text-slate-900">Riesgos abiertos del sitio</h2>
            </div>
            <span class="glass-badge rounded-full bg-rose-500/15 px-3 py-1 text-xs font-semibold text-rose-700"
              >{{ openAlerts.length }} abiertas</span
            >
          </header>

          <div
            v-if="safeOpenAlerts.length === 0"
            class="glass-panel rounded-2xl bg-sky-50/40 p-4 text-sm text-slate-600"
          >
            No hay alertas activas para este sitio.
          </div>

          <div v-else class="space-y-3">
            <article
              v-for="alert in visibleOpenAlerts"
              :key="alert.id"
              class="glass-panel rounded-2xl bg-sky-50/40 p-4"
            >
              <div class="flex items-start justify-between gap-3">
                <div>
                  <p class="text-sm font-semibold text-slate-900">{{ alert.title }}</p>
                  <p class="mt-1 text-xs text-slate-600">{{ formatDate(alert.triggered_at) }}</p>
                </div>
                <span
                  class="glass-badge rounded-full px-3 py-1 text-xs font-semibold"
                  :class="alert.severity_class"
                  >{{ alert.severity_label }}</span
                >
              </div>
              <p class="mt-3 text-sm text-slate-700">{{ alert.friendly_description }}</p>
              <p class="mt-2 text-xs text-cyan-700">
                Acción recomendada: {{ alert.recommended_action }}
              </p>
            </article>

            <button
              v-if="openAlertsHiddenCount > 0"
              type="button"
              class="glass-btn inline-flex items-center gap-2 whitespace-nowrap rounded-full border-sky-500/40 px-4 py-2 text-xs font-semibold text-slate-800 hover:border-rose-400 hover:text-rose-800"
              @click="toggleOpenAlerts"
            >
              <span v-if="!showAllOpenAlerts">Ver más (+{{ openAlertsHiddenCount }})</span>
              <span v-else>Ver menos</span>
            </button>
          </div>
        </article>

        <article class="glass-panel rounded-3xl bg-white/50 p-5">
          <header class="mb-4 flex items-center justify-between gap-3">
            <div>
              <p class="text-xs uppercase tracking-[0.22em] text-cyan-600">
                HISTORIAL DE EVENTOS RECIENTES
              </p>
              <h2 class="mt-1 text-lg font-semibold text-slate-900">Últimos movimientos operativos</h2>
            </div>
            <span class="glass-badge rounded-full bg-cyan-500/15 px-3 py-1 text-xs font-semibold text-cyan-700"
              >{{ events.length }} visibles</span
            >
          </header>

          <div
            v-if="safeRecentEvents.length === 0"
            class="glass-panel rounded-2xl bg-sky-50/40 p-4 text-sm text-slate-600"
          >
            No hay eventos recientes registrados.
          </div>

          <div v-else class="space-y-3">
            <article
              v-for="event in visibleRecentEvents"
              :key="event.id"
              class="glass-panel rounded-2xl bg-sky-50/40 p-4"
            >
              <div class="flex items-start gap-3">
                <div
                  class="mt-1 inline-flex h-8 w-8 items-center justify-center rounded-full bg-sky-200 text-sm font-bold"
                  :class="event.icon_class"
                >
                  {{ event.icon }}
                </div>
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm font-semibold text-slate-900">{{ event.title }}</p>
                    <span
                      class="glass-badge rounded-full bg-sky-200/70 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-700"
                      >{{ event.event_type }}</span
                    >
                  </div>
                  <p class="mt-1 text-sm text-slate-700">{{ event.description }}</p>
                  <p class="mt-2 text-xs text-slate-600">{{ formatDate(event.occurred_at) }}</p>
                </div>
              </div>
            </article>

            <button
              v-if="recentEventsHiddenCount > 0"
              type="button"
              class="glass-btn inline-flex items-center gap-2 whitespace-nowrap rounded-full border-sky-500/40 px-4 py-2 text-xs font-semibold text-slate-800 hover:border-cyan-400 hover:text-cyan-800"
              @click="toggleRecentEvents"
            >
              <span v-if="!showAllRecentEvents">Ver más (+{{ recentEventsHiddenCount }})</span>
              <span v-else>Ver menos</span>
            </button>
          </div>
        </article>
      </section>

      <section
        v-if="isTelemetryInitializing"
        class="glass-panel mt-6 rounded-2xl border-cyan-400/30 bg-cyan-500/10 p-4"
      >
        <p class="text-xs uppercase tracking-[0.18em] text-cyan-700">Estado de telemetria</p>
        <p class="mt-2 text-sm font-medium text-cyan-800">
          Telemetria en proceso de inicializacion: Esperando la primera ronda de escaneos masivos.
        </p>
      </section>

      <section class="mt-8 grid gap-6">
        <article class="glass-panel rounded-3xl bg-white/50 p-5">
          <header class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-slate-900">
              Línea de tiempo de respuesta (latencia)
            </h2>
            <p class="text-xs text-slate-600">Ventana de 24 horas</p>
          </header>
          <VueApexCharts
            v-if="!isTelemetryInitializing"
            type="line"
            height="320"
            :options="ttfbChartOptions"
            :series="ttfbSeries"
          />
          <div v-else class="glass-panel space-y-3 rounded-2xl bg-white/40 p-4">
            <div class="h-3 w-56 animate-pulse rounded bg-sky-300/80" />
            <div class="h-40 animate-pulse rounded-xl bg-sky-200/90" />
            <p class="text-xs text-slate-600">
              La linea de tiempo aparecera automaticamente cuando se registren mediciones.
            </p>
          </div>
        </article>

        <section class="grid gap-6 xl:grid-cols-2">
          <article class="glass-panel rounded-3xl bg-white/50 p-5">
            <header class="mb-4 flex items-center justify-between">
              <h2 class="text-lg font-semibold text-slate-900">Picos de tráfico</h2>
              <p class="text-xs text-slate-600">Últimas 24 horas</p>
            </header>
            <VueApexCharts
              v-if="!isTelemetryInitializing"
              type="area"
              height="300"
              :options="traffic24hOptions"
              :series="traffic24hSeries"
            />
            <div v-else class="glass-panel space-y-3 rounded-2xl bg-white/40 p-4">
              <div class="h-3 w-44 animate-pulse rounded bg-sky-300/80" />
              <div class="h-36 animate-pulse rounded-xl bg-sky-200/90" />
            </div>
          </article>

          <article class="glass-panel rounded-3xl bg-white/50 p-5">
            <header class="mb-4 flex items-center justify-between">
              <h2 class="text-lg font-semibold text-slate-900">Picos de tráfico</h2>
              <p class="text-xs text-slate-600">Última hora</p>
            </header>
            <VueApexCharts
              v-if="!isTelemetryInitializing"
              type="bar"
              height="300"
              :options="traffic1hOptions"
              :series="traffic1hSeries"
            />
            <div v-else class="glass-panel space-y-3 rounded-2xl bg-white/40 p-4">
              <div class="h-3 w-44 animate-pulse rounded bg-sky-300/80" />
              <div class="h-36 animate-pulse rounded-xl bg-sky-200/90" />
            </div>
          </article>
        </section>

        <article class="glass-panel rounded-3xl bg-white/50 p-5">
          <header class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-slate-900">Disponibilidad general</h2>
            <p class="text-xs text-slate-600">Estados por medicion en 24 horas</p>
          </header>
          <VueApexCharts
            v-if="!isTelemetryInitializing"
            type="line"
            height="300"
            :options="uptimeOptions"
            :series="uptimeSeries"
          />
          <div v-else class="glass-panel space-y-3 rounded-2xl bg-white/40 p-4">
            <div class="h-3 w-48 animate-pulse rounded bg-sky-300/80" />
            <div class="h-36 animate-pulse rounded-xl bg-sky-200/90" />
          </div>
        </article>
      </section>

      <section class="mt-8 grid gap-6 lg:grid-cols-2">
        <article class="glass-panel rounded-3xl bg-white/50 p-5">
          <h2 class="text-lg font-semibold text-slate-900">Certificado de seguridad del sitio</h2>
          <div
            v-if="isTelemetryInitializing && !hasSslTelemetry"
            class="glass-panel mt-4 space-y-3 rounded-2xl border-cyan-400/20 bg-cyan-500/5 p-4"
          >
            <p class="text-sm text-cyan-800">
              Aun no hay metadatos SSL disponibles para este sitio.
            </p>
            <div class="grid gap-3 sm:grid-cols-2">
              <div class="h-16 animate-pulse rounded-xl bg-sky-200/90" />
              <div class="h-16 animate-pulse rounded-xl bg-sky-200/90" />
              <div class="h-16 animate-pulse rounded-xl bg-sky-200/90" />
              <div class="h-16 animate-pulse rounded-xl bg-sky-200/90" />
            </div>
          </div>
          <div v-else class="mt-4 grid gap-3 sm:grid-cols-2">
            <div class="glass-panel rounded-xl bg-white/40 p-4">
              <p class="text-xs uppercase tracking-[0.18em] text-slate-700">Fecha de expiración</p>
              <p class="mt-2 text-lg font-semibold text-slate-900">
                {{
                  site.ssl_certificate?.valid_until
                    ? formatDate(site.ssl_certificate.valid_until)
                    : 'Sin datos'
                }}
              </p>
            </div>
            <div class="glass-panel rounded-xl bg-white/40 p-4">
              <p class="text-xs uppercase tracking-[0.18em] text-slate-700">Emisor</p>
              <p class="mt-2 text-lg font-semibold text-slate-900">
                {{ site.ssl_certificate?.issuer || 'Sin datos' }}
              </p>
            </div>
            <div class="glass-panel rounded-xl bg-white/40 p-4">
              <p class="text-xs uppercase tracking-[0.18em] text-slate-700">Días restantes</p>
              <p class="mt-2 text-lg font-semibold text-slate-900">
                {{ site.ssl_certificate?.days_remaining ?? 'Sin datos' }}
              </p>
            </div>
            <div class="glass-panel rounded-xl bg-white/40 p-4">
              <p class="text-xs uppercase tracking-[0.18em] text-slate-700">Algoritmo</p>
              <p class="mt-2 text-lg font-semibold text-slate-900">
                {{ site.ssl_certificate?.algorithm || 'Sin datos' }}
              </p>
            </div>
          </div>
        </article>

        <article class="glass-panel rounded-3xl bg-white/50 p-5">
          <h2
            class="text-lg font-semibold text-slate-900"
            title="Resumen de cabeceras HTTP que refuerzan la seguridad del sitio."
          >
            Cabeceras de seguridad
          </h2>
          <div
            v-if="isTelemetryInitializing && !hasSecurityHeadersTelemetry"
            class="glass-panel mt-4 space-y-3 rounded-2xl border-cyan-400/20 bg-cyan-500/5 p-4"
          >
            <p class="text-sm text-cyan-800">
              Aun no se han detectado cabeceras durante los escaneos iniciales.
            </p>
            <div class="space-y-3">
              <div class="h-14 animate-pulse rounded-xl bg-sky-200/90" />
              <div class="h-14 animate-pulse rounded-xl bg-sky-200/90" />
              <div class="h-14 animate-pulse rounded-xl bg-sky-200/90" />
            </div>
          </div>
          <ul v-else class="mt-4 space-y-3">
            <li
              v-for="header in securityHeaders"
              :key="header.key"
              class="glass-panel rounded-xl bg-white/40 p-4"
              :title="`Cabecera ${header.label}: ${header.present ? 'detectada' : 'no detectada'}. ${securityHeaderHint(header.key)}`"
            >
              <div class="flex items-start justify-between gap-3">
                <div>
                  <p class="text-sm font-semibold text-slate-900">{{ header.label }}</p>
                  <p class="mt-1 text-xs text-slate-600">
                    {{ header.value || 'Sin valor reportado' }}
                  </p>
                </div>
                <span
                  class="glass-badge rounded-full px-3 py-1 text-xs font-semibold"
                  :class="
                    header.present
                      ? 'bg-emerald-500/15 text-emerald-600'
                      : 'bg-rose-500/15 text-rose-600'
                  "
                >
                  {{ header.present ? 'Presente' : 'Ausente' }}
                </span>
              </div>
            </li>
            <li
              v-if="securityHeaders.length === 0"
              class="glass-panel rounded-xl bg-white/40 p-4 text-sm text-slate-600"
            >
              No hay registros de cabeceras para este sitio.
            </li>
          </ul>
        </article>
      </section>

      <AppFooter />
    </section>

    <CookieNotice />
  </main>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
  import { router } from '@inertiajs/vue3'
  import type { ApexOptions } from 'apexcharts'
  import VueApexCharts from 'vue3-apexcharts'
  import TopNav from '@/components/layout/TopNav.vue'
  import AppFooter from '@/components/layout/AppFooter.vue'
  import CookieNotice from '@/components/ui/CookieNotice.vue'
  import Breadcrumbs from '@/components/layout/Breadcrumbs.vue'

  type TimelinePoint = {
    id: number
    checked_at: string
    status: 'up' | 'down' | 'degraded' | 'timeout' | 'unknown'
    http_code: number | null
    response_time_ms: number | null
  }

  type InspectionProfile = {
    inspected_at: string | null
    dns_status: string
    dns_records: {
      a?: string[]
      aaaa?: string[]
      cname?: string[]
    }
    http_status: number | null
    https_status: number | null
    response_time_ms: number | null
    ssl: {
      valid?: boolean
      issuer?: string | null
      expires_at?: string | null
      expires_in_days?: number | null
      self_signed?: boolean | null
    }
    security_headers: {
      score?: string | null
    }
    fingerprint: Record<string, unknown>
    cms_name: string
    cms_version: string
    server_signature: string
    runtime_name: string
    runtime_version: string
    frameworks: string[]
    risk_score: number | null
    risk_level: string
    analysis_errors: string[]
  }

  type TrafficPoint = {
    at: string | null
    rpm: number
    error_rate_pct: number
  }

  type SecurityHeaderPoint = {
    key: string
    label: string
    present: boolean
    value: string
  }

  type SiteDetail = {
    id: number
    name: string
    url: string
    domain: string
    current_status: 'up' | 'down' | 'degraded' | 'unknown'
    ssl_certificate: {
      valid_until: string | null
      issuer: string | null
      days_remaining: number | null
      algorithm: string | null
    } | null
  }

  type AlertItem = {
    id: number
    title: string
    severity: string
    severity_label: string
    severity_class: string
    triggered_at: string | null
    friendly_description: string
    recommended_action: string
  }

  type EventItem = {
    id: number
    title: string
    event_type: string
    severity: string
    icon: string
    icon_class: string
    description: string
    occurred_at: string | null
  }

  type NoteItem = {
    id: number
    title: string
    note: string
    status: 'en_curso' | 'resuelta' | 'completada' | 'incompleta' | 'cancelada'
    is_hidden?: boolean
    created_at: string | null
    completed_at?: string | null
    resolved_at?: string | null
    created_by_name?: string | null
  }

  const props = defineProps<{
    site: SiteDetail
    inspectionProfile: InspectionProfile
    notesTimeline: NoteItem[]
    timeline: TimelinePoint[]
    statusBreakdown24h: Record<string, number>
    uptime24h: number
    avgResponse24h: number | null
    openAlerts: AlertItem[]
    events: EventItem[]
    trafficSeries24h: TrafficPoint[]
    trafficSeries1h: TrafficPoint[]
    securityHeaders: SecurityHeaderPoint[]
    updatedAt: string
  }>()

  const inspectionFrameworksLabel = computed(() => {
    const frameworks = Array.isArray(inspectionProfile.value.frameworks)
      ? inspectionProfile.value.frameworks.filter((item) => item.trim() !== '')
      : []
    return frameworks.length > 0 ? frameworks.join(', ') : 'Sin dato registrado'
  })

  const dnsSummary = computed(() => {
    const records = inspectionProfile.value.dns_records ?? {}
    const parts: string[] = []

    const a = Array.isArray(records.a) ? records.a.filter((item) => item.trim() !== '') : []
    const aaaa = Array.isArray(records.aaaa)
      ? records.aaaa.filter((item) => item.trim() !== '')
      : []
    const cname = Array.isArray(records.cname)
      ? records.cname.filter((item) => item.trim() !== '')
      : []

    if (a.length > 0) {
      parts.push(`A: ${a.join(', ')}`)
    }
    if (aaaa.length > 0) {
      parts.push(`AAAA: ${aaaa.join(', ')}`)
    }
    if (cname.length > 0) {
      parts.push(`CNAME: ${cname.join(', ')}`)
    }

    return parts.length > 0 ? parts.join(' | ') : 'Sin dato registrado'
  })

  const safeTimeline = computed(() => (Array.isArray(props.timeline) ? props.timeline : []))
  const safeTraffic24h = computed(() =>
    Array.isArray(props.trafficSeries24h) ? props.trafficSeries24h : [],
  )
  const safeTraffic1h = computed(() =>
    Array.isArray(props.trafficSeries1h) ? props.trafficSeries1h : [],
  )
  const securityHeaders = computed(() =>
    Array.isArray(props.securityHeaders) ? props.securityHeaders : [],
  )
  const safeOpenAlerts = computed(() => (Array.isArray(props.openAlerts) ? props.openAlerts : []))
  const safeRecentEvents = computed(() => (Array.isArray(props.events) ? props.events : []))
  const notesTimeline = computed(() =>
    Array.isArray(props.notesTimeline) ? props.notesTimeline : [],
  )
  const newNoteText = ref('')
  const newNoteStatus = ref<'en_curso' | 'resuelta'>('en_curso')
  const isSubmittingNote = ref(false)
  const showAllOpenAlerts = ref(false)
  const showAllRecentEvents = ref(false)
  const visibleOpenAlerts = computed(() =>
    showAllOpenAlerts.value ? safeOpenAlerts.value : safeOpenAlerts.value.slice(0, 3),
  )
  const visibleRecentEvents = computed(() =>
    showAllRecentEvents.value ? safeRecentEvents.value : safeRecentEvents.value.slice(0, 3),
  )
  const openAlertsHiddenCount = computed(() => Math.max(0, safeOpenAlerts.value.length - 3))
  const recentEventsHiddenCount = computed(() => Math.max(0, safeRecentEvents.value.length - 3))
  const totalChecks24h = computed(() => {
    const breakdown = props.statusBreakdown24h ?? {}
    return (
      Number(breakdown.up ?? 0) +
      Number(breakdown.down ?? 0) +
      Number(breakdown.degraded ?? 0) +
      Number(breakdown.timeout ?? 0)
    )
  })
  const isTelemetryInitializing = computed(
    () => props.site.current_status === 'unknown' || totalChecks24h.value === 0,
  )
  const hasSslTelemetry = computed(() => {
    const cert = props.site.ssl_certificate
    if (!cert) {
      return false
    }

    return Boolean(
      cert.valid_until || cert.issuer || cert.days_remaining !== null || cert.algorithm,
    )
  })
  const hasSecurityHeadersTelemetry = computed(() =>
    securityHeaders.value.some((header) => header.present || header.value.trim() !== ''),
  )
  const safeSiteUrl = computed(() => {
    const value = props.site.url?.trim() || ''
    if (value === '') {
      return '#'
    }

    return value.startsWith('http://') || value.startsWith('https://') ? value : `https://${value}`
  })

  const noteStatusLabel = (status: NoteItem['status']) => {
    if (status === 'resuelta' || status === 'completada') return 'Completada'
    if (status === 'cancelada') return 'Cancelada'
    return 'Incompleta'
  }

  const noteStatusClass = (status: NoteItem['status']) => {
    if (status === 'resuelta' || status === 'completada')
      return 'bg-emerald-500/20 text-emerald-700'
    if (status === 'cancelada') return 'bg-rose-500/20 text-rose-700'
    return 'bg-amber-500/20 text-amber-700'
  }

  const submitNote = () => {
    if (newNoteText.value.trim() === '' || isSubmittingNote.value) {
      return
    }

    isSubmittingNote.value = true

    router.post(
      `/monitoring/sites/${props.site.id}/notes`,
      {
        note: newNoteText.value.trim(),
        status: newNoteStatus.value,
      },
      {
        preserveScroll: true,
        onFinish: () => {
          isSubmittingNote.value = false
        },
        onSuccess: () => {
          newNoteText.value = ''
          newNoteStatus.value = 'en_curso'
        },
      },
    )
  }

  const updateNoteStatus = (noteId: number, status: 'en_curso' | 'resuelta') => {
    router.patch(
      `/monitoring/sites/${props.site.id}/notes/${noteId}`,
      {
        status,
      },
      {
        preserveScroll: true,
      },
    )
  }

  const toggleNoteHidden = (note: NoteItem) => {
    router.patch(
      `/monitoring/sites/${props.site.id}/notes/${note.id}`,
      {
        is_hidden: !note.is_hidden,
      },
      {
        preserveScroll: true,
      },
    )
  }

  const deleteNote = (noteId: number) => {
    router.delete(`/monitoring/sites/${props.site.id}/notes/${noteId}`, {
      preserveScroll: true,
    })
  }

  const ttfbSeries = computed(() => [
    {
      name: 'TTFB (ms)',
      data: safeTimeline.value
        .filter((point) => point.checked_at !== null)
        .map((point) => ({ x: point.checked_at, y: point.response_time_ms ?? 0 })),
    },
  ])

  const uptimeSeries = computed(() => [
    {
      name: 'Disponibilidad',
      data: safeTimeline.value
        .filter((point) => point.checked_at !== null)
        .map((point) => ({
          x: point.checked_at,
          y: point.status === 'up' ? 100 : point.status === 'degraded' ? 50 : 0,
        })),
    },
  ])

  const traffic24hSeries = computed(() => [
    {
      name: 'Requests por minuto',
      data: safeTraffic24h.value
        .filter((point) => point.at !== null)
        .map((point) => ({ x: point.at, y: point.rpm })),
    },
  ])

  // ── Latencia y estado en vivo (este sitio) ──────────────────────
  type LiveCheckPoint = { at: string | null; response_time_ms: number | null; status: string; http_code: number | null }
  const liveCheckPoints = ref<LiveCheckPoint[]>([])
  let liveCheckPollingInterval: ReturnType<typeof setInterval> | null = null

  const fetchSiteLatencyTimeseries = async () => {
    try {
      const response = await fetch(`/monitoring/sites/${props.site.id}/latency-timeseries?minutes=60`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      })
      if (!response.ok) return
      const payload = (await response.json()) as { points?: LiveCheckPoint[] }
      liveCheckPoints.value = Array.isArray(payload.points) ? payload.points : []
    } catch {
      // Un fallo puntual de sondeo se autocorrige en el siguiente intento.
    }
  }

  const siteLatencySeries = computed(() => {
    const byStatus: Record<'up' | 'degraded' | 'other', Array<{ x: number; y: number }>> = {
      up: [],
      degraded: [],
      other: [],
    }

    for (const point of liveCheckPoints.value) {
      if (point.at === null || point.response_time_ms === null) continue
      const bucket = point.status === 'up' ? 'up' : point.status === 'degraded' ? 'degraded' : 'other'
      byStatus[bucket].push({ x: new Date(point.at).getTime(), y: point.response_time_ms })
    }

    return [
      { name: 'OK', data: byStatus.up },
      { name: 'Degradado', data: byStatus.degraded },
      { name: 'Caído / error', data: byStatus.other },
    ]
  })

  const siteLatencyChartOptions = computed<ApexOptions>(() => ({
    chart: {
      type: 'scatter',
      toolbar: { show: false },
      foreColor: '#334155',
      animations: { enabled: true, dynamicAnimation: { speed: 350 } },
    },
    colors: ['#10b981', '#f59e0b', '#e11d48'],
    xaxis: {
      type: 'datetime',
      labels: { style: { colors: '#64748B' }, datetimeUTC: false, format: 'HH:mm' },
    },
    yaxis: {
      labels: { style: { colors: '#64748B' }, formatter: (value: number) => `${Math.round(value)} ms` },
    },
    legend: { position: 'top', labels: { colors: '#334155' } },
    grid: { borderColor: 'rgba(100,116,139,0.2)' },
    tooltip: {
      theme: 'light',
      x: { format: 'HH:mm:ss' },
      y: { formatter: (value: number) => `${value.toFixed(0)} ms` },
    },
    noData: { text: 'Esperando chequeos recientes...', style: { color: '#64748B' } },
  }))

  onMounted(() => {
    void fetchSiteLatencyTimeseries()
    liveCheckPollingInterval = setInterval(() => {
      void fetchSiteLatencyTimeseries()
    }, 8000)
  })

  onBeforeUnmount(() => {
    if (liveCheckPollingInterval) {
      clearInterval(liveCheckPollingInterval)
      liveCheckPollingInterval = null
    }
  })

  const traffic1hSeries = computed(() => [
    {
      name: 'Requests por minuto',
      data: safeTraffic1h.value
        .filter((point) => point.at !== null)
        .map((point) => ({ x: point.at, y: point.rpm })),
    },
  ])

  const baseChartOptions: ApexOptions = {
    chart: {
      toolbar: { show: false },
      animations: { enabled: true },
      foreColor: '#334155',
    },
    noData: {
      text: 'Sin muestras en este periodo',
      style: {
        color: '#64748B',
      },
    },
    tooltip: {
      theme: 'light',
      x: {
        format: 'dd/MM HH:mm',
      },
    },
    stroke: {
      curve: 'smooth',
      width: 3,
    },
    xaxis: {
      type: 'datetime',
      labels: {
        datetimeUTC: false,
        style: { colors: '#94A3B8' },
      },
    },
    yaxis: {
      labels: { style: { colors: '#94A3B8' } },
    },
    grid: {
      borderColor: 'rgba(148,163,184,0.2)',
    },
  }

  const ttfbChartOptions = computed<ApexOptions>(() => ({
    ...baseChartOptions,
    colors: ['#22D3EE'],
    yaxis: {
      labels: { style: { colors: '#94A3B8' } },
      title: {
        text: 'Milisegundos',
        style: { color: '#94A3B8' },
      },
    },
  }))

  const traffic24hOptions = computed<ApexOptions>(() => ({
    ...baseChartOptions,
    colors: ['#60A5FA'],
    fill: {
      type: 'gradient',
      gradient: {
        shadeIntensity: 0.45,
        opacityFrom: 0.55,
        opacityTo: 0.05,
        stops: [0, 90, 100],
      },
    },
    stroke: {
      curve: 'smooth',
      width: 2,
    },
  }))

  const traffic1hOptions = computed<ApexOptions>(() => ({
    ...baseChartOptions,
    colors: ['#F59E0B'],
    plotOptions: {
      bar: {
        borderRadius: 6,
        columnWidth: '45%',
      },
    },
    stroke: {
      width: 0,
    },
  }))

  const uptimeOptions = computed<ApexOptions>(() => ({
    ...baseChartOptions,
    colors: ['#34D399'],
    yaxis: {
      min: 0,
      max: 100,
      tickAmount: 4,
      labels: {
        formatter: (value: number) => `${value}%`,
        style: { colors: '#94A3B8' },
      },
    },
  }))

  const statusLabel = (status: SiteDetail['current_status']) => {
    if (status === 'up') return 'ACTIVO'
    if (status === 'degraded') return 'DEGRADADO'
    if (status === 'down') return 'CAÍDO'
    return 'DESCONOCIDO'
  }

  const statusTextClass = (status: SiteDetail['current_status']) => {
    if (status === 'up') return 'text-emerald-600'
    if (status === 'degraded') return 'text-amber-600'
    if (status === 'down') return 'text-rose-600'
    return 'text-slate-700'
  }

  const formatDate = (value: string | null) => {
    if (!value) {
      return 'Sin datos'
    }

    const parsed = new Date(value)
    return Number.isNaN(parsed.getTime()) ? 'Sin datos' : parsed.toLocaleString('es-MX')
  }

  const toggleOpenAlerts = () => {
    showAllOpenAlerts.value = !showAllOpenAlerts.value
  }

  const toggleRecentEvents = () => {
    showAllRecentEvents.value = !showAllRecentEvents.value
  }

  const securityHeaderHint = (key: string) => {
    if (key === 'content-security-policy') {
      return 'Protege contra inyeccion de scripts y contenido no autorizado.'
    }

    if (key === 'strict-transport-security') {
      return 'Obliga a usar HTTPS en visitas futuras.'
    }

    if (key === 'x-frame-options') {
      return 'Evita que el sitio se incruste en iframes no confiables.'
    }

    if (key === 'x-content-type-options') {
      return 'Reduce interpretaciones MIME inseguras.'
    }

    if (key === 'referrer-policy') {
      return 'Controla cuanta informacion de origen se comparte al navegar.'
    }

    if (key === 'permissions-policy') {
      return 'Limita el acceso a funciones sensibles del navegador.'
    }

    return 'Cabecera relevante para endurecer la superficie publica.'
  }

  const displayField = (value: unknown) => {
    if (value === null || value === undefined) {
      return 'Sin dato registrado'
    }

    if (typeof value === 'string') {
      const trimmed = value.trim()
      if (trimmed === '' || trimmed.toLowerCase() === 'no determinado') {
        return 'Sin dato registrado'
      }

      return trimmed
    }

    return String(value)
  }

  const inspectionProfile = computed<InspectionProfile>(() => props.inspectionProfile)
  const inspectionErrors = computed<string[]>(() =>
    Array.isArray(inspectionProfile.value.analysis_errors)
      ? inspectionProfile.value.analysis_errors
      : [],
  )
</script>
