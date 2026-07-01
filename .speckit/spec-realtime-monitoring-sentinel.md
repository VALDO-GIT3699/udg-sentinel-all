# Speckit Specification - Sentinel de Monitoreo en Tiempo Real

Version: 1.0.0  
Fecha: 2026-06-30  
Estado: Draft

## 1. Objetivo
Construir un sentinel de monitoreo en tiempo real para plataformas web UDG que permita:
- Verificar disponibilidad (uptime) mediante solicitudes HTTP `HEAD`.
- Registrar vencimiento de certificados SSL/TLS.
- Detectar tecnologías activas (por ejemplo CMS/framework y módulos detectables de Drupal/Laravel).
- Analizar encabezados de seguridad.
- Disparar alertas inmediatas en el dashboard cuando un sitio oficial esté caído.

Restricción obligatoria:
- No se permiten grupos de monitoreo sub-anidados (solo un nivel de agrupación).

## 2. Alcance
Incluye:
- Monitoreo continuo de sitios oficiales configurados.
- Pipeline de checks técnicos y evaluación de estado.
- Persistencia de resultados, evidencias y métricas.
- Motor de alertas en tiempo real y visualización en dashboard Inertia/Vue 3.

Excluye (fase actual):
- Escaneo de vulnerabilidades profundas (DAST completo).
- Ejecución de agentes en redes privadas sin acceso HTTP público.
- Auto-remediación de incidentes.

## 3. Actores
- Administrador de Plataforma: gestiona sitios, umbrales y canales de alerta.
- Analista SOC/Operaciones: atiende incidentes y seguimiento histórico.
- Stakeholder Institucional: consulta estado operativo de sitios oficiales.

## 4. Modelo de Agrupación (Regla Sin Anidación)
- Cada sitio pertenece opcionalmente a un único grupo de monitoreo de primer nivel.
- Un grupo no puede contener otros grupos.
- Un sitio no puede pertenecer a más de un grupo simultáneamente.
- El sistema debe rechazar cualquier intento de crear jerarquías tipo `grupo -> subgrupo`.

Reglas de validación:
- `monitoring_groups.parent_id` debe ser `NULL` siempre.
- Si existe API de grupos, no debe exponer operación de asignar padre.

## 5. Requisitos Funcionales
### RF-01: Registro de Sitios
- El sistema debe permitir alta/edición/baja lógica de sitios oficiales.
- Campos mínimos: nombre, URL canónica, criticidad (`alta`, `media`, `baja`), grupo, estado activo.

### RF-02: Verificación de Uptime por HEAD
- El sentinel ejecutará solicitudes `HEAD` por sitio en intervalo configurable (por defecto 60s).
- Timeout por check configurable (por defecto 8s).
- Se considera disponible si responde con código HTTP 2xx o 3xx dentro del timeout.
- Se considera caído si hay timeout, error de conexión, resolución DNS fallida o HTTP 5xx persistente según política.

Política sugerida de confirmación:
- Incidente crítico tras 2 fallas consecutivas en sitios de criticidad alta.
- Incidente alto tras 3 fallas consecutivas en criticidad media/baja.

### RF-03: Captura de Expiración SSL
- Para sitios HTTPS, el sistema extraerá:
  - fecha de expiración del certificado hoja,
  - emisor,
  - `subject` principal,
  - días restantes.
- Debe registrar al menos una lectura diaria y al cambio de certificado detectado.

Umbrales de alerta SSL:
- Aviso: <= 30 días.
- Crítico: <= 7 días.
- Expirado: < 0 días.

### RF-04: Detección de Tecnologías Activas
- El sistema detectará tecnologías por firma de:
  - encabezados HTTP (`Server`, `X-Powered-By`),
  - meta etiquetas,
  - patrones de rutas/assets públicos,
  - fingerprints de respuesta.
- Debe identificar como mínimo: Laravel, Drupal y componentes/módulos detectables por heurística no intrusiva.
- Los resultados deben versionarse por fecha para comparar cambios de stack.

### RF-05: Escaneo de Encabezados de Seguridad
- Revisar presencia/valor de encabezados mínimos:
  - `Strict-Transport-Security`
  - `Content-Security-Policy`
  - `X-Frame-Options`
  - `X-Content-Type-Options`
  - `Referrer-Policy`
  - `Permissions-Policy`
- Calcular puntaje de cumplimiento de cabeceras por sitio.
- Generar hallazgos por encabezado faltante o configuración débil.

### RF-06: Alertas Inmediatas en Dashboard
- Cuando un sitio oficial caiga, el dashboard debe reflejar alerta en tiempo real.
- Tiempo objetivo de propagación evento -> UI: <= 5 segundos.
- La alerta debe incluir: sitio, severidad, causa, primera detección y estado actual.

### RF-07: Historial y Evidencia
- Guardar historial de checks con sello de tiempo, latencia, resultado y error técnico.
- Retención configurable (por defecto 90 días de detalle; agregados de largo plazo opcionales).

### RF-08: Estado Consolidado
- Estado por sitio: `UP`, `DEGRADED`, `DOWN`, `UNKNOWN`.
- Estado por grupo (sin anidación): agregación directa de sus sitios.

## 6. Requisitos No Funcionales
- RNF-01 Rendimiento: soportar 500 sitios con intervalo de 60s sin pérdida de checks.
- RNF-02 Confiabilidad: tolerancia a fallos de worker con reintentos idempotentes.
- RNF-03 Escalabilidad: scheduler desacoplado de ejecutores de checks.
- RNF-04 Observabilidad: métricas de latencia, tasa de error y cola.
- RNF-05 Seguridad: secretos de canales de alerta fuera de código fuente.
- RNF-06 Auditoría: toda configuración crítica debe quedar auditada.

## 7. Arquitectura Propuesta (Laravel 12 + Inertia)
- Scheduler/Dispatcher: programa checks y distribuye jobs por cola.
- Check Workers:
  - Worker Uptime HEAD
  - Worker SSL
  - Worker Tech Fingerprint
  - Worker Security Headers
- Evaluation Engine: evalúa reglas, severidad y transición de estados.
- Alert Engine: publica eventos de incidente y recuperación.
- Dashboard Gateway: entrega estado y eventos en tiempo real a Inertia/Vue 3.

Patrones:
- Repository Pattern para acceso a datos.
- Servicios de aplicación para orquestación de casos de uso.
- Eventos de dominio para cambios de estado y notificación.

## 8. Modelo de Datos (Lógico)
Tablas principales sugeridas:
- `monitoring_groups` (sin jerarquía)
  - `id`, `name`, `description`, `is_active`, timestamps
  - restricción: `parent_id` no permitido
- `monitored_sites`
  - `id`, `group_id`, `name`, `url`, `is_official`, `criticality`, `is_active`, timestamps
- `uptime_checks`
  - `id`, `site_id`, `checked_at`, `http_status`, `latency_ms`, `is_up`, `error_code`, `error_message`
- `ssl_snapshots`
  - `id`, `site_id`, `checked_at`, `issuer`, `subject_cn`, `expires_at`, `days_remaining`, `fingerprint_sha256`
- `technology_snapshots`
  - `id`, `site_id`, `checked_at`, `technology`, `version_guess`, `confidence`, `evidence`
- `security_header_checks`
  - `id`, `site_id`, `checked_at`, `header_name`, `is_present`, `value`, `score`, `finding`
- `site_incidents`
  - `id`, `site_id`, `started_at`, `resolved_at`, `severity`, `reason`, `status`
- `alert_events`
  - `id`, `incident_id`, `event_type`, `sent_at`, `channel`, `delivery_status`, `payload_hash`

Índices mínimos:
- `uptime_checks(site_id, checked_at DESC)`
- `ssl_snapshots(site_id, checked_at DESC)`
- `site_incidents(site_id, status, started_at DESC)`
- `technology_snapshots(site_id, checked_at DESC)`
- `security_header_checks(site_id, checked_at DESC)`

## 9. Reglas de Estado y Alertamiento
- DOWN:
  - se activa por política de fallas consecutivas según criticidad.
- DEGRADED:
  - latencia por encima de umbral SLO o fallas intermitentes.
- UP:
  - recuperación tras N éxitos consecutivos (por defecto 2).

Eventos mínimos:
- `site.down.detected`
- `site.recovered`
- `ssl.expiring.soon`
- `ssl.expired`
- `security.headers.weak`
- `technology.stack.changed`

## 10. API/Contrato de Dashboard
Payload de tarjeta de sitio (tipado estricto):
- `siteId: string`
- `name: string`
- `url: string`
- `official: boolean`
- `criticality: 'alta' | 'media' | 'baja'`
- `status: 'UP' | 'DEGRADED' | 'DOWN' | 'UNKNOWN'`
- `lastCheckAt: string | null`
- `latencyMsP95: number | null`
- `sslDaysRemaining: number | null`
- `securityScore: number | null`
- `techSummary: string[]`
- `activeIncident: { id: string; severity: string; startedAt: string } | null`

Canal en tiempo real:
- Evento push por WebSocket/Broadcast cuando cambie estado o severidad.
- Fallback por polling corto (15s) si no hay canal en vivo.

## 11. Criterios de Aceptación
- CA-01: Un sitio que no responde `HEAD` dentro del timeout genera incidente según política y alerta visible en dashboard en <= 5s.
- CA-02: El sistema registra expiración SSL y genera alerta de aviso/crítica/expirada con umbrales definidos.
- CA-03: El dashboard muestra tecnologías detectadas y cambios de stack entre snapshots.
- CA-04: El escaneo de cabeceras reporta faltantes y puntaje por sitio.
- CA-05: No es posible crear subgrupos ni relaciones de grupo padre-hijo.
- CA-06: El historial permite auditar causa y línea de tiempo de incidentes.

## 12. Riesgos y Mitigaciones
- Falsos positivos por bloqueos WAF/CDN:
  - Mitigar con reintentos, backoff y allowlist de comportamiento esperado.
- Detección tecnológica incompleta:
  - Mitigar con motor de firmas versionable y evidencias guardadas.
- Sobrecarga por intervalos agresivos:
  - Mitigar con colas, particionado y rate limiting por dominio.

## 13. Entregables
- Módulo backend de monitoreo (jobs, servicios, repositorios, eventos).
- Esquema de base de datos y migraciones optimizadas.
- Dashboard Inertia/Vue 3 con alertas en vivo.
- Suite mínima de pruebas: unitarias, integración y smoke de checks.
- Documentación operativa y playbook de incidentes.

## 14. Fuera de Ambito Inmediato
- Integraciones SIEM avanzadas.
- Correlación con telemetría de infraestructura no web.
- Predicción de fallas mediante ML.
