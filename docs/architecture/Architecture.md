# Arquitectura del Sistema — UDG Sentinel

**Versión:** 1.0  
**Fecha:** 2026-06-29  
**Estado:** Aprobado  
**Autor:** Coordinación de Tecnologías de la Información — UDG  

---

## 1. Visión general

UDG Sentinel es una **plataforma de observabilidad empresarial** construida sobre principios de arquitectura limpia, separación de responsabilidades y escalabilidad horizontal. Su propósito es centralizar el monitoreo, análisis de seguridad e inventario técnico de los más de 200 sitios web bajo el dominio `udg.mx`.

### 1.2 Evolución Asset Intelligence (2026-07)

- El modelo persistido `sites` se conserva por compatibilidad histórica, pero semánticamente se trata como activo digital (`Asset`).
- Se agregó una capa de compatibilidad de esquema (`AssetIntelligenceSchema`) para despliegues incrementales.
- Ningún módulo debe lanzar 500 cuando las columnas de Asset Intelligence aún no existen.
- Monitoreo inteligente por `Strategy Pattern` con `AssetMonitoringStrategyRouter`.
- Clasificador con `Rule Engine` desacoplado, versionado (`classifier_version`, `rule_engine_version`) y hash de resultado (`result_hash`).

---

## 1.3 Architecture Review Empresarial (2026-07-03)

### Hallazgos
- Fortaleza: modularidad base consistente con `nwidart`, repositorios y servicios desacoplados en dominios principales.
- Brecha cerrada: se incorpora `Analytics` como bounded context explicito (antes, analitica dispersa en controladores).
- Brecha cerrada: eventos de dominio ampliados para trazabilidad de ciclo de vida (`MonitoringCompleted`, `AvailabilityChanged`, `CertificateExpiring`, `TechnologyChanged`, `AlertTriggered`, `AlertResolved`, `AssetReclassified`, `ClassificationOverridden`).
- Riesgo residual: `Reports` conserva partes scaffold y requiere pipeline de exportacion robusto para cargas altas.
- Riesgo residual: faltan projections materializadas para analitica de muy alta cardinalidad (actualmente consultas directas en tiempo real).

### Decisiones arquitectonicas
- Se mantiene compatibilidad total con `sites` como agregado persistido canonico.
- Se consolida Asset Intelligence como capacidad transversal sin romper contratos legacy.
- Se refuerza Open/Closed en monitoreo por estrategias, permitiendo nuevos tipos de activo sin modificar estrategias existentes.

---

## 1.4 Bounded Contexts

- `Inventory`: catalogo de activos y metadatos maestros.
- `Monitoring`: disponibilidad, telemetria operativa y estado en tiempo real.
- `Classification`: motor de inferencia y override manual.
- `Analytics`: KPIs institucionales, distribuciones, tendencias y calidad del clasificador.
- `Alerting`: reglas, ciclo de vida de alertas e incidentes.
- `Reporting`: generacion y distribucion de reportes ejecutivos/tecnicos.
- `Authentication`: identidad, autorizacion y permisos.
- `Notifications`: envio multicanal y tracking de entregas.
- `Scheduling`: orquestacion de comandos y jobs periodicos.
- `Audit`: trazabilidad de acciones y cambios de estado.

Separacion recomendada: `Classification` se mantiene dentro de `Inventory` a nivel fisico por compatibilidad, pero se trata como subdominio explicito a nivel de modelo.

---

## 1.5 Modelo de Dominio (resumen)

### Entidades y Agregados
- Aggregate `Site` (Asset): raiz operativa con estado, criticidad y clasificacion vigente.
- Entity `AssetClassification`: historial versionado de decisiones de clasificacion.
- Entity `SiteCheck`: observaciones de disponibilidad y latencia.
- Entity `Alert`: incidente/alerta con ciclo de vida abierto-ack-resuelto.
- Entity `SslCertificate`, `SiteTechnology`, `SecurityScore` como evidencias especializadas.

### Value Objects (conceptuales actuales)
- `AssetFingerprint`, `AssetClassificationResult`, nivel de severidad, estado operativo y ventana temporal.

### Domain Services
- `AssetClassificationService`, `AssetClassificationEngine`, `EvaluateSiteStatusService`, `AnalyticsService`.

### Repositories
- `SiteRepositoryInterface`, `AssetClassificationRepositoryInterface`, `AlertRepositoryInterface`, `SiteCheckRepositoryInterface`.

### Policies / Specifications
- Politica de lock de clasificacion manual.
- Especificaciones por estrategia de monitoreo (`supports(assetType)`).
- Esquema disponible/no disponible con `AssetIntelligenceSchema`.

### Domain Events clave
- `AssetClassified`, `AssetReclassified`, `ClassificationOverridden`.
- `MonitoringCompleted`, `AvailabilityChanged`, `SiteStatusChanged`.
- `TechnologyChanged`, `CertificateExpiring`.
- `AlertTriggered`, `AlertResolved`.

### 1.1 Principios arquitectónicos

| Principio              | Descripción                                                                 |
|------------------------|-----------------------------------------------------------------------------|
| **Separación de capas**    | Frontend, Backend, Recolectores y Base de datos son capas independientes   |
| **Modularidad**            | Cada dominio funcional es un módulo aislado con su propio ciclo de vida    |
| **Sustitubilidad**         | Cualquier componente puede ser reemplazado sin afectar los demás           |
| **Escalabilidad horizontal** | Diseñado para pasar de 200 a 2,000 sitios sin rediseñar la plataforma   |
| **Seguridad por diseño**   | La seguridad no es un añadido; está integrada en cada capa                 |
| **Trazabilidad total**     | Cada acción significativa queda registrada                                 |
| **Sin código muerto**      | No hay lógica condicional por nombre de sitio; todo es configuración       |

---

## 2. Diagrama de arquitectura

```
┌─────────────────────────────────────────────────────────────────────┐
│                           USUARIOS                                   │
│            (Administradores / Operadores / Lectores)                 │
└──────────────────────────────┬──────────────────────────────────────┘
                               │ HTTPS
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         NGINX (Reverse Proxy)                        │
│                   Rate Limiting · TLS Termination                    │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                        PORTAL PRINCIPAL                              │
│                          Laravel 12                                  │
│                                                                      │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────────┐   │
│  │  Auth    │  │Dashboard │  │Inventory │  │  API REST v1     │   │
│  │  Module  │  │  Module  │  │  Module  │  │  (Sanctum)       │   │
│  └──────────┘  └──────────┘  └──────────┘  └──────────────────┘   │
│                                                                      │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────────┐   │
│  │Monitoring│  │  SSL     │  │Technology│  │    Security      │   │
│  │  Module  │  │  Module  │  │  Module  │  │    Module        │   │
│  └──────────┘  └──────────┘  └──────────┘  └──────────────────┘   │
│                                                                      │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐                          │
│  │Notif.    │  │ Reports  │  │  Audit   │                          │
│  │  Module  │  │  Module  │  │  Module  │                          │
│  └──────────┘  └──────────┘  └──────────┘                          │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
              ┌────────────────┼────────────────┐
              ▼                ▼                ▼
┌─────────────────┐  ┌──────────────┐  ┌──────────────────┐
│  PostgreSQL 16  │  │   Redis 7    │  │  Laravel Horizon │
│  (Persistencia) │  │ (Cache/Queue │  │  (Queue Monitor) │
│                 │  │  /Sessions)  │  │                  │
└─────────────────┘  └──────────────┘  └──────────────────┘
              │
              ▼
┌─────────────────────────────────────────────────────────────────────┐
│                          RECOLECTORES                                │
│                                                                      │
│  ┌──────────────┐  ┌────────────┐  ┌──────────────┐  ┌──────────┐ │
│  │ HTTP Checker │  │SSL Checker │  │ Tech Scanner │  │ Security │ │
│  │(Availability)│  │(Certs TLS) │  │(CMS/Version) │  │ Scanner  │ │
│  └──────────────┘  └────────────┘  └──────────────┘  └──────────┘ │
│                                                                      │
│  ┌──────────────┐  ┌────────────┐  ┌──────────────┐               │
│  │Server Metrics│  │Link Checker│  │ Drupal Probe │               │
│  │(CPU/RAM/Disk)│  │(404 Detect)│  │(Modules/Core)│               │
│  └──────────────┘  └────────────┘  └──────────────┘               │
└──────────────────────────────────────────────────────────────────────┘
              │
              ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      SITIOS MONITOREADOS                             │
│                    200+ dominios udg.mx                             │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 3. Capas de la arquitectura

### 3.1 Capa de presentación

**Tecnología:** Inertia.js 2 + Vue.js 3 (TypeScript) + Tailwind CSS 4

- El frontend es una **Single Page Application (SPA)** servida por Inertia.js, lo que evita la complejidad de una API pública separada para la UI.
- Vue 3 con Composition API y TypeScript garantiza un código frontend tipado y mantenible.
- Los componentes siguen el patrón **Atomic Design**: átomos → moléculas → organismos → plantillas → páginas.
- Las gráficas en tiempo real utilizan **ApexCharts** con actualizaciones vía WebSockets (Laravel Echo).
- El diseño visual es **custom** (no Bootstrap), inspirado en dashboards profesionales como Grafana/Cloudflare.

### 3.2 Capa de aplicación

**Tecnología:** Laravel 12 (PHP 8.2+)

- Arquitectura **modular** con nwidart/laravel-modules. Cada módulo contiene su propia capa de Controllers, Services, Repositories, Jobs y Models.
- Patrón **Repository**: las consultas no se escriben en controllers ni en servicios. Cada modelo tiene su Repository.
- Patrón **Service**: la lógica de negocio vive en Services, no en Controllers.
- **Form Requests**: toda validación de entrada en clases dedicadas.
- **API Resources**: toda respuesta API transformada con Resource classes.
- **Jobs + Queues**: operaciones pesadas (escaneos, reportes, notificaciones) se procesan en colas Redis.
- **Events + Listeners**: comunicación entre módulos vía eventos de dominio, no acoplamiento directo.

### 3.3 Capa de datos

**Tecnología:** PostgreSQL 16 + Redis 7

- **PostgreSQL** como base de datos principal: JSON/JSONB nativo, Table Partitioning para series de tiempo, índices parciales, full-text search.
- **Redis** para: caché de consultas frecuentes, sesiones, colas de trabajo y pub/sub para WebSockets.
- Las tablas de series de tiempo (`site_checks`, `server_metrics`, `traffic_metrics`) usan **particionado por rango de fechas** para mantener el rendimiento.
- Política de **retención de datos**: 1 año para checks, 3 meses para métricas de servidor, indefinido para eventos y alertas.

### 3.4 Capa de recolección

Los recolectores son **Jobs de Laravel** ejecutados por el Scheduler, aislados en la carpeta `Modules/*/Jobs/Collectors/`. Esto garantiza que:
- Se ejecutan de forma asíncrona en colas dedicadas.
- Fallan de forma aislada (un sitio caído no bloquea el resto).
- Tienen reintentos configurables con backoff exponencial.
- Quedan registrados en `scheduled_jobs` para auditoría.

Tipos de recolectores:
1. `HttpCheckJob` — Disponibilidad y tiempo de respuesta
2. `SslCheckJob` — Certificado TLS y días para vencer
3. `TechDetectionJob` — Detección de CMS/PHP/DB/framework
4. `SecurityScanJob` — Headers, vulnerabilidades
5. `ServerMetricsJob` — CPU/RAM/Disco (requiere agente o acceso SSH)
6. `LinkCheckerJob` — Detección de 404
7. `DrupalProbeJob` — Módulos y versión de Drupal

---

## 4. Módulos del sistema

### 4.1 Estructura de un módulo

```
Modules/
└── NombreModulo/
    ├── Config/
    │   └── config.php
    ├── Console/
    │   └── Commands/
    ├── Database/
    │   ├── factories/
    │   ├── migrations/
    │   └── seeders/
    ├── Entities/               ← Modelos Eloquent
    ├── Http/
    │   ├── Controllers/
    │   │   ├── Api/
    │   │   └── Web/
    │   ├── Middleware/
    │   ├── Requests/
    │   └── Resources/
    ├── Jobs/
    │   └── Collectors/
    ├── Events/
    ├── Listeners/
    ├── Policies/
    ├── Providers/
    │   └── NombreModuloServiceProvider.php
    ├── Repositories/
    │   ├── Contracts/          ← Interfaces
    │   └── Eloquent/           ← Implementaciones
    ├── Services/
    ├── Tests/
    │   ├── Feature/
    │   └── Unit/
    └── Routes/
        ├── api.php
        └── web.php
```

### 4.2 Catálogo de módulos

| Módulo            | Responsabilidad                                                          |
|-------------------|--------------------------------------------------------------------------|
| **Inventory**     | Sitios, servidores, dependencias (site_groups), relaciones               |
| **Monitoring**    | Uptime checks, tiempo de respuesta, tráfico, línea del tiempo            |
| **SSL**           | Certificados TLS, seguimiento de vencimiento, alertas SSL                |
| **Technology**    | Detección de CMS, PHP, DB, framework, módulos Drupal                     |
| **Security**      | Score de salud, headers HTTP, vulnerabilidades, escaneos                 |
| **Notifications** | Reglas de alerta, canales (email/Slack/Teams/Telegram), despacho         |
| **Reports**       | Generación de PDF, programación, entrega por canal                       |
| **Auth**          | Usuarios, roles, permisos, 2FA, SSO-ready                                |
| **Audit**         | Log de actividad, accesos, acciones críticas                             |
| **Dashboard**     | Vistas agregadas, KPIs globales, resúmenes ejecutivos                    |

---

## 5. Flujo de datos

### 5.1 Ciclo de monitoreo

```
Scheduler (cada 1-5 min)
    │
    ▼
Dispatch HttpCheckJob(site)
    │
    ▼
Job ejecuta verificación HTTP
    │
    ├── Guarda resultado en site_checks
    ├── Calcula nuevo security_score
    ├── Registra evento en site_events
    └── Si cambio de estado → dispara SiteStatusChangedEvent
                                    │
                                    ▼
                             NotificationListener
                                    │
                                    ▼
                             Evalúa alert_rules
                                    │
                                    ▼
                             Crea alert + notificaciones
                                    │
                                    ▼
                             SendNotificationJob
                                    │
                                    ▼
                             Email / Telegram / Slack
```

### 5.2 Ciclo de cálculo de score

```
Trigger: SiteCheckedEvent
    │
    ▼
HealthScoreCalculator::calculate($site)
    │
    ├── Base: 100 puntos
    ├── Verifica site_checks (uptime)
    ├── Verifica ssl_certificates (vencimiento)
    ├── Verifica cms_details (versiones)
    ├── Verifica security_headers
    ├── Verifica vulnerabilities activas
    ├── Verifica broken_links
    └── Retorna SecurityScore con breakdown detallado
    │
    ▼
Persiste en security_scores
Actualiza sites.current_score
Registra evento si el nivel cambió
```

---

## 6. Seguridad arquitectónica

### 6.1 Defensa en profundidad

```
Capa 1: Nginx       → TLS, Rate Limiting, headers de seguridad globales
Capa 2: Laravel     → CSRF, XSS sanitization, SQL injection prevention
Capa 3: Middleware  → Auth, 2FA, RBAC, IP whitelist (optional)
Capa 4: Modelos     → Guardado de mass assignment, scopes de acceso
Capa 5: Datos       → Cifrado de campos sensibles, variables de entorno
```

### 6.2 Control de acceso

- **RBAC** con spatie/laravel-permission
- Roles: `super_admin`, `admin`, `operator`, `viewer`
- Permisos granulares por módulo (ejemplo: `monitoring.view`, `monitoring.configure`, `sites.create`, etc.)
- Las políticas de Eloquent verifican permisos a nivel de recurso individual.

### 6.3 Secretos y configuración

- **Cero credenciales en código**. Todo en variables de entorno.
- Producción usa gestión de secretos (HashiCorp Vault o equivalente institucional).
- El archivo `.env` nunca se sube al repositorio.
- El `.env.example` contiene todas las variables con valores vacíos y comentarios.

---

## 7. Infraestructura y despliegue

### 7.1 Contenedores

```yaml
Servicios Docker:
  app:     PHP-FPM 8.2 + Laravel
  nginx:   Servidor web y proxy
  db:      PostgreSQL 16
  redis:   Redis 7
  horizon: Laravel Horizon (worker de colas)
  scheduler: Laravel Scheduler (cron)
```

### 7.2 Entornos

| Entorno       | Descripción                                      |
|---------------|--------------------------------------------------|
| `local`       | Docker Compose local para desarrollo             |
| `staging`     | Réplica de producción para validación            |
| `production`  | Servidores institucionales UDG                   |

### 7.3 CI/CD (GitHub Actions)

```
Push a main/develop
    │
    ▼
Pipeline CI:
    ├── php-cs-fixer (estilo de código)
    ├── PHPStan (análisis estático nivel 8)
    ├── PHPUnit (pruebas unitarias e integración)
    ├── Vue TypeScript check
    └── npm run build (assets)
    │
    ▼ (solo en main)
Pipeline CD:
    ├── Build Docker image
    ├── Push a registry
    └── Deploy a staging/production
```

---

## 8. Decisiones de diseño importantes

1. **PostgreSQL sobre MySQL**: soporte nativo de JSONB, particionado de tablas, índices parciales y mejor rendimiento en queries analíticas complejas.

2. **Inertia.js + Vue 3 sobre API + SPA separada**: reduce complejidad al mantener routing, auth y middleware de Laravel, sin sacrificar la experiencia de SPA.

3. **Módulos (nwidart) sobre estructura monolítica**: permite que cada dominio evolucione independientemente, facilita pruebas aisladas y posibilita extraer módulos a microservicios en el futuro.

4. **Redis para todo**: sesiones en Redis evita problemas de sesión en entornos multi-proceso; colas en Redis con Horizon da visibilidad total.

5. **Jobs asíncronos para recolectores**: un sitio caído o lento no bloquea el monitoreo de los otros 199 sitios.

6. **Sin lógica condicional por nombre de sitio**: toda configuración es datos, no código. Esto es innegociable para la mantenibilidad a largo plazo.

---

## 9. Escalabilidad

La plataforma está diseñada para escalar en dos dimensiones:

**Vertical (más datos por sitio):**
- Particionado de tablas de series de tiempo
- Índices compuestos en columnas de consulta frecuente
- Redis para absorber lecturas frecuentes del dashboard

**Horizontal (más sitios):**
- Workers de queue escalables independientemente
- Múltiples instancias del scheduler con locks distribuidos
- Base de datos con read replicas para queries pesadas
- CDN para assets estáticos

---

## 10. Monitoreo del propio sistema

UDG Sentinel se monitorea a sí mismo:

- **Laravel Horizon**: visibilidad de colas, jobs fallidos, throughput
- **Laravel Telescope** (solo desarrollo): queries, eventos, jobs, logs
- **PostgreSQL pg_stat_activity**: monitoreo de conexiones y queries lentas
- **Health check endpoint** (`/api/health`): estado de DB, Redis, Queue
- **Alertas internas**: si el scheduler no ha corrido en X minutos → alerta al equipo técnico

---

*Última actualización: 2026-06-29 | Versión 1.0*
