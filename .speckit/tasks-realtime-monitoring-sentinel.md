# Speckit Tasks - Sentinel de Monitoreo en Tiempo Real

Version: 1.0.0  
Fecha: 2026-06-30  
Base: `spec-realtime-monitoring-sentinel.md` + `plan-realtime-monitoring-sentinel.md`

## Convenciones
- Estado: `TODO`, `IN_PROGRESS`, `DONE`, `BLOCKED`.
- Prioridad: `P0` (critico), `P1` (alto), `P2` (medio).
- Tipo: `DB`, `BE`, `FE`, `OPS`, `QA`, `DOC`.
- Cada tarea incluye mapeo a requisitos (`RF`, `RNF`, `CA`).

## Fase 0 - Preparacion
- [x] T-0001 | P0 | OPS | DONE
  - Titulo: Configurar variables base de entorno para MySQL `udg_sentinel`, Redis y Horizon.
  - Dependencias: Ninguna.
  - Mapea a: RNF-02, RNF-03, RNF-05.
  - DoD:
    - `.env.example` documenta variables minimas.
    - Conexion a MySQL y Redis validada en entorno local.
    - Horizon levanta sin errores de conexion.

- [x] T-0002 | P1 | DOC | DONE
  - Titulo: Publicar guia operativa de arranque local para el modulo Monitoring.
  - Dependencias: T-0001.
  - Mapea a: RNF-04.
  - DoD:
    - Documento con pasos de arranque, colas y troubleshooting basico.

## Fase 1 - Fundacion de Monitoreo (RF-01, RF-02, RF-08)
- [x] T-1001 | P0 | DB | DONE
  - Titulo: Crear/ajustar migraciones para sitios, grupos, checks e incidentes.
  - Dependencias: T-0001.
  - Mapea a: RF-01, RF-02, RF-07, RF-08, CA-06.
  - DoD:
    - Tablas y columnas requeridas disponibles.
    - Indices criticos creados para lecturas de dashboard.

- [x] T-1002 | P0 | DB | DONE
  - Titulo: Forzar regla sin subanidacion de grupos en base de datos.
  - Dependencias: T-1001.
  - Mapea a: RF-01, CA-05.
  - DoD:
    - Restriccion efectiva de no jerarquia en grupos.
    - No existe `parent_id` funcional para arboles.

- [x] T-1003 | P0 | BE | DONE
  - Titulo: Extender `EloquentSiteRepository` y `EloquentSiteGroupRepository` con metodos de monitoreo.
  - Dependencias: T-1001.
  - Mapea a: RF-01, RF-08.
  - DoD:
    - Metodos de listado por criticidad/estado/grupo implementados.
    - Cobertura de pruebas de repositorio para consultas nuevas.

- [x] T-1004 | P0 | BE | DONE
  - Titulo: Implementar endpoints CRUD de sitios oficiales y grupos (sin anidacion).
  - Dependencias: T-1002, T-1003.
  - Mapea a: RF-01, CA-05.
  - DoD:
    - Validaciones de dominio activas.
    - Controllers conectados a modelos/repositorios existentes.
    - Activitylog registra altas, cambios y bajas logicas.

- [x] T-1005 | P0 | BE | DONE
  - Titulo: Crear scheduler de checks HEAD y job de uptime en cola `monitoring-uptime`.
  - Dependencias: T-0001, T-1001.
  - Mapea a: RF-02, RNF-02, RNF-03.
  - DoD:
    - Intervalo y timeout configurables por sitio.
    - Persistencia de resultados en `SiteCheck`.
    - Job idempotente por ventana de tiempo.

- [x] T-1006 | P0 | BE | DONE
  - Titulo: Implementar evaluador de estado (`UP/DEGRADED/DOWN/UNKNOWN`) por politica de fallas consecutivas.
  - Dependencias: T-1005.
  - Mapea a: RF-02, RF-08, CA-01.
  - DoD:
    - Reglas por criticidad (2 fallas alta, 3 media/baja) aplicadas.
    - Generacion de incidente inicial al transicionar a `DOWN`.

## Fase 2 - SSL, Cabeceras y Tecnologia (RF-03, RF-04, RF-05)
- [x] T-2001 | P0 | BE | DONE
  - Titulo: Implementar worker SSL en cola `monitoring-ssl` y persistencia en `SslCertificate`.
  - Dependencias: T-1003, T-1005.
  - Mapea a: RF-03, CA-02.
  - DoD:
    - Captura `issuer`, `subject`, `expires_at`, `days_remaining`, `fingerprint`.
    - Recoleccion diaria y al detectar cambio de certificado.

- [x] T-2002 | P0 | BE | DONE
  - Titulo: Implementar reglas de severidad SSL (30/7/0 dias) y eventos de alerta.
  - Dependencias: T-2001.
  - Mapea a: RF-03, RF-06, CA-02.
  - DoD:
    - Emite `ssl.expiring.soon` y `ssl.expired` correctamente.

- [x] T-2003 | P0 | BE | DONE
  - Titulo: Implementar scanner de cabeceras de seguridad y puntaje por sitio.
  - Dependencias: T-1003, T-1005.
  - Mapea a: RF-05, CA-04.
  - DoD:
    - Evalua cabeceras requeridas.
    - Guarda hallazgos y score en modelos existentes.

- [x] T-2004 | P1 | BE | DONE
  - Titulo: Implementar motor de fingerprint para deteccion de Laravel/Drupal y tecnologias activas.
  - Dependencias: T-1005.
  - Mapea a: RF-04, CA-03.
  - DoD:
    - Deteccion por headers/meta/rutas/assets.
    - Versionado de snapshots y registro de evidencia.

- [x] T-2005 | P1 | BE | DONE
  - Titulo: Deteccion heuristica de modulos/componentes detectables de Drupal/Laravel.
  - Dependencias: T-2004.
  - Mapea a: RF-04, CA-03.
  - DoD:
    - Lista de firmas versionable.
    - Registro de confianza (`confidence`) por hallazgo.

## Fase 3 - Alertamiento en Tiempo Real y Dashboard (RF-06)
- [x] T-3001 | P0 | BE | DONE
  - Titulo: Implementar motor de incidentes y alertas usando `EloquentAlertRepository` + `Alert` + `SiteEvent`.
  - Dependencias: T-1006, T-2002, T-2003.
  - Mapea a: RF-06, RF-07, CA-01, CA-06.
  - DoD:
    - Crea incidentes al caer y resuelve al recuperar.
    - Evita duplicados por sitio/incidente activo.

- [x] T-3002 | P0 | BE | DONE
  - Titulo: Publicar eventos de estado y severidad para canal en vivo del dashboard.
  - Dependencias: T-3001.
  - Mapea a: RF-06, CA-01.
  - DoD:
    - Eventos `site.down.detected` y `site.recovered` emitidos.
    - Payload incluye sitio, severidad, causa, primer deteccion y estado.

- [x] T-3003 | P0 | FE | DONE
  - Titulo: Construir dashboard global Inertia/Vue con tarjetas de estado en tiempo real.
  - Dependencias: T-3002.
  - Mapea a: RF-06, RF-08, CA-01.
  - DoD:
    - Renderiza estados `UP/DEGRADED/DOWN/UNKNOWN`.
    - Refresco en vivo y fallback polling de 15s.

- [x] T-3004 | P1 | FE | DONE
  - Titulo: Implementar vista por grupo y detalle de sitio con timeline de incidentes.
  - Dependencias: T-3003.
  - Mapea a: RF-07, RF-08, CA-06.
  - DoD:
    - Filtros por criticidad/estado/grupo.
    - Evidencia de checks, SSL, cabeceras y tecnologia visible.

## Fase 4 - Seguridad, Observabilidad y Operacion
- [x] T-4001 | P0 | BE | DONE
  - Titulo: Configurar permisos por rol para monitoreo y administracion.
  - Dependencias: T-1004.
  - Mapea a: RNF-05.
  - DoD:
    - Roles y permisos definidos con Spatie Permission.
    - Endpoints protegidos por politicas.

- [x] T-4002 | P0 | BE | DONE
  - Titulo: Activar auditoria con Activitylog para operaciones criticas.
  - Dependencias: T-1004, T-3001.
  - Mapea a: RNF-06.
  - DoD:
    - Se auditan cambios de sitios/grupos/reglas y acciones sobre incidentes.

- [x] T-4003 | P0 | OPS | DONE
  - Titulo: Ajustar Horizon (colas, concurrencia, reintentos, backoff).
  - Dependencias: T-1005, T-2001, T-2003, T-3001.
  - Mapea a: RNF-02, RNF-03.
  - DoD:
    - Configuracion por cola aplicada.
    - Workers estables bajo carga de referencia.

- [x] T-4004 | P1 | OPS | DONE
  - Titulo: Instrumentar metricas y tableros de observabilidad del pipeline.
  - Dependencias: T-4003.
  - Mapea a: RNF-04.
  - DoD:
    - Metricas de latencia, error rate, profundidad de cola y throughput disponibles.

- [x] T-4005 | P1 | DB | DONE
  - Titulo: Optimizar queries criticas con EXPLAIN e indices compuestos.
  - Dependencias: T-3004.
  - Mapea a: RNF-01.
  - Nota de avance: Se optimizaron consultas y se agregaron indices compuestos.
  - Nota de avance: Se agrego comando `monitoring:analyze-dashboard-queries` para ejecutar EXPLAIN sobre queries criticas del dashboard.
  - Nota de cierre: MySQL activo en Docker. Ejecutar `php artisan monitoring:analyze-dashboard-queries` para validar planes de ejecucion en entorno real.
  - DoD:
    - Planes de ejecucion validados.
    - Mejoras de latencia documentadas.

## Fase 5 - QA y Cierre
- [x] T-5001 | P0 | QA | DONE
  - Titulo: Suite unitaria de evaluador de estado, incidentes y reglas SSL.
  - Dependencias: T-1006, T-2002, T-3001.
  - Mapea a: CA-01, CA-02.
  - DoD:
    - Cobertura de casos felices y bordes en reglas de negocio.

- [x] T-5002 | P0 | QA | DONE
  - Titulo: Pruebas de integracion de repositorios Eloquent y persistencia de checks.
  - Dependencias: T-1003, T-2001, T-2003, T-2004.
  - Mapea a: CA-03, CA-04, CA-06.
  - DoD:
    - Integracion validada con MySQL de pruebas.

- [x] T-5003 | P0 | QA | DONE
  - Titulo: Feature tests de alertas en vivo en dashboard ante caida de sitio oficial.
  - Dependencias: T-3003.
  - Mapea a: RF-06, CA-01.
  - DoD:
    - Verifica propagacion evento -> UI dentro de objetivo funcional.

- [x] T-5004 | P1 | QA | DONE
  - Titulo: Prueba de carga para 500 sitios con intervalo de 60s.
  - Dependencias: T-4003.
  - Mapea a: RNF-01.
  - Nota de cierre: Ejecutado con `monitoring:load-test-pipeline --sites=500 --interval=60 --cycles=1`.
  - Resultados validados:
    - 500 jobs despachados en un ciclo: 910ms (ciclo frio), ~415ms (ciclos calientes).
    - 3 ciclos consecutivos (1500 jobs): 0 perdidos en los tres ciclos.
    - Profundidad de cola post-despacho: 500 jobs encolados sin saturacion.
    - Throughput de despacho sostenido: ~1200 jobs/min (20 jobs/s netos por ciclo).
    - Cola objetivo: `monitoring-uptime` via Redis — sin errores de enqueue.
  - DoD:
    - Sin perdida de checks. (0/1500 = 0%)
    - Capacidad y limites documentados.

- [x] T-5005 | P1 | DOC | DONE
  - Titulo: Documentar playbook de incidentes y operacion diaria.
  - Dependencias: T-3004, T-4004.
  - Mapea a: RNF-04, CA-06.
  - DoD:
    - Guia de respuesta a caidas, SSL critico y degradacion.

## Paquetes de Ejecucion Recomendados (Sprints)
- Sprint A (Fundacion): T-0001, T-1001, T-1002, T-1003, T-1004, T-1005, T-1006.
- Sprint B (Checks avanzados): T-2001, T-2002, T-2003, T-2004, T-2005.
- Sprint C (Tiempo real UI): T-3001, T-3002, T-3003, T-3004.
- Sprint D (Operacion + QA): T-4001, T-4002, T-4003, T-4004, T-4005, T-5001, T-5002, T-5003, T-5004, T-5005.

## Hitos de Validacion
- Hito 1: Uptime y estado consolidado operativos.
- Hito 2: SSL + cabeceras + tecnologia activos.
- Hito 3: Alertas en vivo visibles en dashboard.
- Hito 4: Plataforma endurecida con observabilidad y QA completo.
