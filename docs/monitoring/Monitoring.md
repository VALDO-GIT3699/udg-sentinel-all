# Monitoreo Sentinel - Guia Operativa Local

## Objetivo
Esta guia define el arranque local del pipeline de monitoreo para verificar uptime, SSL, tecnologias activas, cabeceras de seguridad y alertas en tiempo real.

## Requisitos Previos
- PHP y Composer instalados.
- Node.js y npm instalados.
- MySQL disponible con base de datos `udg_sentinel`.
- Redis disponible para colas y cache.

## Variables Minimas
Configurar en `.env` (basado en `.env.example`):
- `DB_CONNECTION=mysql`
- `DB_HOST`, `DB_PORT=3306`, `DB_DATABASE=udg_sentinel`, `DB_USERNAME`, `DB_PASSWORD`
- `QUEUE_CONNECTION=redis`
- `REDIS_HOST`, `REDIS_PORT`
- `HORIZON_ENABLED=true`
- `SENTINEL_QUEUE_UPTIME=monitoring-uptime`
- `SENTINEL_QUEUE_SSL=monitoring-ssl`
- `SENTINEL_QUEUE_TECH=monitoring-tech`
- `SENTINEL_QUEUE_HEADERS=monitoring-headers`
- `SENTINEL_QUEUE_ALERTS=monitoring-alerts`

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

## Verificacion Rapida
1. Confirmar que Horizon muestra workers activos.
2. Crear o habilitar al menos un sitio oficial monitoreado.
3. Confirmar insercion de checks en tablas de monitoreo.
4. Verificar reflejo de estado en dashboard Inertia.

## Troubleshooting Basico
- Si no se procesan jobs:
  - Verificar `QUEUE_CONNECTION=redis` y conectividad a Redis.
  - Confirmar nombre de colas y worker escuchando esas colas.
- Si falla MySQL:
  - Revisar credenciales y existencia de `udg_sentinel`.
  - Ejecutar `php artisan config:clear` despues de cambios en `.env`.
- Si no actualiza el dashboard:
  - Confirmar que los eventos se emiten y que el canal en vivo esta configurado.
  - Usar fallback de polling para validar flujo de datos.

## Observabilidad Minima
- Revisar logs de Laravel en `storage/logs`.
- Revisar panel Horizon para throughput, fallas y reintentos.
- Registrar cambios de configuracion critica con Activitylog.
