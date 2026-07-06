# AI_CONTEXT - UDG Sentinel

## Estado actual
- Modelo principal persistido: `sites`.
- Evolucion semantica activa: `Site` tratado como `Asset`.
- Asset Intelligence habilita:
  - clasificacion automatica probabilistica
  - clasificacion manual bloqueante
  - historial versionado en `asset_classifications`
  - dashboard de gobierno en `/monitoring/assets/intelligence`

## Compatibilidad de esquema
- Usar `App\\Support\\AssetIntelligenceSchema` para validar disponibilidad antes de consultar columnas nuevas.
- Si Asset Intelligence no esta disponible, degradar sin 500:
  - filtros ocultos
  - defaults `unknown`
  - comandos/jobs no-op

## Monitoreo por estrategia
- Router: `Modules\\Monitoring\\Services\\Strategies\\AssetMonitoringStrategyRouter`
- Estrategias iniciales:
  - WebsiteMonitoringStrategy
  - RestApiMonitoringStrategy
  - MailServerMonitoringStrategy

## Reglas de mantenimiento
- No romper contratos existentes de `SiteRepositoryInterface`.
- No eliminar compatibilidad de rutas/dashboard existentes.
- Toda evolucion nueva requiere pruebas y migraciones idempotentes.
