# Monitoreo Sentinel - Guia Operativa Local

## Objetivo

Esta guia define el arranque local del pipeline de monitoreo para verificar uptime, SSL, tecnologias activas, cabeceras de seguridad y alertas en tiempo real.

## Requisitos Previos

- PHP y Composer instalados.
- Node.js y npm instalados.
- PostgreSQL disponible con base de datos `udg_sentinel`.
- Redis disponible para colas y cache.

## Variables Minimas

Configurar en `.env` (basado en `.env.example`):

- `DB_CONNECTION=pgsql`
- `DB_HOST`, `DB_PORT=5432`, `DB_DATABASE=udg_sentinel`, `DB_USERNAME`, `DB_PASSWORD`
- `QUEUE_CONNECTION=redis`
- `REDIS_HOST`, `REDIS_PORT`
- `HORIZON_ENABLED=true`
- `SENTINEL_QUEUE_UPTIME=monitoring-uptime`
- `SENTINEL_QUEUE_SSL=monitoring-ssl`
- `SENTINEL_QUEUE_TECH=monitoring-tech`
- `SENTINEL_QUEUE_HEADERS=monitoring-headers`
- `SENTINEL_QUEUE_ALERTS=monitoring-alerts`
- `SENTINEL_QUEUE_ASSET_CLASSIFICATION=inventory-asset-classification`
- `SENTINEL_ASSET_MONITOR_ROUTER=true`

## Arranque Backend

1. Instalar dependencias:
   - `composer install`
2. Instalar dependencias frontend:
   - `npm install`
3. Generar llave de aplicacion (si no existe):
   - `php artisan key:generate`
4. Ejecutar migraciones:
   - `php artisan migrate`

## Arranque de Procesos

Levantar en terminales separadas:

1. Aplicacion Laravel:
   - `php artisan serve --host=127.0.0.1 --port=8080`
2. Worker de colas:
   - `php artisan queue:work redis --queue=monitoring-uptime,monitoring-ssl,monitoring-tech,monitoring-headers,monitoring-alerts,default`
3. Horizon:
   - `php artisan horizon`
4. Frontend:
   - `npm run dev`

## Monitoreo por estrategias (Asset Router)

- Comando principal: `php artisan monitoring:dispatch-asset-monitoring --limit=200`
- Estrategias actuales:
  - `website` / `web_application` -> uptime + headers + ssl + tech
  - `rest_api` / `graphql` / `soap_api` -> uptime + api contract + ssl
  - `mail_server` -> sondeo MX
- Fallback automático a estrategia website cuando no hay clasificación disponible.

## Centro de Operaciones y Analitica

- Dashboard operativo: `/monitoring/dashboard`
- Gobierno de activos: `/monitoring/assets/intelligence`
- Centro analitico ejecutivo: `/analytics/overview`
- Aprobacion rapida de clasificacion:
  - `POST /monitoring/sites/{site}/classification/approve`
  - Convierte clasificacion sugerida actual en manual bloqueada.

## Verificacion Rapida

1. Confirmar que Horizon muestra workers activos.
2. Crear o habilitar al menos un sitio oficial monitoreado.
3. Confirmar insercion de checks en tablas de monitoreo.
4. Verificar reflejo de estado en dashboard Inertia.

## Troubleshooting Basico

- Si no se procesan jobs:
  - Verificar `QUEUE_CONNECTION=redis` y conectividad a Redis.
  - Confirmar nombre de colas y worker escuchando esas colas.
- Si falla PostgreSQL:
  - Revisar credenciales y existencia de `udg_sentinel`.
  - Ejecutar `php artisan config:clear` despues de cambios en `.env`.
- Si no actualiza el dashboard:
  - Confirmar que los eventos se emiten y que el canal en vivo esta configurado.
  - Usar fallback de polling para validar flujo de datos.

## Observabilidad Minima

- Revisar logs de Laravel en `storage/logs`.
- Revisar panel Horizon para throughput, fallas y reintentos.
- Registrar cambios de configuracion critica con Activitylog.

## Cambios Operativos 2026-08 (Produccion)

- Baseline real oficial:
  - Fuente: `docs/right_sites/true_sites.csv`.
  - Importacion: `php artisan monitoring:import-official-baseline --source=docs/right_sites/true_sites.csv`.
  - Seeder habilitado en `DatabaseSeeder` mediante `OfficialBaselineSeeder`.
- Ejecucion manual como modo por defecto:
  - `monitoring.scheduled_scans_enabled` arranca desactivado.
  - El sistema opera en modo manual con botones del dashboard para escaneo masivo y seleccionados.
- Deteccion de desviacion de baseline (drift):
  - Si la baseline reporta CMS distinto al detectado en escaneo, se registra evento `monitoring.baseline_drift` para validacion humana.
- Estatus de ciclo de vida del sitio:
  - Nuevo campo por sitio: `lifecycle_status`.
  - Estados soportados: `1ra Etapa`, `2da Etapa`, `Migrando`, `Migrado`, `Eliminado`, `Solicitud de baja`, `Migrado y publicado`, `N/A`, `Migración de CMS`, `Sistema`, `Otro`.
  - Ticket obligatorio cuando el estatus es `Eliminado` (`elimination_ticket`).
- Comentarios operativos en detalle del sitio:
  - Estados: `Completada`, `Incompleta`, `Cancelada`.
  - Acciones: ocultar/mostrar, eliminar y cambiar estado.
- Auto-mantenimiento a un clic:
  - Endpoint dashboard: `POST /monitoring/dashboard/maintenance/run` (admin).
  - Comando CLI: `php artisan monitoring:maintenance-sweep --days=90 --with-cache`.
  - Atajo en dashboard para administradores: `Ctrl + Shift + M`.
- Limpieza de historial de escaneos:
  - Endpoint: `DELETE /monitoring/dashboard/scan-history`.
- Alta manual de nuevos sitios a monitorear:
  - Endpoint: `POST /monitoring/sites/register`.
  - Requiere clave operativa `CGTA`.
