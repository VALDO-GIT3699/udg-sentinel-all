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
        <h1 class="text-fluid-2xl font-semibold text-slate-900">Aviso de privacidad y tratamiento de datos</h1>
        <p class="mt-2 text-sm text-slate-600">Última actualización: {{ updatedAt }}</p>
      </header>

      <article class="glass-panel space-y-6 rounded-2xl bg-white/70 p-6 text-sm leading-relaxed text-slate-700 sm:p-8">
        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">1. Responsable del tratamiento</h2>
          <p>
            UDG Sentinel es una herramienta interna operada por la Coordinación General de Tecnologías
            Administrativas (CGTA) de la Universidad de Guadalajara, para el monitoreo técnico de los sitios
            web y activos digitales oficiales de la institución. No es un servicio público ni recopila datos
            de visitantes de los sitios monitoreados.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">2. Datos que se procesan</h2>
          <ul class="list-disc space-y-1 pl-5">
            <li>Datos de la cuenta de personal autorizado: nombre, usuario/correo institucional, departamento y rol de acceso.</li>
            <li>Registro de actividad (auditoría): acciones realizadas dentro del sistema, dirección IP de acceso y marca de tiempo, con fines de trazabilidad y seguridad.</li>
            <li>Metadatos técnicos de los sitios institucionales monitoreados (disponibilidad, certificados, tecnología detectada, alertas) — no se recopilan datos personales de terceros ni de visitantes externos.</li>
          </ul>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">3. Finalidad</h2>
          <p>
            Los datos se usan exclusivamente para operar el sistema de monitoreo: autenticar personal
            autorizado, controlar permisos por rol, mantener un registro de auditoría de cambios y acciones,
            y generar reportes operativos sobre el estado de la infraestructura web institucional.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">4. Conservación</h2>
          <p>
            El registro de auditoría se conserva mientras la cuenta institucional permanezca activa y durante
            el periodo adicional que determine la normatividad interna de la Universidad de Guadalajara para
            fines de trazabilidad. Las cuentas desactivadas no se eliminan de forma permanente de inmediato,
            para preservar la integridad del historial de auditoría asociado.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">5. Derechos del personal usuario</h2>
          <p>
            El personal con una cuenta en el sistema puede solicitar a su administrador de CGTA la
            rectificación de sus datos de perfil (nombre, departamento) o la desactivación de su cuenta.
            Las solicitudes se atienden a través de los canales internos de soporte de CGTA.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">6. Cookies</h2>
          <p>
            El sistema usa únicamente una cookie de sesión, necesaria para mantener la sesión iniciada. No se
            usan cookies de rastreo, publicidad ni analítica de terceros. Ver el
            <a href="/legal/terminos" class="text-cyan-600 hover:text-cyan-700">aviso de cookies</a> para más
            detalle.
          </p>
        </section>
      </article>
    </section>
  </main>
</template>

<script setup lang="ts">
const updatedAt = new Date().toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' })
</script>
