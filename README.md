# UDG Sentinel

Monitoreo en tiempo real de la infraestructura web institucional de la Universidad de Guadalajara.
Sentinel vigila cientos de sitios oficiales (disponibilidad, certificados SSL, tecnología detectada,
cabeceras de seguridad) y centraliza esa información en un panel administrativo con control de acceso
granular y registro de auditoría.

Operado por la Coordinación General de Tecnologías Administrativas (CGTA).

## Qué hace

- **Monitoreo activo**: chequeos HTTP periódicos (disponibilidad, tiempo de respuesta, código de estado)
  sobre el inventario oficial de sitios `.udg.mx`.
- **Inspección técnica**: detección de CMS, runtime, frameworks y cabeceras de seguridad por fingerprinting
  HTTP — sin acceso al servidor del sitio monitoreado.
- **Certificados SSL**: vencimientos, emisor y alertas preventivas antes de que expiren.
- **Catálogo de activos**: clasificación automática de cada sitio por qué es (web, API, correo, VPN...) y
  para qué se usa (sistema escolar, LMS, centro universitario...).
- **Panel administrativo**: usuarios, roles y permisos granulares (Spatie Permission), con registro de
  auditoría de cada acción sensible (altas, bajas, cambios de permisos, escaneos).
- **Reportes**: exportación ejecutiva en PDF/CSV del estado del inventario.

## Arquitectura

Laravel 12 (PHP 8.2) + Inertia.js + Vue 3 + TypeScript + Tailwind CSS v4, organizado en módulos
(`nwidart/laravel-modules`) bajo `backend/Modules/`:

| Módulo | Responsabilidad |
|---|---|
| `Monitoring` | Núcleo: chequeos, inspección técnica, dashboard, escaneo masivo, gestión de sitios y usuarios |
| `Analytics` | Centro analítico ejecutivo (tendencias, cobertura, riesgo institucional) |
| `Dashboard` | Tablero ejecutivo consolidado |
| `Inventory` | Motor de clasificación automática de activos |
| `Reports` | Exportación de reportes ejecutivos |
| `Audit` | Visor del registro de auditoría (basado en `spatie/laravel-activitylog`) |
| `Notifications` | Reservado — ver "Pendientes" |

Infraestructura: PostgreSQL 16, Redis (cache + colas), Laravel Horizon (workers), Docker Compose
(`infrastructure/docker/`) para desarrollo y producción, Nginx con TLS y cabeceras de seguridad
endurecidas en producción (`infrastructure/nginx/`).

## Arranque rápido (Docker)

```bash
cd backend
cp .env.example .env
docker compose up -d
docker exec sentinel_app php artisan key:generate
docker exec sentinel_app php artisan migrate --seed
```

El panel queda disponible en `http://localhost:8080`. En entorno local, `/monitoring/local-autologin`
permite entrar sin formulario (gateado por IP de red privada — nunca disponible fuera de `APP_ENV=local`).

## Comandos operativos clave

```bash
# Disparar chequeos manualmente (normalmente corren por schedule)
php artisan monitoring:dispatch-asset-monitoring --limit=200

# Backup de base de datos (corre solo, diario, a las 02:30)
php artisan monitoring:backup-database --keep-days=14

# Clasificación de activos (Catálogo de activos)
php artisan inventory:dispatch-asset-classifications --limit=300

# Mantenimiento: poda de historicos, métricas, temporales (diario, 03:00)
php artisan monitoring:maintenance-sweep --days=90

# Calidad de código
vendor/bin/pint          # formato — autocorrige
vendor/bin/phpstan analyse --memory-limit=1G   # análisis estático (informativo, no bloqueante en CI)
```

## Permisos y roles

Tres roles (`monitoring-admin`, `monitoring-operator`, `monitoring-viewer`) sobre un set de permisos
granulares definidos en `Modules\Monitoring\Support\MonitoringPermissionMatrix` — única fuente de verdad.
Los permisos se pueden además personalizar por usuario individual desde **Administración → Nuevo usuario**,
encima del preset de su rol.

## Seguridad

- Cabeceras reforzadas (CSP sin `unsafe-inline`, HSTS, X-Frame-Options, Cross-Origin-Opener-Policy) tanto
  a nivel de aplicación (`App\Http\Middleware\SecurityHeaders`) como de Nginx en producción.
- Rate limiting por usuario en operaciones costosas (escaneo masivo, exportaciones).
- Registro de auditoría inmutable de toda acción administrativa.
- **Pendiente**: autenticación de dos factores para cuentas con permisos de administración — ver
  `.speckit/tasks-realtime-monitoring-sentinel.md` para el plan vigente.

## Documentación adicional

- `.speckit/` — especificación, plan y tareas del sistema de monitoreo en tiempo real.
- `AI_CONTEXT.md` — contratos y reglas de evolución del modelo de datos (`Site` como `Asset`).
- `backend/docs/monitoring/InventarioOficial.md` — fuente del inventario oficial de sitios.

## Estado del proyecto

CI (`php artisan test` + análisis estático informativo) y CD (build + publicación de imagen a GHCR) corren
en GitHub Actions en cada push. El despliegue a producción es manual sobre la imagen publicada — ver
comentarios en `.github/workflows/cd.yml`.
