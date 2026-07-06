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

## Importante
Para sincronizar la lista oficial en la base de datos, el markdown fuente debe estar disponible en el repositorio o en una ruta accesible al runtime del contenedor.
