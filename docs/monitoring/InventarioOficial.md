# Inventario Oficial UDG

Este documento define la fuente maestra institucional que el sistema debe tomar como alcance funcional.

## Alcance
- Solo deben considerarse los sitios oficiales contenidos en el archivo markdown institucional entregado por el usuario.
- El inventario operativo no debe crecer por descubrimiento automático fuera de esa fuente.
- Los datos operativos vivos siguen viniendo de monitoreo en tiempo real, no del archivo fuente.

## Columnas esperadas de la fuente
- Clasificación
- Entidad
- Nombre del sitio
- Dominio
- Sitio activo
- CMS
- IP servidor
- Certificado de seguridad
- Estatus proyecto
- Comentarios

## Reglas de uso
- El archivo institucional se usa como lista canónica de URLs y metadatos iniciales.
- El dashboard debe mostrar el inventario con una forma visual parecida a esa fuente.
- La columna de certificado debe reflejar estado real del monitoreo SSL, no solo texto histórico.
- El sistema no debe duplicar ni mantener activos fuera del alcance oficial cuando se ejecute la sincronización canónica.

## Baseline CSV oficial (Fase A)
- El archivo `docs/right_sites/true_sites.csv` se utiliza **solo** para importación inicial de baseline.
- La baseline se persiste en tablas independientes del estado operativo:
	- `official_baseline_snapshots`
	- `official_baseline_sites`
- La tabla `sites` mantiene su propósito operativo actual y **no** representa la baseline.
- El runtime del sistema opera sobre datos persistidos en base de datos; no sobre lectura directa del CSV.

## Comando de importación de baseline
- Importar baseline:
	- `php artisan monitoring:import-official-baseline --source=docs/right_sites/true_sites.csv`
- Validar sin persistir:
	- `php artisan monitoring:import-official-baseline --source=docs/right_sites/true_sites.csv --dry-run`

## Seeder de baseline
- Seeder disponible para bootstrap inicial:
	- `php artisan db:seed --class=Database\\Seeders\\OfficialBaselineSeeder`

## Importante
Para sincronizar la lista oficial en la base de datos, el markdown fuente debe estar disponible en el repositorio o en una ruta accesible al runtime del contenedor.
