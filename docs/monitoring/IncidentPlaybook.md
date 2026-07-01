# Playbook de Incidentes - UDG Sentinel Monitoring

## Objetivo
Guiar la respuesta operativa ante caidas de sitios oficiales, alertas SSL y degradacion del pipeline de monitoreo.

## Severidades
- Critica: sitio oficial caido o SSL expirado.
- Alta: degradacion sostenida o SSL <= 7 dias.
- Media: cabeceras debiles, alertas recurrentes sin impacto directo.
- Baja: hallazgos informativos o eventos intermitentes.

## Flujo de Respuesta
1. Confirmar alerta en dashboard de monitoreo.
2. Revisar evidencia: checks recientes, codigo HTTP, latencia, error tecnico.
3. Correlacionar con eventos de infraestructura (red, DNS, CDN, WAF, certificados).
4. Escalar segun criticidad a equipo responsable del sitio.
5. Registrar acciones y tiempos en bitacora operativa.
6. Cerrar incidente cuando el sitio mantenga recuperacion estable.

## Runbook - Sitio Oficial Caido
1. Validar si la alerta fue confirmada por fallas consecutivas.
2. Ejecutar prueba manual HEAD/GET desde operacion.
3. Verificar:
   - DNS del dominio.
   - estado del servidor origen.
   - vencimiento SSL.
   - reglas de firewall/WAF.
4. Si la causa es infraestructura, escalar al equipo de plataforma.
5. Si la causa es aplicacion, escalar al equipo de desarrollo del sistema.
6. Confirmar recuperacion y verificar que el evento `site.recovered` se refleje en dashboard.

## Runbook - SSL Critico o Expirado
1. Revisar `days_remaining`, emisor, CN y SAN.
2. Confirmar cadena de certificados y fecha de renovacion esperada.
3. Escalar a responsable de certificados si <= 7 dias.
4. Si expirado, activar ventana de atencion inmediata.
5. Tras renovacion, validar nueva lectura SSL y cierre de alerta.

## Runbook - Degradacion de Pipeline
1. Revisar Horizon: colas, throughput, retries, failed jobs.
2. Validar profundidad de colas de monitoreo:
   - monitoring-uptime
   - monitoring-ssl
   - monitoring-tech
   - monitoring-headers
   - monitoring-alerts
3. Ajustar concurrencia si hay backlog sostenido.
4. Revisar logs de errores de jobs en `storage/logs`.

## SLAs Operativos Recomendados
- Deteccion a ack inicial:
  - Critica: <= 5 min
  - Alta: <= 15 min
  - Media/Baja: <= 60 min
- Actualizacion de estado a stakeholders:
  - Critica: cada 15 min
  - Alta: cada 30 min

## Checklist de Cierre
- Causa raiz identificada.
- Mitigacion aplicada.
- Verificacion de estabilidad (al menos 2 checks exitosos consecutivos).
- Alertas resueltas o justificadas.
- Lecciones aprendidas documentadas.

## Comandos Operativos
- Listar rutas de monitoreo:
  - `php artisan route:list --path=monitoring`
- Listar comandos del pipeline:
  - `php artisan list monitoring`
- Ejecutar despacho manual de checks:
  - `php artisan monitoring:dispatch-head-checks --limit=200`
  - `php artisan monitoring:dispatch-ssl-checks --limit=200`
  - `php artisan monitoring:dispatch-security-headers-checks --limit=200`
  - `php artisan monitoring:dispatch-technology-scans --limit=200`
