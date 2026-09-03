<template>
  <main class="relative min-h-screen overflow-x-hidden bg-sky-50 text-slate-900">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden" aria-hidden="true">
      <div class="absolute -left-40 -top-48 h-[34rem] w-[34rem] rounded-full bg-sky-300/25 blur-[130px]" />
      <div class="absolute -right-32 top-1/4 h-[30rem] w-[30rem] rounded-full bg-blue-300/20 blur-[130px]" />
    </div>

    <section class="relative z-10 mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
      <a href="/" class="text-sm text-cyan-600 hover:text-cyan-700">&larr; Volver a UDG Sentinel</a>

      <header class="mt-4 mb-8">
        <p class="text-xs uppercase tracking-[0.22em] text-cyan-600">UDG Sentinel</p>
        <h1 class="text-fluid-2xl font-semibold text-slate-900">Términos de uso y aviso de cookies</h1>
        <p class="mt-2 text-sm text-slate-600">Última actualización: {{ updatedAt }}</p>
      </header>

      <article class="glass-panel space-y-6 rounded-2xl bg-white/70 p-6 text-sm leading-relaxed text-slate-700 sm:p-8">
        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">1. Uso previsto</h2>
          <p>
            UDG Sentinel es una herramienta de uso interno exclusivo para personal autorizado de la
            Coordinación General de Tecnologías Administrativas (CGTA) de la Universidad de Guadalajara, para
            el monitoreo técnico de los sitios web y activos digitales oficiales de la institución. El acceso
            está restringido mediante cuenta y contraseña individuales; queda prohibido compartir credenciales
            o usar el sistema fuera de las funciones asignadas al puesto.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">2. Responsabilidad de la cuenta</h2>
          <p>
            Cada persona usuaria es responsable de las acciones realizadas con su cuenta. Toda acción relevante
            (altas, bajas, cambios de permisos, escaneos, eliminación de sitios) queda registrada en el
            <a href="/monitoring/audit" class="text-cyan-600 hover:text-cyan-700">registro de auditoría</a> del
            sistema con la identidad de quien la ejecutó.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">3. Uso aceptable</h2>
          <ul class="list-disc space-y-1 pl-5">
            <li>No usar el sistema para fines ajenos al monitoreo de la infraestructura institucional.</li>
            <li>No intentar eludir los controles de permisos o acceder a funciones fuera del rol asignado.</li>
            <li>Reportar a CGTA cualquier comportamiento anómalo o posible incidente de seguridad detectado.</li>
          </ul>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">4. Aviso de cookies</h2>
          <p>
            El sistema utiliza únicamente una cookie técnica de sesión, indispensable para mantener la sesión
            de la persona usuaria autenticada. No se utilizan cookies de rastreo, publicidad ni analítica de
            terceros. Al usar el sistema con una cuenta institucional, se acepta el uso de esta cookie
            estrictamente necesaria para su funcionamiento.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">5. Cambios</h2>
          <p>
            CGTA puede actualizar estos términos y el aviso de privacidad cuando sea necesario. Los cambios
            relevantes se comunicarán al personal usuario por los canales internos habituales.
          </p>
        </section>
      </article>
    </section>
  </main>
</template>

<script setup lang="ts">
const updatedAt = new Date().toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' })
</script>
