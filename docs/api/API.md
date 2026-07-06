# API Interna - Asset Intelligence y Monitoring

## Endpoints nuevos

### GET /monitoring/assets/intelligence
Retorna dashboard de gobierno de activos (Inertia).

### POST /monitoring/sites/{site}/classification/manual
Aplica clasificacion manual y bloquea sobreescritura automatica.

Payload:
```json
{
  "asset_type": "web_application",
  "asset_role": "lms",
  "confidence_pct": 95,
  "notes": "Validado por equipo de arquitectura"
}
```

Respuesta exitosa: 200 JSON

### POST /monitoring/sites/{site}/classification/approve
Aprueba en un clic la clasificacion sugerida actual y la convierte en manual bloqueada.

Payload: vacio

Respuesta exitosa: 200 JSON

### GET /analytics/overview
Vista ejecutiva de analitica institucional (Inertia).

### GET /api/analytics/overview
Retorna resumen analitico para integraciones internas.

### GET /monitoring/sites/{siteId}/detail
Incluye campos de Asset Intelligence cuando el esquema esta disponible:
- asset_type
- asset_role
- asset_confidence_pct
- asset_classification_source

Si el esquema no esta disponible:
- `assetIntelligenceEnabled=false`
- filtros/opciones vacias
- comportamiento degradado sin error 500

## Comandos CLI relevantes

- `php artisan inventory:dispatch-asset-classifications --limit=200`
- `php artisan monitoring:dispatch-asset-monitoring --limit=200`

## Eventos de dominio

- `Modules\\Inventory\\Events\\AssetClassified`
- `Modules\\Inventory\\Events\\AssetReclassified`
- `Modules\\Inventory\\Events\\ClassificationOverridden`
- `Modules\\Monitoring\\Events\\MonitoringCompleted`
- `Modules\\Monitoring\\Events\\AvailabilityChanged`
- `Modules\\Monitoring\\Events\\TechnologyChanged`
- `Modules\\Monitoring\\Events\\CertificateExpiring`
- `Modules\\Monitoring\\Events\\AlertTriggered`
- `Modules\\Monitoring\\Events\\AlertResolved`

Payload base:
- siteId
- source (automatic|manual)
- payload (clasificacion, scores, recomendaciones)
- classifiedAt
