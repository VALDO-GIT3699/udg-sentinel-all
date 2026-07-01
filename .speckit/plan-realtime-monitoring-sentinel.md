# Speckit Plan - Implementacion Tecnica Sentinel en Tiempo Real

Version: 1.0.0  
Fecha: 2026-06-30  
Estado: Ready

## 1. Contexto Tecnico Obligatorio
- Backend: Laravel 12.
- Base de datos: MySQL, schema `udg_sentinel`.
- Arquitectura modular: `nwidart/laravel-modules`.
- Autorizacion y auditoria: Spatie Permission + Spatie Activitylog.
- Colas y procesamiento asincrono: Redis + Laravel Horizon.
- Frontend: Vite + Vue 3 + Inertia.js + Tailwind CSS.

Regla de integracion solicitada:
- Los controladores deben conectarse directamente con los modelos Eloquent existentes en `backend/app/Models` y con las interfaces/abstracciones de repositorio bajo `backend/app/Repositories` (sin crear una capa adicional innecesaria para esta fase).

## 2. Objetivo del Plan
Entregar un sentinel de monitoreo en tiempo real para sitios oficiales UDG que cubra uptime por `HEAD`, SSL, tecnologias activas, cabeceras de seguridad y alertamiento inmediato en dashboard.

## 3. Alineacion con Codigo Existente
### 3.1 Modelos existentes a reutilizar
- Sitios y grupos: `Site`, `SiteGroup`, `SiteCheck`, `SiteEvent`.
- Seguridad/SSL: `SecurityHeader`, `SecurityScore`, `SslCertificate`.
- Tecnologia: `Technology`, `SiteTechnology`, `DrupalModule`, `CmsDetail`.
- Alertas y notificaciones: `Alert`, `AlertRule`, `NotificationChannel`, `NotificationSent`.

### 3.2 Repositorios existentes a reutilizar
- `EloquentSiteRepository`
- `EloquentSiteGroupRepository`
- `EloquentSiteCheckRepository`
- `EloquentAlertRepository`

Nota de implementacion:
- Si alguna operacion requerida no existe aun en estos repositorios, se ampliaran metodos en la misma clase/contrato antes de introducir nuevos repositorios.

## 4. Arquitectura de Implementacion
- Modulo principal: `backend/Modules/Monitoring`.
- Capa HTTP del modulo:
  - Controllers para dashboard, configuracion, estados y evidencias.
  - Requests para validacion de entrada.
- Capa de dominio/aplicacion:
  - Servicios de orquestacion de checks (uptime, ssl, tecnologia, headers).
  - Evaluador de estado e incidentes.
  - Publicador de eventos de alerta.
- Capa de datos:
  - Uso directo de modelos Eloquent y repositorios existentes en `app/Repositories`.
- Capa asincrona:
  - Jobs en cola Redis para ejecucion de checks.
  - Supervisado por Horizon.
- Capa frontend:
  - Paginas Inertia/Vue para tablero en vivo, filtros y detalle de incidentes.

## 5. Plan por Fases
### Fase 1 - Fundacion de Monitoreo
Entregables:
- Migraciones MySQL para tablas faltantes y ajustes de indices.
- Configuracion de modulo Monitoring.
- Endpoints base de administracion de sitios/grupos (sin subanidacion).
- Scheduler de checks `HEAD` y persistencia de `SiteCheck`.

Tareas clave:
- Forzar regla de grupos no anidados con validacion de dominio + constraint de datos.
- Definir intervalos por sitio y timeout por check.
- Crear politicas iniciales de estado (`UP`, `DEGRADED`, `DOWN`, `UNKNOWN`).

### Fase 2 - SSL, Headers y Tecnologia
Entregables:
- Captura de certificados y dias restantes en `SslCertificate`.
- Escaneo de cabeceras de seguridad en `SecurityHeader`/`SecurityScore`.
- Motor de fingerprint para tecnologias y modulos detectables.

Tareas clave:
- Heuristicas de deteccion no intrusiva por headers, meta y rutas.
- Reglas de severidad para SSL por umbral (30/7/0 dias).
- Puntaje de seguridad por presencia/calidad de cabeceras.

### Fase 3 - Alertas en Tiempo Real y Dashboard
Entregables:
- Motor de alertas con persistencia en `Alert` y `SiteEvent`.
- Emision en tiempo real al dashboard Inertia/Vue.
- Vistas de estado global, grupos y detalle de sitio.

Tareas clave:
- Propagacion de eventos de caida y recuperacion <= 5s objetivo.
- Mostrar causa tecnica, timestamps, criticidad y evidencia.
- Integrar canales de notificacion configurables.

### Fase 4 - Endurecimiento Operativo
Entregables:
- Horizon ajustado para concurrencia y colas de checks.
- Auditoria completa con Activitylog para cambios criticos.
- Pruebas de carga y tuning MySQL.

Tareas clave:
- Panel de salud de workers y tasa de fallo.
- Ajuste de indices segun EXPLAIN sobre queries criticas.
- Playbook operativo de incidentes.

## 6. Diseño de Integracion Controller -> Model/Repository
Patron de trabajo por endpoint:
1. Controller valida request.
2. Controller invoca repositorio Eloquent existente o modelo Eloquent cuando la operacion es directa y simple.
3. Operaciones multi-paso usan servicio de aplicacion que orquesta repositorios/modelos.
4. Se registra actividad con Spatie Activitylog.
5. Se devuelve respuesta Inertia o JSON tipada.

Ejemplos de uso esperado:
- Listado de sitios: `EloquentSiteRepository`.
- Persistencia de checks: `EloquentSiteCheckRepository` + `SiteCheck`.
- Generacion de alerta: `EloquentAlertRepository` + `Alert`.

## 7. Base de Datos MySQL (udg_sentinel)
Objetivos:
- Garantizar escrituras de checks de alta frecuencia.
- Consultas de dashboard por ultima lectura y estado sin full scan.

Indices minimos:
- `site_checks(site_id, checked_at DESC)`
- `alerts(site_id, status, created_at DESC)`
- `ssl_certificates(site_id, expires_at)`
- `security_scores(site_id, checked_at DESC)`
- `site_technologies(site_id, detected_at DESC)`

Politicas:
- Retencion de detalle configurable (recomendado 90 dias).
- Agregados para historicos largos.

## 8. Colas, Redis y Horizon
- Colas separadas por tipo de check:
  - `monitoring-uptime`
  - `monitoring-ssl`
  - `monitoring-tech`
  - `monitoring-headers`
  - `monitoring-alerts`
- Reintentos con backoff exponencial para errores transitorios.
- Jobs idempotentes por `(site_id, check_type, ventana_tiempo)`.

## 9. Frontend Inertia + Vue 3 + Tailwind
Pantallas:
- Dashboard global de salud.
- Vista por grupo (sin niveles anidados).
- Vista de detalle de sitio con timeline de incidentes.

Requisitos de UX:
- Actualizacion en vivo de estados.
- Filtros por criticidad, estado y grupo.
- Indicadores claros para sitios oficiales caidos.

## 10. Seguridad y Cumplimiento
- Permisos por rol (Spatie Permission) para administrar configuracion y ver reportes.
- Activitylog obligatorio para:
  - cambios en sitios/grupos,
  - cambios de reglas de alerta,
  - silenciamiento/ack de incidentes.

## 11. Testing y Calidad
Pruebas minimas:
- Unitarias de evaluacion de estado e incidentes.
- Integracion de repositorios Eloquent clave.
- Feature tests de endpoints de dashboard y alertas.
- Smoke tests de jobs y pipeline de cola.

Puertas de calidad:
- PHPStan, Pint, tests backend.
- Type checking y lint frontend.

## 12. Backlog Tecnico Inicial
- B1: Crear migraciones/indices faltantes para monitoreo.
- B2: Implementar scheduler y job `HEAD` con persistencia de checks.
- B3: Implementar evaluador de estado + incidentes.
- B4: Implementar scanner SSL y umbrales de alerta.
- B5: Implementar scanner de cabeceras de seguridad.
- B6: Implementar fingerprint de tecnologias activas.
- B7: Integrar alertas en tiempo real al dashboard Inertia.
- B8: Configurar permisos, activity logs y politicas de acceso.
- B9: Ajustar Horizon y colas por tipo de check.
- B10: Suite de pruebas y tuning de queries MySQL.

## 13. Criterios de Exito del Plan
- Se detecta y alerta caida de sitio oficial en tiempo objetivo.
- SSL, cabeceras y tecnologias se reflejan en dashboard con evidencia trazable.
- No se permite subanidacion de grupos en ninguna capa.
- El flujo completo opera sobre Laravel 12 + MySQL `udg_sentinel` + Redis/Horizon sin romper convenciones del proyecto.
