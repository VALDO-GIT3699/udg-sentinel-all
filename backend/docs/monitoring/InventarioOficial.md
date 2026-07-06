# Inventario Oficial UDG

Este documento es la fuente maestra markdown que el backend puede consumir para sincronizar el inventario oficial.

## Alcance
- Solo deben considerarse los sitios oficiales contenidos en el markdown institucional entregado por el usuario.
- El inventario no debe crecer por descubrimiento automático fuera de esta fuente.
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
- El archivo se usa como lista canónica de URLs y metadatos iniciales.
- El dashboard debe mostrar el inventario con una forma visual parecida a esa fuente.
- La columna de certificado debe reflejar estado real del monitoreo SSL, no solo texto histórico.
- Si el markdown contiene filas importables, `monitoring:sync-official-inventory` puede crear, actualizar y purgar sitios fuera del alcance oficial.

## Importante
Pega aquí el markdown completo del inventario institucional para que el backend lo sincronice.
