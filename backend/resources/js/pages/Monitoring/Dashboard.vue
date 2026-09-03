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
      <header class="mb-8 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.22em] text-cyan-600">UDG Sentinel</p>
          <h1 class="mt-2 text-fluid-2xl font-semibold text-slate-900">
            Estado general de sitios oficiales
          </h1>
          <p class="mt-2 max-w-3xl text-sm text-slate-700">
            Inventario operativo limpio con acceso directo al detalle técnico de cada sitio.
          </p>
        </div>
        <p class="text-xs text-slate-600">Actualizado: {{ formattedUpdatedAt }}</p>
      </header>

      <section class="mb-5 flex flex-wrap items-center gap-3">
        <button
          type="button"
          class="glass-btn h-11 whitespace-nowrap rounded-xl border border-cyan-400/40 bg-cyan-500/10 px-5 text-sm font-semibold text-cyan-800 backdrop-blur-md transition hover:border-cyan-300 hover:bg-cyan-500/20 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="isExporting"
          @click="exportDashboardPdf"
        >
          {{ isExporting ? 'Exportando...' : 'Exportar PDF Editable' }}
        </button>
        <button
          type="button"
          class="glass-btn h-11 whitespace-nowrap rounded-xl border border-amber-400/50 bg-amber-500/10 px-5 text-sm font-semibold text-amber-800 backdrop-blur-md transition hover:border-amber-300 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="isMassScanRunning"
          @click="scanAllSites"
        >
          {{ isMassScanRunning ? 'Escaneo masivo en ejecución...' : 'Iniciar escaneo masivo' }}
        </button>
        <p class="text-xs text-slate-600">
          Revalida ficha técnica integral por sitio en todos los activos del inventario.
        </p>
      </section>

      <section
        v-if="actionMessage"
        class="mb-4 rounded-xl border border-cyan-500/40 bg-cyan-500/10 px-4 py-3 text-sm text-cyan-800"
      >
        {{ actionMessage }}
      </section>

      <section
        v-if="isMassScanRunning"
        class="mb-4 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-800"
      >
        Snapshot estable activo: la lista y los conteos quedan congelados durante la corrida para
        evitar saltos visuales. Se actualizarán automáticamente al finalizar.
      </section>

      <section
        v-if="showMassScanOverlay"
        class="mb-6 rounded-2xl border border-cyan-500/40 bg-white/95 p-5 shadow-[0_15px_45px_rgba(8,145,178,0.15)]"
      >
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p class="text-xs uppercase tracking-[0.22em] text-cyan-600">Escaneo masivo en curso</p>
            <h2 class="mt-2 text-xl font-semibold text-slate-900">
              Revalidando ficha técnica completa por sitio
            </h2>
            <p class="mt-1 text-sm text-slate-700">
              Completado {{ massScanCompletedTasks }} de {{ massScanTotalTasks }} tareas · Restantes
              {{ massScanRemainingTasks }}
            </p>
          </div>
          <div class="text-right">
            <p class="text-3xl font-semibold text-cyan-700">
              {{ massScanProgressPct.toFixed(1) }}%
            </p>
            <p class="mt-1 text-xs text-slate-600">Inicio: {{ massScanStartedAt }}</p>
            <button
              v-if="canRunMassScan"
              type="button"
              class="mt-2 rounded-lg border border-rose-600/50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:border-rose-400 disabled:cursor-not-allowed disabled:opacity-60"
              :disabled="isCancellingMassScan"
              @click="cancelMassScan"
            >
              {{ isCancellingMassScan ? 'Cancelando...' : 'Cancelar escaneo' }}
            </button>
          </div>
        </div>

        <div class="mt-4 h-3 w-full overflow-hidden rounded-full bg-sky-200">
          <div
            class="h-full rounded-full bg-gradient-to-r from-cyan-400 via-emerald-300 to-cyan-200 transition-all duration-300"
            :style="{ width: `${massScanProgressPct}%` }"
          />
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-2">
          <div
            v-for="stage in massScanStageRows"
            :key="stage.key"
            class="rounded-xl border border-sky-300/80 bg-sky-50/60 p-3"
          >
            <div class="mb-2 flex items-center justify-between text-xs">
              <span class="font-semibold uppercase tracking-[0.16em] text-slate-700">{{
                stage.label
              }}</span>
              <span class="text-slate-600"
                >{{ stage.completed }}/{{ stage.total }} · faltan {{ stage.remaining }}</span
              >
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-sky-200">
              <div
                class="h-full rounded-full bg-cyan-400 transition-all duration-300"
                :style="{ width: `${stage.progressPct}%` }"
              />
            </div>
          </div>
        </div>
      </section>

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <button
          type="button"
          class="glass-panel glass-btn rounded-2xl border-emerald-400/25 bg-emerald-500/10 p-5 text-left hover:border-emerald-300/60 disabled:cursor-not-allowed disabled:opacity-60"
          :title="'Sitios que responden dentro de los parametros esperados.'"
          :disabled="isSnapshotFrozen"
          @click="setStatusFilter('up')"
        >
          <p class="text-xs uppercase tracking-[0.18em] text-emerald-600">Operativos</p>
          <p class="mt-3 text-4xl font-semibold text-slate-900">{{ normalizedStatusCounts.UP }}</p>
        </button>
        <button
          type="button"
          class="glass-panel glass-btn rounded-2xl border-amber-400/25 bg-amber-500/10 p-5 text-left hover:border-amber-300/60 disabled:cursor-not-allowed disabled:opacity-60"
          :title="'Sitios que responden, pero con degradacion o restricciones temporales.'"
          :disabled="isSnapshotFrozen"
          @click="setStatusFilter('degraded')"
        >
          <p class="text-xs uppercase tracking-[0.18em] text-amber-600">Con incidencias</p>
          <p class="mt-3 text-4xl font-semibold text-slate-900">
            {{ normalizedStatusCounts.DEGRADED }}
          </p>
        </button>
        <button
          type="button"
          class="glass-panel glass-btn rounded-2xl border-rose-400/25 bg-rose-500/10 p-5 text-left hover:border-rose-300/60 disabled:cursor-not-allowed disabled:opacity-60"
          :title="'Sitios que no responden o fallan la verificacion principal.'"
          :disabled="isSnapshotFrozen"
          @click="setStatusFilter('down')"
        >
          <p class="text-xs uppercase tracking-[0.18em] text-rose-600">No responde</p>
          <p class="mt-3 text-4xl font-semibold text-slate-900">{{ normalizedStatusCounts.DOWN }}</p>
        </button>
        <button
          type="button"
          class="glass-panel glass-btn rounded-2xl border-sky-600/15 bg-sky-500/10 p-5 text-left hover:border-sky-700/40 disabled:cursor-not-allowed disabled:opacity-60"
          :title="'Sitios sin una medicion reciente o aun en proceso de escaneo.'"
          :disabled="isSnapshotFrozen"
          @click="setStatusFilter('unknown')"
        >
          <p class="text-xs uppercase tracking-[0.18em] text-slate-700">Sin actualizar</p>
          <p class="mt-3 text-4xl font-semibold text-slate-900">{{ normalizedStatusCounts.UNKNOWN }}</p>
        </button>
      </section>

      <section class="mt-6 grid gap-6 xl:grid-cols-2">
        <article class="glass-panel rounded-3xl bg-white/50 p-5">
          <h2 class="text-lg font-semibold text-slate-900">Distribución por estado</h2>
          <VueApexCharts
            type="pie"
            height="280"
            :options="statusPieOptions"
            :series="statusPieSeries"
          />
        </article>
        <article class="glass-panel rounded-3xl bg-white/50 p-5">
          <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-slate-900">Latencia promedio en vivo</h2>
            <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-emerald-600">
              <span class="relative flex h-2 w-2">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
              </span>
              En vivo
            </span>
          </div>
          <p class="text-xs text-slate-500">Promedio de respuesta (ms) de los últimos 30 minutos, por minuto.</p>
          <VueApexCharts
            type="area"
            height="252"
            :options="latencyChartOptions"
            :series="latencySeries"
          />
        </article>
      </section>

      <section class="glass-panel mt-6 rounded-3xl bg-white/50 p-5">
        <header
          class="mb-4 flex flex-col gap-3 border-b border-sky-200 pb-4 lg:flex-row lg:items-end lg:justify-between"
        >
          <div>
            <p class="text-xs uppercase tracking-[0.22em] text-cyan-600">Certificados por vencer</p>
            <h2 class="mt-1 text-xl font-semibold text-slate-900">Top 5 urgentes</h2>
            <p class="mt-1 text-sm text-slate-600">
              Lista priorizada por días restantes para intervención inmediata.
            </p>
          </div>
          <p class="text-xs text-slate-500">Ordenado por urgencia</p>
        </header>

        <div
          v-if="preventiveExpirationsSorted.length === 0"
          class="rounded-2xl border border-sky-200 bg-sky-50/60 p-4 text-sm text-slate-600"
        >
          No hay certificados SSL que expiren en los próximos 60 días.
        </div>

        <div v-else class="space-y-3">
          <article
            v-for="item in visibleUrgentCertificates"
            :key="item.id"
            class="glass-panel rounded-2xl bg-sky-50/40 p-4"
          >
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
              <div>
                <p class="text-sm font-semibold text-slate-900">
                  {{ item.site_name }} · {{ item.domain }}
                </p>
                <p class="mt-1 text-xs text-slate-600">{{ formatDate(item.valid_until) }}</p>
              </div>
              <div class="text-sm text-slate-700">
                <span
                  class="glass-badge rounded-full px-3 py-1 text-xs font-semibold"
                  :class="
                    item.days_remaining <= 15
                      ? 'bg-rose-500/15 text-rose-700'
                      : item.days_remaining <= 30
                        ? 'bg-amber-500/15 text-amber-700'
                        : 'bg-emerald-500/15 text-emerald-700'
                  "
                >
                  Quedan {{ item.days_remaining }} días
                </span>
              </div>
            </div>
          </article>

          <button
            v-if="urgentCertificatesHiddenCount > 0"
            type="button"
            class="inline-flex items-center gap-2 rounded-full border border-sky-300 px-4 py-2 text-xs font-semibold text-slate-800 transition hover:border-cyan-400 hover:text-cyan-800"
            @click="showAllUrgentCertificates = !showAllUrgentCertificates"
          >
            <span v-if="!showAllUrgentCertificates"
              >Ver más (+{{ urgentCertificatesHiddenCount }})</span
            >
            <span v-else>Ver menos</span>
          </button>
        </div>
      </section>

      <section class="glass-panel mt-8 rounded-3xl bg-white/50 p-5">
        <header class="flex flex-col gap-5 border-b border-sky-200 pb-5">
          <div class="text-center sm:text-left">
            <h2 class="text-xl font-semibold text-slate-900">Sitios monitoreados</h2>
            <p class="mt-1 text-sm text-slate-600">
              Un clic abre el detalle, y el dominio abre el sitio en otra pestaña.
            </p>
          </div>

          <form class="flex flex-col gap-3" @submit.prevent="applySearch">
            <div class="flex flex-col gap-3 sm:flex-row">
              <label class="sr-only" for="dashboard-search">Buscar sitio o dominio</label>
              <input
                id="dashboard-search"
                v-model="localSearch"
                list="monitoring-site-suggestions"
                type="search"
                autocomplete="off"
                :disabled="isSnapshotFrozen"
                placeholder="Buscar sitio o dominio"
                class="glass-input h-11 rounded-xl px-4 text-sm text-slate-900 placeholder:text-slate-500 focus:border-cyan-400 focus:outline-none sm:flex-1"
              />
              <datalist id="monitoring-site-suggestions">
                <option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion" />
              </datalist>
              <label class="sr-only" for="dashboard-cms-filter">Filtrar por CMS</label>
              <select
                  id="dashboard-cms-filter"
                  v-model="localCms"
                  @change="applySearch"
                  :disabled="isSnapshotFrozen"
                  class="glass-input h-11 rounded-xl px-3 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none sm:w-56"
                  >
                  <option value="all">CMS: Todos</option>
                  <option value="drupal">Drupal (cualquiera)</option>
                  <option value="drupal-6">Drupal 6</option>
                  <option value="drupal-7">Drupal 7</option>
                  <option value="drupal-8">Drupal 8</option>
                  <option value="drupal-9">Drupal 9</option>
                  <option value="drupal-10">Drupal 10</option>
                  <option value="drupal-11">Drupal 11</option>

                  <option value="wordpress">WordPress</option>
                  <option value="joomla">Joomla</option>
                  <option value="moodle">Moodle</option>
                  <option value="typo3">TYPO3</option>
                  <option value="sharepoint">SharePoint</option>
                  <option value="ghost">Ghost</option>
                  <option value="prestashop">PrestaShop</option>
                  <option value="magento">Magento</option>
                  <option value="laravel">Laravel</option>
                  <option value="wix">Wix</option>
                  <option value="php">PHP</option>
                  <option value="no-determinado">No determinado</option>
              </select>
            </div>

            <div class="flex flex-wrap items-center justify-center gap-2.5">
              <button
                v-if="canRunMassScan"
                type="button"
                class="glass-btn h-11 whitespace-nowrap rounded-xl border-amber-400/40 px-4 text-sm font-semibold text-amber-700 hover:border-amber-300"
                :disabled="isMassScanRunning"
                @click="scanAllSites"
              >
                {{ isMassScanRunning ? 'Escaneo en ejecución...' : 'Iniciar escaneo masivo' }}
              </button>
              <button
                v-if="canRunMassScan"
                type="button"
                class="glass-btn h-11 whitespace-nowrap rounded-xl border-emerald-400/40 px-4 text-sm font-semibold text-emerald-700 hover:border-emerald-300 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="isMassScanRunning || selectedSiteIds.length === 0"
                @click="scanSelectedSites"
              >
                Escanear seleccionados ({{ selectedSiteIds.length }})
              </button>
              <button
                type="submit"
                class="glass-btn h-11 whitespace-nowrap rounded-xl border-cyan-300/40 bg-cyan-400 px-4 text-sm font-semibold text-slate-50 hover:bg-cyan-300"
                :disabled="isSnapshotFrozen"
              >
                Buscar
              </button>
              <button
                type="button"
                class="glass-btn h-11 whitespace-nowrap rounded-xl border-sky-400/50 px-4 text-sm text-slate-800 hover:border-sky-600"
                :disabled="isSnapshotFrozen"
                @click="clearSearch"
              >
                Limpiar
              </button>
              <button
                v-if="canManageSites"
                type="button"
                class="glass-btn h-11 whitespace-nowrap rounded-xl border-cyan-400/50 px-4 text-sm font-semibold text-cyan-700 hover:border-cyan-300"
                @click="openAddSiteModal"
              >
                Agregar sitio
              </button>
            </div>
          </form>
        </header>

        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-sky-200 text-left text-sm">
            <thead>
              <tr class="text-slate-600">
                <th class="px-4 py-4 font-medium">
                  <input
                    type="checkbox"
                    class="h-4 w-4 rounded border-sky-400 bg-white text-cyan-400 focus:ring-cyan-500"
                    :checked="isAllVisibleSelected"
                    :indeterminate.prop="isSomeVisibleSelected && !isAllVisibleSelected"
                    @click.stop
                    @change="toggleSelectVisible"
                  />
                </th>
                <th class="px-4 py-4 font-medium">Sitio</th>
                <th class="px-4 py-4 font-medium">Dominio</th>
                <th class="max-w-[13rem] px-4 py-4 font-medium">Tecnología</th>
                <th class="px-4 py-4 font-medium">Certificado</th>
                <th class="min-w-[11.5rem] px-4 py-4 font-medium">Estatus</th>
                <th class="px-4 py-4 font-medium">Estado operativo</th>
                <th class="px-4 py-4 font-medium">Diagnóstico actual</th>
                <th class="px-4 py-4 font-medium">Señales técnicas</th>
                <th class="px-4 py-4 font-medium">Último check</th>
                <th class="px-4 py-4 font-medium">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-sky-200/90">
              <tr
                v-for="site in normalizedSites"
                :key="site.id"
                class="cursor-pointer transition hover:bg-sky-200/70 focus-within:bg-sky-200/70"
                tabindex="0"
                role="link"
                @click="openSiteDetail(site.id)"
                @keydown.enter.prevent="openSiteDetail(site.id)"
                @keydown.space.prevent="openSiteDetail(site.id)"
              >
                <td class="px-4 py-4" @click.stop>
                  <input
                    type="checkbox"
                    class="h-4 w-4 rounded border-sky-400 bg-white text-cyan-400 focus:ring-cyan-500"
                    :checked="isSiteSelected(site.id)"
                    @click.stop
                    @change="toggleSelectedSite(site.id)"
                  />
                </td>
                <td class="px-4 py-4 align-middle">
                  <p class="font-medium text-slate-900">{{ fallbackSiteName(site) }}</p>
                  <p class="mt-1 text-xs text-slate-500">Abrir detalle</p>
                </td>
                <td class="px-4 py-4 text-slate-700">
                  <a
                    :href="safeSiteUrl(site)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-cyan-600 hover:text-cyan-700"
                    @click.stop
                  >
                    {{ fallbackDomain(site.domain) }}
                  </a>
                </td>
                <td class="px-4 py-4 text-slate-700">
                  <span
                    class="glass-badge rounded-full bg-cyan-500/10 px-2.5 py-1 text-xs font-semibold text-cyan-700"
                    :class="technologyBadgeClass(site)"
                    :title="technologyTooltip(site)"
                  >
                    <span
                      v-if="isDrupalTechnology(site)"
                      class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-cyan-300/20 text-[10px] font-bold text-cyan-800"
                      >D</span
                    >
                    <span class="inline-block max-w-[180px] overflow-hidden text-ellipsis align-bottom">{{
                      technologyDisplayLabel(site)
                    }}</span>
                  </span>
                </td>
                <td class="px-4 py-4 text-slate-700">
                  <span
                    class="glass-badge rounded-full px-2.5 py-1 text-xs font-semibold"
                    :class="certificateBadgeClass(site)"
                  >
                    {{ certificateLabel(site) }}
                  </span>
                </td>
                <td class="px-4 py-4 text-slate-700" @click.stop>
                  <select
                    v-if="canManageSites"
                    :value="site.lifecycle_status || 'N/A'"
                    class="glass-input w-full max-w-[13rem] rounded-lg px-2 py-1.5 text-xs text-slate-900"
                    @click.stop
                    @keydown.enter.stop
                    @keydown.space.stop
                    @change="onLifecycleStatusChange(site, $event)"
                  >
                    <option
                      v-if="isCustomLifecycleStatus(site.lifecycle_status)"
                      :value="site.lifecycle_status"
                    >
                      {{ site.lifecycle_status }}
                    </option>
                    <option
                      v-for="statusOption in lifecycleStatuses"
                      :key="statusOption"
                      :value="statusOption"
                    >
                      {{ statusOption }}
                    </option>
                  </select>
                  <span
                    v-else
                    class="glass-badge rounded-full px-2.5 py-1 text-xs font-semibold"
                    :class="lifecycleBadgeClass(site.lifecycle_status)"
                  >
                    {{ site.lifecycle_status || 'N/A' }}
                  </span>
                  <p
                    v-if="site.lifecycle_status === 'Eliminado' && site.elimination_ticket"
                    class="mt-1 text-[11px] text-rose-700"
                  >
                    Ticket: {{ site.elimination_ticket }}
                  </p>
                </td>
                <td class="px-4 py-4">
                  <div class="flex flex-wrap items-center gap-2">
                    <span
                      class="glass-badge rounded-full px-3 py-1 text-xs font-semibold uppercase"
                      :class="statusBadgeClass(resolveStatusCode(site))"
                    >
                      {{ operationalStatusLabel(site) }}
                    </span>
                    <span
                      v-if="isSiteTelemetryPending(site)"
                      class="glass-badge rounded-full bg-cyan-400/10 px-2 py-1 text-[11px] font-medium text-cyan-700"
                    >
                      <span class="h-1.5 w-1.5 rounded-full bg-cyan-300 animate-pulse" />
                      Escaneo en proceso
                    </span>
                    <span
                      v-else-if="isSiteScanInterrupted(site)"
                      :title="'La ultima corrida se cancelo antes de re-inspeccionar este sitio. La tecnologia mostrada es la del ultimo escaneo completo.'"
                      class="glass-badge rounded-full bg-amber-400/10 px-2 py-1 text-[11px] font-medium text-amber-700"
                    >
                      Interrumpido
                    </span>
                  </div>
                </td>
                <td class="px-4 py-4 text-slate-700">{{ site.diagnostic_label || '-' }}</td>
                <td class="px-4 py-4 text-slate-700">{{ technicalSummary(site) }}</td>
                <td class="px-4 py-4 text-slate-600">
                  {{ formatCheckTime(site.last_checked_at, resolveStatusCode(site), site) }}
                  <p v-if="isSiteScanInterrupted(site)" class="text-[11px] text-amber-700">
                    Último intento interrumpido
                  </p>
                </td>
                <td class="px-4 py-4" @click.stop>
                  <div class="flex flex-wrap gap-2">
                    <button
                      v-if="canRunMassScan"
                      type="button"
                      class="rounded-lg border border-cyan-600/60 px-3 py-1.5 text-xs font-semibold text-cyan-700 hover:border-cyan-400"
                      @click="scanSingleSite(site.id)"
                    >
                      Reescanear
                    </button>
                    <button
                      v-if="canDeleteSites"
                      type="button"
                      class="rounded-lg border border-rose-600/60 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:border-rose-400"
                      @click="openDeleteSiteModal(site)"
                    >
                      Eliminar
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="normalizedSites.length === 0">
                <td colspan="11" class="px-4 py-12 text-center text-sm text-slate-600">
                  No hay sitios que coincidan con la búsqueda actual.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <footer class="mt-5 flex flex-col items-center gap-3 border-t border-sky-200 pt-5 sm:grid sm:grid-cols-3 sm:gap-2">
          <div class="text-center text-sm text-slate-600 sm:text-left">
            Mostrando {{ firstVisibleItem }}-{{ lastVisibleItem }} de {{ totalSites }} sitios.
          </div>

          <div class="flex items-center justify-center gap-3">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">
              Página {{ currentPage }} de {{ lastPage }}
            </p>
            <button
              type="button"
              class="h-10 rounded-xl border border-sky-300 px-4 text-sm text-slate-800 transition hover:border-sky-500 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="currentPage <= 1 || isSnapshotFrozen"
              @click="goToPage(currentPage - 1)"
            >
              Anterior
            </button>
            <button
              type="button"
              class="h-10 rounded-xl border border-sky-300 px-4 text-sm text-slate-800 transition hover:border-sky-500 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="currentPage >= lastPage || isSnapshotFrozen"
              @click="goToPage(currentPage + 1)"
            >
              Siguiente
            </button>
          </div>

          <div class="hidden sm:block" aria-hidden="true" />
        </footer>
      </section>

      <section class="mt-8 rounded-3xl border border-sky-200 bg-white/80 p-5">
        <header class="mb-4">
          <h2 class="text-xl font-semibold text-slate-900">Histórico de escaneos masivos</h2>
          <p class="mt-1 text-sm text-slate-600">
            Auditoría de quién lanzó cada ejecución, cuándo inició y cómo terminó.
          </p>
        </header>

        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-sky-200 text-left text-sm">
            <thead>
              <tr class="text-slate-600">
                <th class="px-4 py-3 font-medium">Inicio</th>
                <th class="px-4 py-3 font-medium">Usuario</th>
                <th class="px-4 py-3 font-medium">Modo</th>
                <th class="px-4 py-3 font-medium">Estado</th>
                <th class="px-4 py-3 font-medium">Avance</th>
                <th class="px-4 py-3 font-medium">Fin</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-sky-200/80">
              <tr v-for="run in visibleMassScanHistory" :key="run.run_id">
                <td class="px-4 py-3 text-slate-700">{{ formatDateTime(run.started_at) }}</td>
                <td class="px-4 py-3 text-slate-700">{{ run.initiated_by || 'Sistema' }}</td>
                <td class="px-4 py-3 text-slate-700">
                  {{ run.trigger_mode === 'manual' ? 'Manual' : 'Programado' }}
                </td>
                <td class="px-4 py-3">
                  <span
                    class="glass-badge rounded-full px-2.5 py-1 text-xs font-semibold"
                    :class="massScanStatusClass(run.status)"
                  >
                    {{ massScanStatusLabel(run.status) }}
                  </span>
                </td>
                <td class="px-4 py-3 text-slate-700">
                  {{ run.completed_tasks }}/{{ run.total_tasks }} · fallos {{ run.failed_tasks }}
                </td>
                <td class="px-4 py-3 text-slate-600">{{ formatDateTime(run.completed_at) }}</td>
              </tr>
              <tr v-if="massScanHistoryNormalized.length === 0">
                <td colspan="6" class="px-4 py-10 text-center text-sm text-slate-600">
                  Sin ejecuciones registradas todavía.
                </td>
              </tr>
            </tbody>
          </table>

          <div
            v-if="massScanHistoryNormalized.length > MASS_SCAN_HISTORY_VISIBLE_DEFAULT"
            class="mt-4 flex justify-end"
          >
            <button
              type="button"
              class="rounded-xl border border-sky-300 px-4 py-2 text-sm text-slate-800 transition hover:border-sky-500"
              @click="showAllMassScanHistory = !showAllMassScanHistory"
            >
              {{
                showAllMassScanHistory
                  ? 'Ver menos'
                  : `Ver más (+${massScanHistoryNormalized.length - MASS_SCAN_HISTORY_VISIBLE_DEFAULT})`
              }}
            </button>
          </div>
        </div>
      </section>

      <section
        v-if="canManageSites && isAddSiteModalOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-sky-50/60 px-4 backdrop-blur-sm"
        @click.self="closeAddSiteModal"
      >
        <div class="glass-panel w-full max-w-md rounded-2xl bg-white/70 p-5">
          <header class="mb-4">
            <h3 class="text-lg font-semibold text-slate-900">Registrar nuevo sitio</h3>
            <p class="mt-1 text-xs text-slate-600">
              Captura la URL del sitio y la clave operativa vigente para el alta manual.
            </p>
          </header>

          <form class="space-y-3" @submit.prevent="registerNewSite">
            <input
              v-model="newSiteUrl"
              type="url"
              required
              placeholder="https://nuevo-sitio.udg.mx"
              class="glass-input h-10 w-full rounded-xl px-3 text-xs text-slate-900 placeholder:text-slate-500 focus:border-cyan-400 focus:outline-none"
            />
            <div>
              <input
                v-model="newSiteKey"
                type="password"
                inputmode="numeric"
                autocomplete="off"
                required
                maxlength="10"
                pattern="\d{10}"
                placeholder="Clave: ddmmaaaaHH"
                class="glass-input h-10 w-full rounded-xl px-3 text-xs text-slate-900 placeholder:text-slate-500 focus:border-cyan-400 focus:outline-none"
              />
              <p class="mt-1 text-[11px] text-slate-500">
                10 dígitos: día, mes, año y hora (24h) del momento actual, hora Guadalajara.
              </p>
            </div>

            <p
              v-if="addSiteModalMessage"
              class="glass-panel rounded-lg bg-sky-50/50 px-3 py-2 text-xs text-slate-800"
            >
              {{ addSiteModalMessage }}
            </p>

            <div class="flex justify-end gap-2 pt-2">
              <button
                type="button"
                class="glass-btn h-10 whitespace-nowrap rounded-xl border-sky-400/50 px-4 text-xs text-slate-800 hover:border-sky-600"
                :disabled="isRegisteringSite"
                @click="closeAddSiteModal"
              >
                Cancelar
              </button>
              <button
                type="submit"
                class="glass-btn h-10 whitespace-nowrap rounded-xl border-cyan-400/50 px-4 text-xs font-semibold text-cyan-700 hover:border-cyan-300"
                :disabled="isRegisteringSite"
              >
                {{ isRegisteringSite ? 'Guardando...' : 'Guardar' }}
              </button>
            </div>
          </form>
        </div>
      </section>

      <section
        v-if="canDeleteSites && isDeleteSiteModalOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-sky-50/60 px-4 backdrop-blur-sm"
        @click.self="closeDeleteSiteModal"
      >
        <div class="glass-panel w-full max-w-md rounded-2xl bg-white/70 p-5">
          <header class="mb-4">
            <h3 class="text-lg font-semibold text-slate-900">Eliminar sitio</h3>
            <p class="mt-1 text-xs text-slate-600">
              Esta a punto de eliminar
              <span class="text-rose-600">{{ deleteSiteTarget?.name || deleteSiteTarget?.domain }}</span>
              del inventario. Puede restaurarse después solo por un administrador con acceso a la base de datos.
              Confirma con la clave operativa vigente.
            </p>
          </header>

          <form class="space-y-3" @submit.prevent="confirmDeleteSite">
            <div>
              <input
                v-model="deleteSiteKey"
                type="password"
                inputmode="numeric"
                autocomplete="off"
                required
                maxlength="10"
                pattern="\d{10}"
                placeholder="Clave: ddmmaaaaHH"
                class="glass-input h-10 w-full rounded-xl px-3 text-xs text-slate-900 placeholder:text-slate-500 focus:border-rose-400 focus:outline-none"
              />
              <p class="mt-1 text-[11px] text-slate-500">
                10 dígitos: día, mes, año y hora (24h) del momento actual, hora Guadalajara.
              </p>
            </div>

            <p
              v-if="deleteSiteModalMessage"
              class="glass-panel rounded-lg bg-sky-50/50 px-3 py-2 text-xs text-slate-800"
            >
              {{ deleteSiteModalMessage }}
            </p>

            <div class="flex justify-end gap-2 pt-2">
              <button
                type="button"
                class="glass-btn h-10 whitespace-nowrap rounded-xl border-sky-400/50 px-4 text-xs text-slate-800 hover:border-sky-600"
                :disabled="isDeletingSite"
                @click="closeDeleteSiteModal"
              >
                Cancelar
              </button>
              <button
                type="submit"
                class="glass-btn h-10 whitespace-nowrap rounded-xl border-rose-400/50 px-4 text-xs font-semibold text-rose-700 hover:border-rose-300"
                :disabled="isDeletingSite"
              >
                {{ isDeletingSite ? 'Eliminando...' : 'Eliminar' }}
              </button>
            </div>
          </form>
        </div>
      </section>

      <section
        v-if="isCustomStatusModalOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-sky-50/60 px-4 backdrop-blur-sm"
        @click.self="closeCustomStatusModal"
      >
        <div class="glass-panel w-full max-w-md rounded-2xl bg-white/70 p-5">
          <header class="mb-4">
            <h3 class="text-lg font-semibold text-slate-900">Estatus personalizado</h3>
            <p class="mt-1 text-xs text-slate-600">
              Describe el estatus real del sitio
              <span v-if="customStatusSite" class="text-slate-700">{{
                fallbackSiteName(customStatusSite)
              }}</span>
              .
            </p>
          </header>

          <form class="space-y-3" @submit.prevent="submitCustomStatus">
            <input
              v-model="customStatusText"
              type="text"
              required
              maxlength="120"
              placeholder="Ej. Migrando a AWS"
              class="glass-input h-10 w-full rounded-xl px-3 text-sm text-slate-900 placeholder:text-slate-500 focus:border-cyan-400 focus:outline-none"
              @keydown.escape="closeCustomStatusModal"
            />

            <p
              v-if="customStatusMessage"
              class="glass-panel rounded-lg bg-sky-50/50 px-3 py-2 text-xs text-slate-800"
            >
              {{ customStatusMessage }}
            </p>

            <div class="flex justify-end gap-2 pt-2">
              <button
                type="button"
                class="glass-btn h-10 whitespace-nowrap rounded-xl border-sky-400/50 px-4 text-xs text-slate-800 hover:border-sky-600"
                :disabled="isSavingCustomStatus"
                @click="closeCustomStatusModal"
              >
                Cancelar
              </button>
              <button
                type="submit"
                class="glass-btn h-10 whitespace-nowrap rounded-xl border-violet-400/50 px-4 text-xs font-semibold text-violet-700 hover:border-violet-300"
                :disabled="isSavingCustomStatus"
              >
                {{ isSavingCustomStatus ? 'Guardando...' : 'Guardar' }}
              </button>
            </div>
          </form>
        </div>
      </section>

      <AppFooter />
    </section>

    <CookieNotice />
  </main>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
  import { router, usePage } from '@inertiajs/vue3'
  import type { ApexOptions } from 'apexcharts'
  import VueApexCharts from 'vue3-apexcharts'
  import TopNav from '@/components/layout/TopNav.vue'
  import AppFooter from '@/components/layout/AppFooter.vue'
  import CookieNotice from '@/components/ui/CookieNotice.vue'

  const DEFAULT_REFRESH_INTERVAL_MS = 5000
  const DEFAULT_PER_PAGE = 50

  type StatusCounts = {
    UP: number
    DEGRADED: number
    DOWN: number
    UNKNOWN: number
  }

  type StatusCountPayload = Partial<
    Record<keyof StatusCounts | Lowercase<keyof StatusCounts>, number>
  >

  type SiteItem = {
    id: number
    name: string | null
    domain: string | null
    url?: string | null
    lifecycle_status?: string | null
    elimination_ticket?: string | null
    ssl_certificate?: {
      valid_until: string | null
      issuer: string | null
      days_remaining: number | null
      algorithm: string | null
      is_expired?: boolean
    } | null
    current_status: string
    current_status_code?: string
    display_status_code?: string
    last_checked_at: string | null
    scan_interrupted_at?: string | null
    diagnostic_bucket?: string | null
    diagnostic_label?: string | null
    diagnostic_reason?: string | null
    technology_name?: string | null
    technology_version?: string | null
    technology_label?: string | null
    technology_category_label?: string | null
    technology_confidence?: number | string | null
    technology_badge_state?: 'danger' | 'success' | null
    server_signature?: string | null
    runtime_name?: string | null
    runtime_version?: string | null
    risk_level?: string | null
    risk_score?: number | null
    http_status?: number | null
    https_status?: number | null
    response_time_ms?: number | null
    security_headers_grade?: string | null
  }

  type Paginated<T> = {
    data: T[]
    current_page?: number
    last_page?: number
    per_page?: number
    total?: number
  }

  type Filters = {
    search: string
    status?: string
    cms?: string
    lifecycle_status?: string
    php_version?: string
    expires_in_days?: number
  }

  type PipelineMetricsPayload = {
    queueDepth?: Record<string, number>
  }

  type DashboardProps = {
    filters?: Partial<Filters>
    statusCounts?: StatusCountPayload
    diagnosticBreakdown?: Partial<
      Record<
        | 'operativo'
        | 'respuesta_lenta'
        | 'responde_con_errores'
        | 'inestable'
        | 'no_responde'
        | 'sin_actualizar',
        number
      >
    >
    searchSuggestions?: string[]
    sites?: Paginated<SiteItem> | SiteItem[]
    massScanProgress?: MassScanProgressPayload | null
    massScanHistory?: MassScanHistoryItem[]
    preventiveExpirations?: PreventiveExpirationItem[]
    pipelineMetrics?: PipelineMetricsPayload
    lifecycleStatuses?: string[]
    scheduledScansEnabled?: boolean
    canManageSettings?: boolean
    canManageSites?: boolean
    refreshIntervalMs?: number
    updatedAt?: string
  }

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

  type MassScanHistoryItem = {
    run_id: string
    trigger_mode: 'manual' | 'scheduled' | string
    status: 'running' | 'completed_ok' | 'completed_with_errors' | 'incomplete' | string
    total_sites: number
    total_tasks: number
    completed_tasks: number
    failed_tasks: number
    started_at: string | null
    last_progress_at: string | null
    completed_at: string | null
    initiated_by: string | null
  }

  type PreventiveExpirationItem = {
    id: number
    site_name: string
    domain: string
    valid_until: string | null
    days_remaining: number
    issuer: string | null
    month_label: string | null
  }

  const props = defineProps<DashboardProps>()

  type DashboardSnapshot = {
    sites?: Paginated<SiteItem> | SiteItem[]
    statusCounts?: StatusCountPayload
    diagnosticBreakdown?: DashboardProps['diagnosticBreakdown']
    updatedAt?: string
  }

  const localSearch = ref(props.filters?.search ?? '')
  const localStatus = ref((props.filters?.status ?? 'all').toString())
  const localCms = ref((props.filters?.cms ?? 'all').toString())
  const actionMessage = ref('')
  const massScanProgress = ref<MassScanProgressPayload | null>(props.massScanProgress ?? null)
  const suggestionsState = ref<string[]>(
    Array.isArray(props.searchSuggestions) ? props.searchSuggestions : [],
  )
  const selectedSiteIdsSet = ref<Set<number>>(new Set())
  const suggestions = computed(() => suggestionsState.value)
  const canManageSettings = computed(() => Boolean(props.canManageSettings))
  const canManageSites = computed(() => Boolean(props.canManageSites))
  const sharedPermissions = computed(() => (usePage().props as { auth?: { permissions?: string[] } }).auth?.permissions ?? [])
  const canDeleteSites = computed(() => sharedPermissions.value.includes('monitoring.delete_sites'))
  const canRunMassScan = computed(() => sharedPermissions.value.includes('monitoring.run_mass_scan'))
  const lifecycleStatuses = computed(() =>
    Array.isArray(props.lifecycleStatuses) ? props.lifecycleStatuses : [],
  )

  const isCustomLifecycleStatus = (status?: string | null) => {
    const value = (status || 'N/A').toString()
    return value !== '' && !lifecycleStatuses.value.includes(value)
  }
  const hasAnnouncedActiveRun = ref(false)
  const isCancellingMassScan = ref(false)
  const dashboardSnapshot = ref<DashboardSnapshot | null>(null)
  const MASS_SCAN_HISTORY_VISIBLE_DEFAULT = 5
  const showAllMassScanHistory = ref(false)
  const isAddSiteModalOpen = ref(false)
  const newSiteUrl = ref('')
  const newSiteKey = ref('')
  const isRegisteringSite = ref(false)
  const addSiteModalMessage = ref('')

  const isDeleteSiteModalOpen = ref(false)
  const deleteSiteTarget = ref<SiteItem | null>(null)
  const deleteSiteKey = ref('')
  const isDeletingSite = ref(false)
  const deleteSiteModalMessage = ref('')

  const isCustomStatusModalOpen = ref(false)
  const customStatusSite = ref<SiteItem | null>(null)
  const customStatusText = ref('')
  const customStatusMessage = ref('')
  const isSavingCustomStatus = ref(false)

  const massScanHistoryNormalized = computed<MassScanHistoryItem[]>(() =>
    Array.isArray(props.massScanHistory) ? props.massScanHistory : [],
  )
  const visibleMassScanHistory = computed<MassScanHistoryItem[]>(() => {
    if (showAllMassScanHistory.value) {
      return massScanHistoryNormalized.value
    }

    return massScanHistoryNormalized.value.slice(0, MASS_SCAN_HISTORY_VISIBLE_DEFAULT)
  })
  const preventiveExpirationsNormalized = computed<PreventiveExpirationItem[]>(() =>
    Array.isArray(props.preventiveExpirations) ? props.preventiveExpirations : [],
  )
  const isExporting = ref(false)
  const showAllUrgentCertificates = ref(false)
  const preventiveExpirationsSorted = computed<PreventiveExpirationItem[]>(() => {
    return [...preventiveExpirationsNormalized.value].sort(
      (left, right) => Number(left.days_remaining) - Number(right.days_remaining),
    )
  })
  const visibleUrgentCertificates = computed<PreventiveExpirationItem[]>(() => {
    return showAllUrgentCertificates.value
      ? preventiveExpirationsSorted.value
      : preventiveExpirationsSorted.value.slice(0, 5)
  })
  const urgentCertificatesHiddenCount = computed(() =>
    Math.max(0, preventiveExpirationsSorted.value.length - 5),
  )
  const isMassScanRunning = computed(() => massScanProgress.value?.status === 'running')
  const isSnapshotFrozen = computed(() => isMassScanRunning.value)

  const clonePayload = <T,>(value: T): T => {
    if (value === undefined || value === null) {
      return value
    }

    return JSON.parse(JSON.stringify(value)) as T
  }

  const captureDashboardSnapshot = () => {
    dashboardSnapshot.value = {
      sites: clonePayload(props.sites),
      statusCounts: clonePayload(props.statusCounts),
      diagnosticBreakdown: clonePayload(props.diagnosticBreakdown),
      updatedAt: props.updatedAt,
    }
  }

  const clearDashboardSnapshot = () => {
    dashboardSnapshot.value = null
  }

  const effectiveSites = computed<Paginated<SiteItem> | SiteItem[] | undefined>(() => {
    if (isMassScanRunning.value && dashboardSnapshot.value?.sites !== undefined) {
      return dashboardSnapshot.value.sites
    }

    return props.sites
  })

  const effectiveStatusCounts = computed<StatusCountPayload | undefined>(() => {
    if (isMassScanRunning.value && dashboardSnapshot.value?.statusCounts !== undefined) {
      return dashboardSnapshot.value.statusCounts
    }

    return props.statusCounts
  })

  const effectiveUpdatedAt = computed<string | undefined>(() => {
    if (isMassScanRunning.value && dashboardSnapshot.value?.updatedAt !== undefined) {
      return dashboardSnapshot.value.updatedAt
    }

    return props.updatedAt
  })

  const showMassScanOverlay = computed(() => {
    if (!massScanProgress.value) {
      return false
    }

    return massScanProgress.value.status === 'running' || massScanProgress.value.remaining_tasks > 0
  })

  const massScanTotalTasks = computed(() => Number(massScanProgress.value?.total_tasks ?? 0))
  const massScanCompletedTasks = computed(() =>
    Number(massScanProgress.value?.completed_tasks ?? 0),
  )
  const massScanRemainingTasks = computed(() =>
    Number(massScanProgress.value?.remaining_tasks ?? 0),
  )
  const massScanProgressPct = computed(() => Number(massScanProgress.value?.progress_pct ?? 0))
  const massScanStartedAt = computed(() => {
    const value = massScanProgress.value?.started_at
    if (!value) {
      return 'Sin dato'
    }

    const parsed = new Date(value)
    return Number.isNaN(parsed.getTime()) ? 'Sin dato' : parsed.toLocaleString('es-MX')
  })

  const massScanStageRows = computed(() => {
    const stages = massScanProgress.value?.stages

    if (!stages) {
      return []
    }

    const labels: Record<string, string> = {
      inspection: 'Inspección integral por sitio',
      uptime: 'Disponibilidad',
      ssl: 'Certificado SSL',
      headers: 'Cabeceras de seguridad',
      technology: 'Tecnologías detectadas',
    }

    return Object.entries(stages).map(([key, stage]) => ({
      key,
      label: labels[key] ?? key,
      completed: Number(stage?.completed ?? 0),
      failed: Number(stage?.failed ?? 0),
      total: Number(stage?.total ?? 0),
      remaining: Number(stage?.remaining ?? 0),
      progressPct: Number(stage?.progress_pct ?? 0),
    }))
  })

  const normalizedStatusCounts = computed<StatusCounts>(() => ({
    UP: Number(effectiveStatusCounts.value?.UP ?? effectiveStatusCounts.value?.up ?? 0),
    DEGRADED: Number(
      effectiveStatusCounts.value?.DEGRADED ?? effectiveStatusCounts.value?.degraded ?? 0,
    ),
    DOWN: Number(effectiveStatusCounts.value?.DOWN ?? effectiveStatusCounts.value?.down ?? 0),
    UNKNOWN: Number(
      effectiveStatusCounts.value?.UNKNOWN ?? effectiveStatusCounts.value?.unknown ?? 0,
    ),
  }))

  const normalizedSites = computed<SiteItem[]>(() => {
    // 1. Obtenemos el arreglo sin importar si viene directo o dentro de .data
    const rawSites = Array.isArray(effectiveSites.value)
      ? effectiveSites.value
      : (effectiveSites.value?.data ?? [])

    // 2. Creamos una copia ([...rawSites]) para no mutar el estado reactivo original
    return [...rawSites].sort((a, b) => {
      // Busca la propiedad que contenga el texto del sitio (name, domain, url, etc.)
      const labelA = (a.name || a.domain || '').toLowerCase()
      const labelB = (b.name || b.domain || '').toLowerCase()

      // Ordena respetando caracteres del español (acentos, ñ, etc.)
      return labelA.localeCompare(labelB)
    })
  })

  const visibleSiteIds = computed(() => normalizedSites.value.map((site) => site.id))

  const selectedSiteIds = computed(() => Array.from(selectedSiteIdsSet.value))

  const isAllVisibleSelected = computed(() => {
    if (visibleSiteIds.value.length === 0) {
      return false
    }

    return visibleSiteIds.value.every((siteId) => selectedSiteIdsSet.value.has(siteId))
  })

  const isSomeVisibleSelected = computed(() =>
    visibleSiteIds.value.some((siteId) => selectedSiteIdsSet.value.has(siteId)),
  )

  const currentPage = computed(() =>
    Array.isArray(effectiveSites.value) ? 1 : (effectiveSites.value?.current_page ?? 1),
  )
  const lastPage = computed(() =>
    Array.isArray(effectiveSites.value) ? 1 : Math.max(1, effectiveSites.value?.last_page ?? 1),
  )
  const currentPerPage = computed(() =>
    Array.isArray(effectiveSites.value)
      ? normalizedSites.value.length
      : (effectiveSites.value?.per_page ?? DEFAULT_PER_PAGE),
  )
  const totalSites = computed(() =>
    Array.isArray(effectiveSites.value)
      ? normalizedSites.value.length
      : (effectiveSites.value?.total ?? normalizedSites.value.length),
  )

  const firstVisibleItem = computed(() => {
    if (totalSites.value === 0) {
      return 0
    }

    return (currentPage.value - 1) * currentPerPage.value + 1
  })

  const lastVisibleItem = computed(() => {
    if (totalSites.value === 0) {
      return 0
    }

    return Math.min(totalSites.value, firstVisibleItem.value + normalizedSites.value.length - 1)
  })

  const formattedUpdatedAt = computed(() => {
    if (!effectiveUpdatedAt.value) {
      return 'Sin dato'
    }

    const parsed = new Date(effectiveUpdatedAt.value)
    return Number.isNaN(parsed.getTime()) ? 'Sin dato' : parsed.toLocaleString('es-MX')
  })

  const resolveStatusCode = (site: SiteItem): 'up' | 'down' | 'degraded' | 'unknown' => {
    const diagnosisBucket = (site.diagnostic_bucket ?? '').toString().trim().toLowerCase()
    const diagnosisLabel = (site.diagnostic_label ?? '').toString().trim().toLowerCase()

    if (
      diagnosisBucket === 'sin_actualizar' ||
      diagnosisLabel === 'sin actualizar' ||
      diagnosisLabel === 'desconocido'
    ) {
      return 'unknown'
    }

    const displayStatus = (site.display_status_code ?? '').toString().trim().toLowerCase()

    if (
      displayStatus === 'up' ||
      displayStatus === 'down' ||
      displayStatus === 'degraded' ||
      displayStatus === 'unknown'
    ) {
      return displayStatus
    }

    const raw = (site.current_status_code ?? site.current_status ?? '')
      .toString()
      .trim()
      .toLowerCase()

    if (raw === 'up' || raw === 'activo') return 'up'
    if (raw === 'degraded' || raw === 'degradado') return 'degraded'
    if (raw === 'down' || raw === 'caído' || raw === 'caido') return 'down'

    return 'unknown'
  }

  const statusBadgeClass = (status: ReturnType<typeof resolveStatusCode>) => {
    if (status === 'up') return 'bg-emerald-500/15 text-emerald-600'
    if (status === 'degraded') return 'bg-amber-500/15 text-amber-600'
    if (status === 'down') return 'bg-rose-500/15 text-rose-600'
    return 'bg-amber-400/10 text-amber-700'
  }

  const statusLabel = (status: ReturnType<typeof resolveStatusCode>) => {
    if (status === 'up') return 'OPERATIVO'
    if (status === 'degraded') return 'CON INCIDENCIAS'
    if (status === 'down') return 'NO RESPONDE'
    return 'SIN ACTUALIZAR'
  }

  const operationalStatusLabel = (site: SiteItem) => {
    const bucket = (site.diagnostic_bucket ?? '').toString().trim().toLowerCase()

    if (bucket === 'inactivo') {
      return 'INACTIVO'
    }

    if (bucket === 'fuera_monitoreo') {
      return 'FUERA DE MONITOREO'
    }

    return statusLabel(resolveStatusCode(site))
  }

  const isSiteTelemetryPending = (site: SiteItem) => {
    if (!isMassScanRunning.value) {
      return false
    }

    const bucket = (site.diagnostic_bucket ?? '').toString().trim().toLowerCase()
    const label = (site.diagnostic_label ?? '').toString().trim().toLowerCase()

    return bucket === 'sin_actualizar' || label === 'sin actualizar' || label === 'desconocido'
  }

  const isSiteScanInterrupted = (site: SiteItem) => Boolean(site.scan_interrupted_at)

  const fallbackSiteName = (site: SiteItem) =>
    site.name?.trim() || site.domain?.trim() || `Sitio #${site.id}`

  const fallbackDomain = (domain: string | null) => domain?.trim() || 'Sin dominio'

  const isCertificateNotVerifiable = (site: SiteItem) => {
    const cert = site.ssl_certificate

    if (!cert) {
      return false
    }

    const status = resolveStatusCode(site)
    const isDownOrUnknown = status === 'down' || status === 'unknown'

    if (!isDownOrUnknown) {
      return false
    }

    const hasTelemetry = Boolean(cert.valid_until || cert.issuer || cert.algorithm)
    const notExpired = cert.days_remaining === null || cert.days_remaining >= 0
    const notFlaggedExpired = cert.is_expired !== true

    return hasTelemetry && notExpired && notFlaggedExpired
  }

  const certificateLabel = (site: SiteItem) => {
    const cert = site.ssl_certificate

    if (!cert) {
      return 'Sin certificado'
    }

    if (isCertificateNotVerifiable(site)) {
      return 'No verificable'
    }

    if (cert.days_remaining !== null) {
      if (cert.days_remaining < 0 || cert.is_expired) {
        return 'Expirado'
      }

      if (cert.days_remaining <= 30) {
        return `Vence en ${cert.days_remaining} días`
      }

      return 'Vigente'
    }

    if (cert.valid_until || cert.issuer || cert.algorithm) {
      return 'Vigente'
    }

    return 'Sin datos'
  }

  const certificateBadgeClass = (site: SiteItem) => {
    const cert = site.ssl_certificate

    if (!cert) {
      return 'bg-sky-300 text-slate-700'
    }

    if (isCertificateNotVerifiable(site)) {
      return 'bg-sky-400/35 text-slate-900'
    }

    if (cert.days_remaining !== null && (cert.days_remaining < 0 || cert.is_expired)) {
      return 'bg-rose-500/15 text-rose-700'
    }

    if (cert.days_remaining !== null && cert.days_remaining <= 30) {
      return 'bg-amber-500/15 text-amber-700'
    }

    return 'bg-emerald-500/15 text-emerald-700'
  }

//   const technologyDisplayLabel = (site: SiteItem) =>
//     (site.technology_label || site.technology_name || 'No identificada').trim() || 'No identificada'
  const technologyDisplayLabel = (site: SiteItem) => {
    // 1. Obtenemos el texto base original
    let label = (site.technology_label || site.technology_name || 'No identificada').trim();

    // 2. Si el texto contiene "Drupal X X" (donde X es el mismo número), elimina el segundo
    label = label.replace(/\b(Drupal\s+(\d+))\s+\2\b/i, '$1');

    return label || 'No identificada';
    };

  const isDrupalTechnology = (site: SiteItem) =>
    technologyDisplayLabel(site).toLowerCase().includes('drupal')

  const technologyBadgeClass = (site: SiteItem) => {
    if (isDrupalTechnology(site)) {
      return 'ring-1 ring-cyan-400/25'
    }

    return ''
  }

  const lifecycleBadgeClass = (status?: string | null) => {
    if (status === 'Eliminado') return 'bg-rose-700/50 text-rose-800'
    if (status === 'Solicitud de baja') return 'bg-rose-600/35 text-rose-800'
    if (status === 'Migración de CMS') return 'bg-orange-500/35 text-orange-800'
    if (status === '2da Etapa') return 'bg-amber-500/30 text-amber-800'
    if (status === 'Migrando') return 'bg-amber-400/25 text-amber-800'
    if (status === '1ra Etapa') return 'bg-sky-500/25 text-sky-800'
    if (status === 'Migrado') return 'bg-emerald-500/20 text-emerald-800'
    if (status === 'Migrado y publicado') return 'bg-emerald-600/35 text-emerald-900'
    if (status === 'Sistema') return 'bg-cyan-500/25 text-cyan-800'
    if (status === 'Otro') return 'bg-violet-500/25 text-violet-800'
    return 'bg-sky-300 text-slate-700'
  }

  const safeSiteUrl = (site: SiteItem) => {
    const candidate = site.url?.trim() || (site.domain ? `https://${site.domain}` : '')
    if (candidate === '') {
      return '#'
    }

    return candidate.startsWith('http://') || candidate.startsWith('https://')
      ? candidate
      : `https://${candidate}`
  }

  const buildDashboardQuery = (page = 1) => {
    const query: Record<string, string | number> = {
      page,
      per_page: currentPerPage.value || DEFAULT_PER_PAGE,
    }

    if (localSearch.value.trim() !== '') {
      query.search = localSearch.value.trim()
    }

    if (localStatus.value !== 'all') {
      query.status = localStatus.value
    }

    if (localCms.value !== 'all') {
      query.cms = localCms.value
    }

    return query
  }

  const setStatusFilter = (status: 'up' | 'degraded' | 'down' | 'unknown') => {
    if (isMassScanRunning.value) {
      actionMessage.value =
        'Snapshot estable activo. Espera a que termine la corrida para aplicar filtros.'
      return
    }

    localStatus.value = status
    router.get('/monitoring/dashboard', buildDashboardQuery(1), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    })
  }

  const refreshDashboard = () => {
    if (
      document.activeElement instanceof HTMLInputElement ||
      document.activeElement instanceof HTMLTextAreaElement
    ) {
      return
    }

    router.get('/monitoring/dashboard', buildDashboardQuery(currentPage.value), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      only: [
        'sites',
        'statusCounts',
        'diagnosticBreakdown',
        'pipelineMetrics',
        'massScanProgress',
        'massScanHistory',
        'scheduledScansEnabled',
        'updatedAt',
      ],
    })
  }

  const applySearch = () => {
    if (isMassScanRunning.value) {
      actionMessage.value = 'Snapshot estable activo. Espera a que termine la corrida para buscar.'
      return
    }

    router.get('/monitoring/dashboard', buildDashboardQuery(1), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    })
  }

  const clearSearch = () => {
    if (isMassScanRunning.value) {
      actionMessage.value =
        'Snapshot estable activo. Espera a que termine la corrida para limpiar filtros.'
      return
    }

    localSearch.value = ''
    localStatus.value = 'all'
    localCms.value = 'all'
    applySearch()
  }

  const searchByCms = () => {
    if (isSnapshotFrozen.value) {
        return
    }

    applySearch()
    }

  const goToPage = (page: number) => {
    if (isMassScanRunning.value) {
      actionMessage.value =
        'Snapshot estable activo. La paginación se habilita al finalizar la corrida.'
      return
    }

    if (page < 1 || page > lastPage.value) {
      return
    }

    router.get('/monitoring/dashboard', buildDashboardQuery(page), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      only: [
        'sites',
        'statusCounts',
        'diagnosticBreakdown',
        'pipelineMetrics',
        'massScanProgress',
        'massScanHistory',
        'scheduledScansEnabled',
        'updatedAt',
      ],
    })
  }

  const openSiteDetail = (siteId: number) => {
    router.visit(`/monitoring/sites/${siteId}/detail`)
  }

  const isSiteSelected = (siteId: number) => selectedSiteIdsSet.value.has(siteId)

  const toggleSelectedSite = (siteId: number) => {
    const next = new Set(selectedSiteIdsSet.value)

    if (next.has(siteId)) {
      next.delete(siteId)
    } else {
      next.add(siteId)
    }

    selectedSiteIdsSet.value = next
  }

  const toggleSelectVisible = () => {
    const next = new Set(selectedSiteIdsSet.value)

    if (isAllVisibleSelected.value) {
      for (const siteId of visibleSiteIds.value) {
        next.delete(siteId)
      }
    } else {
      for (const siteId of visibleSiteIds.value) {
        next.add(siteId)
      }
    }

    selectedSiteIdsSet.value = next
  }

  const scanSingleSite = (siteId: number) => {
    if (isMassScanRunning.value) {
      actionMessage.value = 'Ya existe un escaneo en curso. Espera a que finalice.'
      return
    }

    actionMessage.value = 'Iniciando escaneo del sitio seleccionado...'
    void startSingleSiteScanRequest(siteId)
  }

  const scanAllSites = () => {
    if (isMassScanRunning.value) {
      actionMessage.value =
        'Ya existe un escaneo masivo en curso. Espera a que termine para iniciar otro.'
      return
    }

    actionMessage.value = 'Validando disponibilidad para lanzar escaneo masivo...'
    void startMassScanRequest()
  }

  const scanSelectedSites = () => {
    if (selectedSiteIds.value.length === 0) {
      actionMessage.value = 'Selecciona al menos un sitio para escanear.'
      return
    }

    if (isMassScanRunning.value) {
      actionMessage.value = 'Ya existe un escaneo en curso. Espera a que finalice.'
      return
    }

    actionMessage.value = `Iniciando escaneo de ${selectedSiteIds.value.length} sitio(s) seleccionados...`
    void startSelectedScanRequest()
  }

  const exportDashboardPdf = async () => {
    isExporting.value = true

    try {
      const response = await fetch('/monitoring/dashboard/export-report', {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/pdf',
          'X-Requested-With': 'XMLHttpRequest',
        },
      })

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }

      const blob = await response.blob()
      const objectUrl = window.URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = objectUrl
      anchor.download = `UDG_Sentinel_Reporte_General_${new Date().toISOString().slice(0, 10).replaceAll('-', '_')}.pdf`
      anchor.click()
      window.URL.revokeObjectURL(objectUrl)
    } catch {
      actionMessage.value = 'No se pudo exportar el PDF en este momento.'
    } finally {
      isExporting.value = false
    }
  }

  const getCsrfToken = () => {
    const meta = document.querySelector('meta[name="csrf-token"]')
    return meta instanceof HTMLMetaElement ? meta.content || '' : ''
  }

  const startMassScanRequest = async () => {
    try {
      const response = await fetch('/monitoring/dashboard/scan-all', {
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
        actionMessage.value = 'La sesión expiró. Recarga la página para continuar.'
        return
      }

      if (!response.ok) {
        actionMessage.value = 'No se pudo iniciar el escaneo masivo. Intenta nuevamente.'
        return
      }

      const payload = (await response.json()) as {
        started?: boolean
        status?: string
        message?: string
        progress?: MassScanProgressPayload | null
        redirect_url?: string | null
      }

      if (payload.message) {
        actionMessage.value = payload.message
      }

      if (payload.progress) {
        massScanProgress.value = payload.progress
      }

      if (payload.started) {
        hasAnnouncedActiveRun.value = false
        if (typeof payload.redirect_url === 'string' && payload.redirect_url !== '') {
          router.visit(payload.redirect_url)
          return
        }

        void fetchMassScanProgress()
      } else if (payload.status === 'already_running' && payload.progress) {
        hasAnnouncedActiveRun.value = true
        actionMessage.value = 'Ya existe un escaneo en curso. Mostrando el progreso actual.'

        if (typeof payload.redirect_url === 'string' && payload.redirect_url !== '') {
          router.visit(payload.redirect_url)
          return
        }
      }

      refreshDashboard()
    } catch {
      actionMessage.value = 'No se pudo iniciar el escaneo masivo por un error de red.'
    }
  }

  const cancelMassScan = async () => {
    const runId = massScanProgress.value?.run_id

    if (!runId || isCancellingMassScan.value) {
      return
    }

    const confirmed = window.confirm(
      'Se cancelará el escaneo masivo en curso. Los sitios que aún no fueron re-inspeccionados conservarán su última tecnología detectada, marcados como "Interrumpido". ¿Continuar?',
    )

    if (!confirmed) {
      return
    }

    isCancellingMassScan.value = true

    try {
      const response = await fetch(`/monitoring/scans/${runId}/cancel`, {
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
        actionMessage.value = 'La sesión expiró. Recarga la página para continuar.'
        return
      }

      const payload = (await response.json()) as {
        cancelled?: boolean
        message?: string
        progress?: MassScanProgressPayload | null
      }

      if (payload.message) {
        actionMessage.value = payload.message
      }

      if (payload.progress) {
        massScanProgress.value = payload.progress
      }

      if (payload.cancelled) {
        hasAnnouncedActiveRun.value = false
        refreshDashboard()
      }
    } catch {
      actionMessage.value = 'No se pudo cancelar el escaneo por un error de red.'
    } finally {
      isCancellingMassScan.value = false
    }
  }

  const startSelectedScanRequest = async () => {
    try {
      const response = await fetch('/monitoring/dashboard/scan-selected', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ site_ids: selectedSiteIds.value }),
      })

      if (response.status === 419) {
        actionMessage.value = 'La sesión expiró. Recarga la página para continuar.'
        return
      }

      if (!response.ok) {
        actionMessage.value = 'No se pudo iniciar el escaneo de sitios seleccionados.'
        return
      }

      const payload = (await response.json()) as {
        started?: boolean
        status?: string
        message?: string
        progress?: MassScanProgressPayload | null
        redirect_url?: string | null
      }

      if (payload.message) {
        actionMessage.value = payload.message
      }

      if (payload.progress) {
        massScanProgress.value = payload.progress
      }

      if (payload.started || payload.status === 'already_running') {
        if (typeof payload.redirect_url === 'string' && payload.redirect_url !== '') {
          router.visit(payload.redirect_url)
          return
        }
      }
    } catch {
      actionMessage.value =
        'No se pudo iniciar el escaneo de sitios seleccionados por un error de red.'
    }
  }

  const startSingleSiteScanRequest = async (siteId: number) => {
    try {
      const response = await fetch(`/monitoring/sites/${siteId}/scan`, {
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
        actionMessage.value = 'La sesión expiró. Recarga la página para continuar.'
        return
      }

      if (!response.ok) {
        actionMessage.value = 'No se pudo iniciar el escaneo del sitio.'
        return
      }

      const payload = (await response.json()) as {
        started?: boolean
        status?: string
        message?: string
        progress?: MassScanProgressPayload | null
        redirect_url?: string | null
      }

      if (payload.message) {
        actionMessage.value = payload.message
      }

      if (payload.progress) {
        massScanProgress.value = payload.progress
      }

      if (payload.started || payload.status === 'already_running') {
        if (typeof payload.redirect_url === 'string' && payload.redirect_url !== '') {
          router.visit(payload.redirect_url)
          return
        }
      }
    } catch {
      actionMessage.value = 'No se pudo iniciar el escaneo del sitio por un error de red.'
    }
  }

  const fetchMassScanProgress = async () => {
    try {
      const response = await fetch('/monitoring/dashboard/scan-progress', {
        method: 'GET',
        headers: {
          Accept: 'application/json',
        },
        credentials: 'same-origin',
      })

      if (!response.ok) {
        return
      }

      const payload = (await response.json()) as {
        active?: boolean
        progress?: MassScanProgressPayload | null
      }

      if (!payload.progress) {
        const hadRun = massScanProgress.value !== null
        massScanProgress.value = null

        if (hadRun) {
          hasAnnouncedActiveRun.value = false
          refreshDashboard()
        }

        return
      }

      const previousStatus = massScanProgress.value?.status ?? null
      massScanProgress.value = payload.progress

      if (payload.progress.status === 'running' && !hasAnnouncedActiveRun.value) {
        actionMessage.value = 'Escaneo en ejecución. Redirigiendo a la página de progreso...'
        hasAnnouncedActiveRun.value = true

        if (payload.progress.run_id) {
          router.visit(`/monitoring/scans/${payload.progress.run_id}`)
          return
        }
      }

      if (payload.progress.status === 'completed_ok') {
        actionMessage.value =
          'Escaneo masivo completado correctamente. Los datos ya fueron revalidados.'
      } else if (payload.progress.status === 'completed_with_errors') {
        actionMessage.value =
          'Escaneo masivo completado con errores parciales. Revisa el histórico para detalles.'
      } else if (payload.progress.status === 'incomplete') {
        actionMessage.value = 'Escaneo masivo marcado como incompleto por inactividad del proceso.'
      } else if (payload.progress.status === 'cancelled') {
        actionMessage.value =
          'Escaneo masivo cancelado. Los sitios sin re-inspeccionar conservan su última tecnología detectada, marcados como interrumpidos.'
      }

      if (previousStatus === 'running' && payload.progress.status !== 'running') {
        hasAnnouncedActiveRun.value = false
        refreshDashboard()
      }
    } catch {
      // El dashboard sigue operativo aunque falle la consulta de progreso.
    }
  }

  const fetchSearchSuggestions = async (query: string) => {
    try {
      const params = new URLSearchParams()
      if (query.trim() !== '') {
        params.set('q', query.trim())
      }

      const response = await fetch(
        `/monitoring/dashboard/search-suggestions?${params.toString()}`,
        {
          method: 'GET',
          headers: {
            Accept: 'application/json',
          },
          credentials: 'same-origin',
        },
      )

      if (!response.ok) {
        return
      }

      const payload = (await response.json()) as { items?: string[] }
      suggestionsState.value = Array.isArray(payload.items) ? payload.items : []
    } catch {
      // El autocompletado es auxiliar; no bloquea flujo principal.
    }
  }

  const updateLifecycleStatus = async (site: SiteItem, status: string): Promise<boolean> => {
    let ticket: string | null = null

    if (status === 'Eliminado') {
      ticket = window.prompt('Ingresa el número de ticket para marcar como Eliminado:')
      if (!ticket || ticket.trim() === '') {
        actionMessage.value =
          'No se actualizó el estatus porque el ticket es obligatorio para Eliminado.'
        return false
      }
    }

    try {
      const response = await fetch(`/monitoring/sites/${site.id}/lifecycle-status`, {
        method: 'PATCH',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          lifecycle_status: status,
          elimination_ticket: ticket,
        }),
      })

      if (!response.ok) {
        actionMessage.value = 'No se pudo actualizar el estatus del sitio.'
        return false
      }

      const payload = (await response.json()) as { message?: string }
      site.lifecycle_status = status
      site.elimination_ticket = status === 'Eliminado' ? ticket : null
      actionMessage.value = payload.message ?? 'Estatus actualizado.'
      refreshDashboard()
      return true
    } catch {
      actionMessage.value = 'Error de red al actualizar estatus del sitio.'
      return false
    }
  }

  const onLifecycleStatusChange = async (site: SiteItem, event: Event) => {
    const target = event.target as HTMLSelectElement | null
    if (!target) {
      return
    }

    const previousStatus = (site.lifecycle_status || 'N/A').toString()
    const nextStatus = String(target.value)

    if (nextStatus === 'Otro') {
      // El select vuelve a su valor previo; el estatus real lo captura el modal.
      target.value = previousStatus
      openCustomStatusModal(site)
      return
    }

    const ok = await updateLifecycleStatus(site, nextStatus)
    if (!ok) {
      target.value = previousStatus
    }
  }

  const openCustomStatusModal = (site: SiteItem) => {
    customStatusSite.value = site
    customStatusText.value = isCustomLifecycleStatus(site.lifecycle_status)
      ? (site.lifecycle_status || '').toString()
      : ''
    customStatusMessage.value = ''
    isCustomStatusModalOpen.value = true
  }

  const closeCustomStatusModal = () => {
    isCustomStatusModalOpen.value = false
    customStatusSite.value = null
    customStatusText.value = ''
    customStatusMessage.value = ''
  }

  const submitCustomStatus = async () => {
    const site = customStatusSite.value
    if (!site || isSavingCustomStatus.value) {
      return
    }

    const text = customStatusText.value.trim()
    if (text === '') {
      customStatusMessage.value = 'Escribe el estatus personalizado.'
      return
    }

    isSavingCustomStatus.value = true
    const ok = await updateLifecycleStatus(site, text)
    isSavingCustomStatus.value = false

    if (ok) {
      closeCustomStatusModal()
      return
    }

    customStatusMessage.value = actionMessage.value || 'No se pudo guardar el estatus personalizado.'
  }

  const openAddSiteModal = () => {
    addSiteModalMessage.value = ''
    isAddSiteModalOpen.value = true
  }

  const closeAddSiteModal = () => {
    addSiteModalMessage.value = ''
    isAddSiteModalOpen.value = false
    newSiteUrl.value = ''
    newSiteKey.value = ''
  }

  const registerNewSite = async () => {
    const url = newSiteUrl.value.trim()
    const clave = newSiteKey.value.trim()

    if (url === '' || clave === '') {
      addSiteModalMessage.value = 'Ingresa la URL y la clave operativa para registrar un nuevo sitio.'
      return
    }

    if (!/^\d{10}$/.test(clave)) {
      addSiteModalMessage.value = 'La clave debe tener 10 dígitos (formato ddmmaaaaHH).'
      return
    }

    const isValidUrl = (() => {
      try {
        const parsed = new URL(url)
        return parsed.protocol === 'http:' || parsed.protocol === 'https:'
      } catch {
        return false
      }
    })()

    if (!isValidUrl) {
      addSiteModalMessage.value = 'La URL proporcionada no es válida.'
      return
    }

    if (isRegisteringSite.value) {
      return
    }

    isRegisteringSite.value = true
    addSiteModalMessage.value = 'Registrando sitio...'

    try {
      const response = await fetch('/monitoring/sites/register', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          url,
          clave,
        }),
      })

      let payload: { message?: string } = {}
      const rawBody = await response.text()
      if (rawBody.trim() !== '') {
        try {
          payload = JSON.parse(rawBody) as { message?: string }
        } catch {
          payload = {}
        }
      }

      if (!response.ok) {
        if (response.status === 419) {
          addSiteModalMessage.value = 'La sesión expiró. Recarga la página e inténtalo de nuevo.'
          return
        }

        if (response.status === 403) {
          addSiteModalMessage.value = payload.message ?? 'No tienes permisos para registrar sitios.'
          return
        }

        if (response.status === 422) {
          addSiteModalMessage.value =
            payload.message ?? 'Datos inválidos. Verifica la URL y la clave.'
          return
        }

        addSiteModalMessage.value = payload.message ?? 'No fue posible registrar el sitio.'
        return
      }

      const successMessage = payload.message ?? 'Sitio registrado correctamente.'
      actionMessage.value = successMessage
      addSiteModalMessage.value = successMessage
      closeAddSiteModal()
      await refreshDashboard()
    } catch {
      addSiteModalMessage.value = 'Error de red al registrar el nuevo sitio.'
    } finally {
      isRegisteringSite.value = false
    }
  }

  const openDeleteSiteModal = (site: SiteItem) => {
    deleteSiteTarget.value = site
    deleteSiteKey.value = ''
    deleteSiteModalMessage.value = ''
    isDeleteSiteModalOpen.value = true
  }

  const closeDeleteSiteModal = () => {
    isDeleteSiteModalOpen.value = false
    deleteSiteTarget.value = null
    deleteSiteKey.value = ''
    deleteSiteModalMessage.value = ''
  }

  const confirmDeleteSite = async () => {
    const site = deleteSiteTarget.value
    const clave = deleteSiteKey.value.trim()

    if (!site) {
      return
    }

    if (!/^\d{10}$/.test(clave)) {
      deleteSiteModalMessage.value = 'La clave debe tener 10 dígitos (formato ddmmaaaaHH).'
      return
    }

    if (isDeletingSite.value) {
      return
    }

    isDeletingSite.value = true
    deleteSiteModalMessage.value = 'Eliminando sitio...'

    try {
      const response = await fetch(`/monitoring/sites/${site.id}`, {
        method: 'DELETE',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ clave }),
      })

      if (response.redirected) {
        deleteSiteModalMessage.value = 'Tu sesión ya no es válida. Recarga la página e inicia sesión de nuevo.'
        return
      }

      let payload: { message?: string } = {}
      const rawBody = await response.text()
      if (rawBody.trim() !== '') {
        try {
          payload = JSON.parse(rawBody) as { message?: string }
        } catch {
          payload = {}
        }
      }

      if (!response.ok) {
        if (response.status === 419) {
          deleteSiteModalMessage.value = 'La sesión expiró. Recarga la página e inténtalo de nuevo.'
          return
        }

        if (response.status === 403) {
          deleteSiteModalMessage.value = payload.message ?? 'No tienes permisos para eliminar sitios.'
          return
        }

        if (response.status === 422) {
          deleteSiteModalMessage.value = payload.message ?? 'Clave inválida o expirada.'
          return
        }

        deleteSiteModalMessage.value = payload.message ?? 'No fue posible eliminar el sitio.'
        return
      }

      actionMessage.value = 'Sitio eliminado correctamente.'
      closeDeleteSiteModal()
      await refreshDashboard()
    } catch {
      deleteSiteModalMessage.value = 'Error de red al eliminar el sitio.'
    } finally {
      isDeletingSite.value = false
    }
  }

  const handleAdminHotkeys = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && isAddSiteModalOpen.value) {
      closeAddSiteModal()
      return
    }

    if (event.key === 'Escape' && isCustomStatusModalOpen.value) {
      closeCustomStatusModal()
      return
    }

    if (event.key === 'Escape' && isDeleteSiteModalOpen.value) {
      closeDeleteSiteModal()
      return
    }

    if (!canManageSettings.value) {
      return
    }

    if (event.ctrlKey && event.shiftKey && (event.key === 'M' || event.key === 'm')) {
      event.preventDefault()
      router.visit('/monitoring/dashboard/maintenance')
    }
  }

  const formatDateTime = (value: string | null) => {
    if (!value) {
      return '-'
    }

    const parsed = new Date(value)
    return Number.isNaN(parsed.getTime()) ? '-' : parsed.toLocaleString('es-MX')
  }

  const formatDate = (value: string | null) => formatDateTime(value)

  const massScanStatusLabel = (status: string) => {
    if (status === 'running') return 'En ejecución'
    if (status === 'completed_ok') return 'Finalizado OK'
    if (status === 'completed_with_errors') return 'Finalizado con errores'
    if (status === 'incomplete') return 'Incompleto'
    if (status === 'cancelled') return 'Cancelado'
    return 'Desconocido'
  }

  const massScanStatusClass = (status: string) => {
    if (status === 'running') return 'bg-cyan-500/15 text-cyan-700'
    if (status === 'completed_ok') return 'bg-emerald-500/15 text-emerald-700'
    if (status === 'completed_with_errors') return 'bg-amber-500/15 text-amber-700'
    if (status === 'incomplete') return 'bg-rose-500/15 text-rose-700'
    if (status === 'cancelled') return 'bg-slate-400/20 text-slate-700'
    return 'bg-sky-300 text-slate-800'
  }

  const formatCheckTime = (
    value: string | null,
    status: ReturnType<typeof resolveStatusCode>,
    site?: SiteItem,
  ) => {
    if (!value) {
      return site && isSiteTelemetryPending(site)
        ? 'Escaneo en proceso...'
        : status === 'unknown'
          ? 'Sin datos'
          : 'Nunca'
    }

    const parsed = new Date(value)
    return Number.isNaN(parsed.getTime()) ? 'Nunca' : parsed.toLocaleString('es-MX')
  }

  const technologyTooltip = (site: SiteItem) => {
    const category = site.technology_category_label?.trim() || 'Sin categoria'
    const confidenceValue = site.technology_confidence
    const confidence =
      confidenceValue !== null && confidenceValue !== undefined
        ? typeof confidenceValue === 'number'
          ? `${confidenceValue}%`
          : confidenceValue
        : 'Sin confianza'
    const version = site.technology_version?.trim() || 'Sin versión'

    return `${technologyDisplayLabel(site)} · ${category} · ${confidence} · ${version}`
  }

  // "No determinado" es el valor centinela que manda el backend cuando no
  // hay evidencia suficiente — no es un dato real que valga la pena mostrar
  // (y menos concatenado dos veces, ej. nombre+version ambos "No determinado").
  const isKnownValue = (value?: string | null): value is string =>
    Boolean(value && value.trim() !== '' && value.trim() !== 'No determinado')

  const technicalSummary = (site: SiteItem) => {
    const http = site.http_status ? `HTTP ${site.http_status}` : null
    const https = site.https_status ? `HTTPS ${site.https_status}` : null
    const ssl = certificateLabel(site)
    const headers = site.security_headers_grade ? `Headers ${site.security_headers_grade}` : null
    const runtime = isKnownValue(site.runtime_name)
      ? `${site.runtime_name}${isKnownValue(site.runtime_version) ? ` ${site.runtime_version}` : ''}`
      : null
    const risk = isKnownValue(site.risk_level)
      ? `Riesgo ${site.risk_level}${site.risk_score !== null && site.risk_score !== undefined ? ` (${site.risk_score})` : ''}`
      : null
    const latency =
      site.response_time_ms !== null && site.response_time_ms !== undefined
        ? `${site.response_time_ms} ms`
        : null

    const parts = [http, https, `SSL ${ssl}`, headers, runtime, risk, latency].filter(
      (value): value is string => Boolean(value && value.trim() !== ''),
    )

    if (parts.length > 0) {
      return parts.join(' · ')
    }

    return site.diagnostic_reason || '-'
  }

  const statusPieSeries = computed(() => [
    normalizedStatusCounts.value.UP,
    normalizedStatusCounts.value.DEGRADED,
    normalizedStatusCounts.value.DOWN,
    normalizedStatusCounts.value.UNKNOWN,
  ])

  const statusPieOptions = computed<ApexOptions>(() => ({
    labels: ['Operativos', 'Con incidencias', 'No responde', 'Sin actualizar'],
    legend: {
      position: 'bottom',
      labels: { colors: '#334155' },
    },
    colors: ['#34D399', '#F59E0B', '#F43F5E', '#64748B'],
    chart: {
      toolbar: { show: false },
      foreColor: '#334155',
    },
    dataLabels: {
      style: { colors: ['#0f172a'] },
      dropShadow: { enabled: false },
    },
    tooltip: { theme: 'light' },
  }))

  // ── Latencia en vivo ──────────────────────────────────────────
  // Serie independiente del resto del dashboard: se sondea sola cada 8s
  // via /monitoring/dashboard/latency-timeseries (no espera al refresh
  // completo de la pagina), para que la grafica se sienta realmente viva.
  type LatencyPoint = { at: string; avg_ms: number; samples: number }
  const latencyPoints = ref<LatencyPoint[]>([])
  let latencyPollingInterval: ReturnType<typeof setInterval> | null = null

  const latencySeries = computed(() => [
    {
      name: 'Latencia promedio',
      data: latencyPoints.value.map((point) => ({
        x: new Date(point.at).getTime(),
        y: point.avg_ms,
      })),
    },
  ])

  const latencyChartOptions = computed<ApexOptions>(() => ({
    chart: {
      type: 'area',
      toolbar: { show: false },
      foreColor: '#334155',
      animations: { enabled: true, dynamicAnimation: { speed: 350 } },
    },
    colors: ['#0891b2'],
    stroke: { curve: 'smooth', width: 2.5 },
    fill: {
      type: 'gradient',
      gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.02, stops: [0, 90, 100] },
    },
    dataLabels: { enabled: false },
    xaxis: {
      type: 'datetime',
      labels: { style: { colors: '#64748B' }, datetimeUTC: false, format: 'HH:mm' },
    },
    yaxis: {
      labels: {
        style: { colors: '#64748B' },
        formatter: (value: number) => `${Math.round(value)} ms`,
      },
    },
    grid: { borderColor: 'rgba(100,116,139,0.2)' },
    tooltip: {
      theme: 'light',
      x: { format: 'HH:mm' },
      y: { formatter: (value: number) => `${value.toFixed(0)} ms` },
    },
    noData: { text: 'Esperando muestras de latencia...', style: { color: '#64748B' } },
  }))

  const fetchLatencyTimeseries = async () => {
    try {
      const response = await fetch('/monitoring/dashboard/latency-timeseries?minutes=30', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      })

      if (!response.ok) {
        return
      }

      const payload = (await response.json()) as { points?: LatencyPoint[] }
      latencyPoints.value = Array.isArray(payload.points) ? payload.points : []
    } catch {
      // Silencioso: un fallo puntual de sondeo no debe interrumpir el resto
      // del dashboard, el siguiente intento en 8s se autocorrige.
    }
  }

  type MonitoringEchoChannel = {
    listen: (event: string, callback: (payload: unknown) => void) => unknown
    stopListening: (name: string) => unknown
    unsubscribe: () => unknown
  }

  let channel: MonitoringEchoChannel | null = null
  let pollingInterval: ReturnType<typeof setInterval> | null = null
  let massScanPollingInterval: ReturnType<typeof setInterval> | null = null
  let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null

  watch(localSearch, (value, previousValue) => {
    if (value === previousValue) {
      return
    }

    if (searchDebounceTimer) {
      clearTimeout(searchDebounceTimer)
    }

    searchDebounceTimer = setTimeout(() => {
      void fetchSearchSuggestions(value)
    }, 300)
  })

  watch(normalizedSites, () => {
    const visible = new Set(normalizedSites.value.map((site) => site.id))
    const next = new Set<number>()

    for (const siteId of selectedSiteIdsSet.value) {
      if (visible.has(siteId)) {
        next.add(siteId)
      }
    }

    selectedSiteIdsSet.value = next
  })

  watch(isMassScanRunning, (running, previousRunning) => {
    if (running && !previousRunning) {
      captureDashboardSnapshot()
      return
    }

    if (!running && previousRunning) {
      clearDashboardSnapshot()
    }
  })

  onMounted(() => {
    if (isMassScanRunning.value) {
      captureDashboardSnapshot()
    }

    pollingInterval = setInterval(() => {
      if (!isMassScanRunning.value) {
        refreshDashboard()
      }
    }, 15000)
    massScanPollingInterval = setInterval(() => {
      void fetchMassScanProgress()
    }, 2500)

    void fetchMassScanProgress()
    void fetchSearchSuggestions(localSearch.value)

    void fetchLatencyTimeseries()
    latencyPollingInterval = setInterval(() => {
      void fetchLatencyTimeseries()
    }, 8000)

    const w = window as Window & {
      Echo?: {
        channel: (name: string) => MonitoringEchoChannel
      }
    }

    if (!w.Echo) {
      return
    }

    channel = w.Echo.channel('monitoring.sites')
    channel.listen('.site.status.changed', () => {
      if (!isMassScanRunning.value) {
        refreshDashboard()
      }
    })
  })

  onBeforeUnmount(() => {
    if (pollingInterval) {
      clearInterval(pollingInterval)
      pollingInterval = null
    }

    if (massScanPollingInterval) {
      clearInterval(massScanPollingInterval)
      massScanPollingInterval = null
    }

    if (latencyPollingInterval) {
      clearInterval(latencyPollingInterval)
      latencyPollingInterval = null
    }

    channel?.stopListening('.site.status.changed')
    channel?.unsubscribe()

    if (searchDebounceTimer) {
      clearTimeout(searchDebounceTimer)
      searchDebounceTimer = null
    }
  })
</script>
