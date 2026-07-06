# Modelo de Base de Datos — UDG Sentinel

**Versión:** 1.0  
**Motor:** PostgreSQL 16  
**Fecha:** 2026-06-29  

---

## 1. Principios de diseño

- Nomenclatura en **snake_case**, en **inglés**, en **plural** para tablas.
- Toda tabla tiene `id` (ULID/UUID o bigint según el caso), `created_at` y `updated_at`.
- Las tablas de series de tiempo tienen particionado por rango de fecha.
- Los campos de configuración extensible usan `JSONB` en lugar de columnas dispersas.
- **Sin tablas genéricas** (`datos`, `informacion`, `sitios2`). Nombre preciso, propósito claro.
- Soft deletes (`deleted_at`) solo donde el negocio requiere historial de borrados.

---

## 2. Diagrama de entidades principales

```
site_groups ──< sites >── servers
                  │
         ┌────────┼──────────────────────┐
         ▼        ▼                      ▼
    site_checks  ssl_certificates    site_technologies
         │                                │
         │                          cms_details
         │                                │
         │                          drupal_modules
         │
    ┌────┼───────────────┐
    ▼    ▼               ▼
 alerts  security_scores  broken_links
    │
 notifications_sent
    │
 notification_channels
         │
    site_events (timeline)
```

### 2.1 Extensión de Asset Intelligence y Analytics

- `sites` incorpora atributos de inteligencia de activos:
  - `asset_type`, `asset_role`, `asset_confidence_pct`
  - `asset_classification_source`, `asset_last_classified_at`, `asset_classification_locked_at`
  - `asset_classifier_version`, `asset_classification_evidence`
- `asset_classifications` conserva historial inmutable de clasificaciones:
  - `source`, `asset_type`, `asset_role`, `confidence_pct`, `scores`, `evidence`
  - versionado de motor (`classifier_version`, `rule_engine_version`)
  - trazabilidad (`rules_used`, `observations`, `recommendations`, `result_hash`)
- `Analytics` consume estas entidades para KPIs, tendencias y calidad del clasificador sin duplicar dominios ni borrar historial.

---

## 3. Definición completa de tablas

### 3.1 Inventario

#### `site_groups` — Dependencias / grupos de sitios

| Columna             | Tipo           | Restricciones           | Descripción                        |
|---------------------|----------------|-------------------------|------------------------------------|
| `id`                | bigserial      | PK                      | Identificador único                |
| `name`              | varchar(255)   | NOT NULL                | Nombre de la dependencia           |
| `slug`              | varchar(100)   | UNIQUE NOT NULL         | Identificador URL-friendly         |
| `description`       | text           | NULLABLE                | Descripción del grupo              |
| `responsible_name`  | varchar(255)   | NULLABLE                | Nombre del responsable             |
| `responsible_email` | varchar(255)   | NULLABLE                | Email del responsable              |
| `color`             | varchar(7)     | DEFAULT '#3B82F6'       | Color de identificación (#RRGGBB)  |
| `created_at`        | timestamptz    | NOT NULL                |                                    |
| `updated_at`        | timestamptz    | NOT NULL                |                                    |

---

#### `sites` — Sitios monitoreados (tabla central)

| Columna              | Tipo           | Restricciones              | Descripción                           |
|----------------------|----------------|----------------------------|---------------------------------------|
| `id`                 | bigserial      | PK                         | Identificador único                   |
| `site_group_id`      | bigint         | FK → site_groups.id        | Dependencia a la que pertenece        |
| `name`               | varchar(255)   | NOT NULL                   | Nombre descriptivo                    |
| `slug`               | varchar(100)   | UNIQUE NOT NULL            | Identificador URL-friendly            |
| `domain`             | varchar(255)   | NOT NULL                   | Dominio (ej: cucei.udg.mx)            |
| `url`                | varchar(500)   | NOT NULL                   | URL completa a monitorear             |
| `is_active`          | boolean        | DEFAULT true               | Si el sitio está activo               |
| `is_monitored`       | boolean        | DEFAULT true               | Si el monitoreo está habilitado       |
| `priority`           | smallint       | DEFAULT 2                  | 1=crítico, 2=normal, 3=bajo           |
| `current_status`     | varchar(20)    | DEFAULT 'unknown'          | up/down/degraded/unknown              |
| `current_score`      | smallint       | DEFAULT 100                | Score de salud actual (0-100)         |
| `current_score_level`| varchar(20)    | DEFAULT 'unknown'          | excellent/good/medium/low/critical    |
| `last_checked_at`    | timestamptz    | NULLABLE                   | Último chequeo realizado              |
| `check_interval_min` | smallint       | DEFAULT 5                  | Minutos entre cada chequeo            |
| `notes`              | text           | NULLABLE                   | Notas del administrador               |
| `tags`               | jsonb          | DEFAULT '[]'               | Etiquetas de clasificación            |
| `created_at`         | timestamptz    | NOT NULL                   |                                       |
| `updated_at`         | timestamptz    | NOT NULL                   |                                       |
| `deleted_at`         | timestamptz    | NULLABLE                   | Soft delete                           |

**Índices:**
```sql
CREATE INDEX idx_sites_site_group_id ON sites(site_group_id);
CREATE INDEX idx_sites_domain ON sites(domain);
CREATE INDEX idx_sites_current_status ON sites(current_status) WHERE is_active = true;
CREATE INDEX idx_sites_current_score ON sites(current_score) WHERE is_active = true;
```

---

#### `servers` — Servidores de infraestructura

| Columna          | Tipo           | Restricciones    | Descripción                         |
|------------------|----------------|------------------|-------------------------------------|
| `id`             | bigserial      | PK               | Identificador único                 |
| `name`           | varchar(255)   | NOT NULL         | Nombre descriptivo del servidor     |
| `hostname`       | varchar(255)   | NULLABLE         | Hostname del servidor               |
| `ip_address`     | inet           | NULLABLE         | Dirección IP del servidor           |
| `os`             | varchar(100)   | NULLABLE         | Sistema operativo (Ubuntu 22.04)    |
| `provider`       | varchar(100)   | NULLABLE         | Proveedor (CUCEA, Redes UDG, etc.)  |
| `location`       | varchar(100)   | NULLABLE         | Ubicación física o datacenter       |
| `ssh_port`       | smallint       | DEFAULT 22       | Puerto SSH                          |
| `ssh_user`       | varchar(100)   | NULLABLE         | Usuario SSH de acceso               |
| `is_accessible`  | boolean        | DEFAULT false    | Si tenemos acceso al servidor       |
| `cpu_cores`      | smallint       | NULLABLE         | Núcleos de CPU                      |
| `ram_gb`         | decimal(5,2)   | NULLABLE         | RAM total en GB                     |
| `disk_gb`        | decimal(7,2)   | NULLABLE         | Disco total en GB                   |
| `notes`          | text           | NULLABLE         | Notas del administrador             |
| `created_at`     | timestamptz    | NOT NULL         |                                     |
| `updated_at`     | timestamptz    | NOT NULL         |                                     |

---

#### `site_server` — Relación muchos a muchos sitios-servidores

| Columna      | Tipo    | Restricciones           |
|--------------|---------|-------------------------|
| `site_id`    | bigint  | FK → sites.id           |
| `server_id`  | bigint  | FK → servers.id         |
| `is_primary` | boolean | DEFAULT false           |

---

### 3.2 Monitoreo

#### `site_checks` — Historial de verificaciones de disponibilidad

> **Particionada por rango en `checked_at`** (particiones mensuales).

| Columna              | Tipo          | Restricciones        | Descripción                          |
|----------------------|---------------|----------------------|--------------------------------------|
| `id`                 | bigserial     | PK                   |                                      |
| `site_id`            | bigint        | FK → sites.id        | Sitio verificado                     |
| `checked_at`         | timestamptz   | NOT NULL             | Momento de la verificación           |
| `status`             | varchar(20)   | NOT NULL             | up / down / degraded / timeout       |
| `http_code`          | smallint      | NULLABLE             | Código HTTP (200, 301, 404, 500...)  |
| `response_time_ms`   | int           | NULLABLE             | Tiempo de respuesta en ms            |
| `response_size_bytes`| int           | NULLABLE             | Tamaño de la respuesta               |
| `ip_resolved`        | inet          | NULLABLE             | IP resuelta en la verificación       |
| `redirect_url`       | varchar(500)  | NULLABLE             | URL de redirección si aplica         |
| `error_message`      | text          | NULLABLE             | Mensaje de error si hubo             |
| `checked_from`       | varchar(100)  | DEFAULT 'sentinel'   | Origen del chequeo                   |
| `created_at`         | timestamptz   | NOT NULL             |                                      |

**Índices:**
```sql
CREATE INDEX idx_site_checks_site_checked ON site_checks(site_id, checked_at DESC);
CREATE INDEX idx_site_checks_status ON site_checks(status, checked_at DESC) WHERE status != 'up';
```

**Retención:** 12 meses. Las particiones más antiguas se archivan o eliminan automáticamente.

---

#### `server_metrics` — Métricas de infraestructura por servidor

> **Particionada por rango en `recorded_at`** (particiones mensuales).

| Columna           | Tipo         | Restricciones       | Descripción               |
|-------------------|--------------|---------------------|---------------------------|
| `id`              | bigserial    | PK                  |                           |
| `server_id`       | bigint       | FK → servers.id     | Servidor medido           |
| `recorded_at`     | timestamptz  | NOT NULL            | Momento de la medición    |
| `cpu_usage_pct`   | decimal(5,2) | NULLABLE            | Uso de CPU en %           |
| `ram_usage_pct`   | decimal(5,2) | NULLABLE            | Uso de RAM en %           |
| `ram_used_mb`     | int          | NULLABLE            | RAM usada en MB           |
| `ram_total_mb`    | int          | NULLABLE            | RAM total en MB           |
| `disk_usage_pct`  | decimal(5,2) | NULLABLE            | Uso de disco en %         |
| `disk_used_gb`    | decimal(7,2) | NULLABLE            | Disco usado en GB         |
| `disk_total_gb`   | decimal(7,2) | NULLABLE            | Disco total en GB         |
| `load_avg_1`      | decimal(6,2) | NULLABLE            | Load average 1 min        |
| `load_avg_5`      | decimal(6,2) | NULLABLE            | Load average 5 min        |
| `load_avg_15`     | decimal(6,2) | NULLABLE            | Load average 15 min       |
| `created_at`      | timestamptz  | NOT NULL            |                           |

---

#### `traffic_metrics` — Métricas de tráfico web por sitio

> **Particionada por rango en `recorded_at`**.

| Columna               | Tipo         | Restricciones    | Descripción                    |
|-----------------------|--------------|------------------|--------------------------------|
| `id`                  | bigserial    | PK               |                                |
| `site_id`             | bigint       | FK → sites.id    | Sitio                          |
| `recorded_at`         | timestamptz  | NOT NULL         | Momento de la medición         |
| `requests_per_min`    | int          | NULLABLE         | Solicitudes por minuto         |
| `unique_visitors`     | int          | NULLABLE         | Visitantes únicos en el periodo|
| `bandwidth_bytes`     | bigint       | NULLABLE         | Bytes transferidos             |
| `error_rate_pct`      | decimal(5,2) | NULLABLE         | Tasa de errores en %           |
| `avg_response_time_ms`| int          | NULLABLE         | Tiempo promedio de respuesta   |
| `created_at`          | timestamptz  | NOT NULL         |                                |

---

### 3.3 SSL

#### `ssl_certificates` — Certificados TLS por sitio

| Columna            | Tipo          | Restricciones     | Descripción                       |
|--------------------|---------------|-------------------|-----------------------------------|
| `id`               | bigserial     | PK                |                                   |
| `site_id`          | bigint        | FK → sites.id     | Sitio al que pertenece            |
| `common_name`      | varchar(255)  | NULLABLE          | Common Name del certificado       |
| `issuer`           | varchar(255)  | NULLABLE          | Entidad emisora                   |
| `issuer_org`       | varchar(255)  | NULLABLE          | Organización emisora              |
| `valid_from`       | timestamptz   | NULLABLE          | Fecha de inicio de validez        |
| `valid_until`      | timestamptz   | NULLABLE          | Fecha de vencimiento              |
| `days_remaining`   | int           | NULLABLE          | Días para el vencimiento          |
| `is_valid`         | boolean       | DEFAULT false     | Si el certificado es válido       |
| `is_expired`       | boolean       | DEFAULT false     | Si el certificado está vencido    |
| `algorithm`        | varchar(50)   | NULLABLE          | Algoritmo (RSA, ECDSA...)         |
| `key_size`         | smallint      | NULLABLE          | Tamaño de la clave en bits        |
| `signature_alg`    | varchar(100)  | NULLABLE          | Algoritmo de firma                |
| `san_domains`      | jsonb         | DEFAULT '[]'      | Dominios alternativos (SANs)      |
| `fingerprint_sha256`| varchar(95)  | NULLABLE          | Fingerprint SHA-256               |
| `last_checked_at`  | timestamptz   | NULLABLE          | Último chequeo                    |
| `created_at`       | timestamptz   | NOT NULL          |                                   |
| `updated_at`       | timestamptz   | NOT NULL          |                                   |

**Índices:**
```sql
CREATE INDEX idx_ssl_site_valid_until ON ssl_certificates(site_id, valid_until);
CREATE INDEX idx_ssl_days_remaining ON ssl_certificates(days_remaining) WHERE is_expired = false;
```

---

### 3.4 Detección tecnológica

#### `technologies` — Catálogo de tecnologías

| Columna       | Tipo          | Restricciones    | Descripción                                      |
|---------------|---------------|------------------|--------------------------------------------------|
| `id`          | bigserial     | PK               |                                                  |
| `name`        | varchar(255)  | NOT NULL         | Nombre (Drupal, Laravel, PHP, MySQL...)          |
| `slug`        | varchar(100)  | UNIQUE NOT NULL  |                                                  |
| `category`    | varchar(50)   | NOT NULL         | cms/framework/language/database/server/cdn/other |
| `vendor`      | varchar(255)  | NULLABLE         | Empresa/comunidad desarrolladora                 |
| `logo_url`    | varchar(500)  | NULLABLE         | URL del logo para mostrar en UI                  |
| `created_at`  | timestamptz   | NOT NULL         |                                                  |
| `updated_at`  | timestamptz   | NOT NULL         |                                                  |

---

#### `site_technologies` — Tecnologías detectadas por sitio

| Columna            | Tipo          | Restricciones          | Descripción                         |
|--------------------|---------------|------------------------|-------------------------------------|
| `id`               | bigserial     | PK                     |                                     |
| `site_id`          | bigint        | FK → sites.id          |                                     |
| `technology_id`    | bigint        | FK → technologies.id   |                                     |
| `version`          | varchar(50)   | NULLABLE               | Versión detectada                   |
| `confidence_pct`   | smallint      | DEFAULT 100            | Confianza de la detección (0-100)   |
| `is_primary`       | boolean       | DEFAULT false          | Si es la tecnología principal       |
| `detected_at`      | timestamptz   | NOT NULL               | Cuándo se detectó                   |
| `detection_method` | varchar(50)   | DEFAULT 'automatic'    | automatic/manual/api                |
| `metadata`         | jsonb         | DEFAULT '{}'           | Datos adicionales de detección      |
| `created_at`       | timestamptz   | NOT NULL               |                                     |
| `updated_at`       | timestamptz   | NOT NULL               |                                     |

---

#### `cms_details` — Detalles del CMS por sitio

| Columna             | Tipo          | Restricciones    | Descripción                          |
|---------------------|---------------|------------------|--------------------------------------|
| `id`                | bigserial     | PK               |                                      |
| `site_id`           | bigint        | FK → sites.id    | UNIQUE                               |
| `cms_type`          | varchar(50)   | NULLABLE         | drupal/wordpress/laravel/joomla/other|
| `cms_version`       | varchar(50)   | NULLABLE         | Versión del CMS                      |
| `db_type`           | varchar(50)   | NULLABLE         | mysql/postgresql/sqlite              |
| `db_version`        | varchar(50)   | NULLABLE         | Versión de la base de datos          |
| `php_version`       | varchar(20)   | NULLABLE         | Versión de PHP                       |
| `php_is_vulnerable` | boolean       | DEFAULT false    | Si la versión de PHP tiene CVEs      |
| `server_software`   | varchar(255)  | NULLABLE         | Apache 2.4 / Nginx 1.24...           |
| `theme_name`        | varchar(255)  | NULLABLE         | Tema activo (Drupal: bootr4theme)    |
| `theme_version`     | varchar(50)   | NULLABLE         | Versión del tema                     |
| `modules_count`     | smallint      | DEFAULT 0        | Número de módulos/plugins activos    |
| `has_updates`       | boolean       | DEFAULT false    | Si hay actualizaciones disponibles   |
| `has_security_updates` | boolean    | DEFAULT false    | Si hay actualizaciones de seguridad  |
| `last_scanned_at`   | timestamptz   | NULLABLE         | Último escaneo de esta información   |
| `created_at`        | timestamptz   | NOT NULL         |                                      |
| `updated_at`        | timestamptz   | NOT NULL         |                                      |

---

#### `drupal_modules` — Módulos de Drupal por sitio

| Columna                     | Tipo          | Restricciones       | Descripción                     |
|-----------------------------|---------------|---------------------|---------------------------------|
| `id`                        | bigserial     | PK                  |                                 |
| `cms_detail_id`             | bigint        | FK → cms_details.id |                                 |
| `module_name`               | varchar(255)  | NOT NULL            | Nombre del módulo               |
| `module_version`            | varchar(50)   | NULLABLE            | Versión instalada               |
| `is_enabled`                | boolean       | DEFAULT true        | Si está habilitado              |
| `is_core`                   | boolean       | DEFAULT false       | Si es módulo core de Drupal     |
| `project_url`               | varchar(500)  | NULLABLE            | URL del proyecto en drupal.org  |
| `has_update_available`      | boolean       | DEFAULT false       | Si hay actualización            |
| `security_update_available` | boolean       | DEFAULT false       | Si hay actualización de seguridad|
| `created_at`                | timestamptz   | NOT NULL            |                                 |
| `updated_at`                | timestamptz   | NOT NULL            |                                 |

---

### 3.5 Seguridad

#### `security_scores` — Score de salud calculado

| Columna          | Tipo          | Restricciones    | Descripción                          |
|------------------|---------------|------------------|--------------------------------------|
| `id`             | bigserial     | PK               |                                      |
| `site_id`        | bigint        | FK → sites.id    |                                      |
| `score`          | smallint      | NOT NULL         | Puntuación 0-100                     |
| `level`          | varchar(20)   | NOT NULL         | critical/low/medium/good/excellent   |
| `calculated_at`  | timestamptz   | NOT NULL         | Momento del cálculo                  |
| `breakdown`      | jsonb         | DEFAULT '{}'     | Desglose de cada factor y su impacto |
| `recommendations`| jsonb         | DEFAULT '[]'     | Lista de recomendaciones             |
| `created_at`     | timestamptz   | NOT NULL         |                                      |

**Índices:**
```sql
CREATE INDEX idx_security_scores_site_calc ON security_scores(site_id, calculated_at DESC);
```

---

#### `security_headers` — Headers HTTP de seguridad

| Columna                  | Tipo          | Restricciones    | Descripción                     |
|--------------------------|---------------|------------------|---------------------------------|
| `id`                     | bigserial     | PK               |                                 |
| `site_id`                | bigint        | FK → sites.id    |                                 |
| `checked_at`             | timestamptz   | NOT NULL         |                                 |
| `has_hsts`               | boolean       | DEFAULT false    | Strict-Transport-Security       |
| `has_csp`                | boolean       | DEFAULT false    | Content-Security-Policy         |
| `has_x_frame_options`    | boolean       | DEFAULT false    | X-Frame-Options                 |
| `has_x_content_type`     | boolean       | DEFAULT false    | X-Content-Type-Options          |
| `has_referrer_policy`    | boolean       | DEFAULT false    | Referrer-Policy                 |
| `has_permissions_policy` | boolean       | DEFAULT false    | Permissions-Policy              |
| `score_contribution`     | smallint      | DEFAULT 0        | Puntos ganados/perdidos         |
| `raw_headers`            | jsonb         | DEFAULT '{}'     | Headers completos capturados    |
| `created_at`             | timestamptz   | NOT NULL         |                                 |
| `updated_at`             | timestamptz   | NOT NULL         |                                 |

---

#### `vulnerabilities` — Vulnerabilidades detectadas

| Columna               | Tipo          | Restricciones       | Descripción                      |
|-----------------------|---------------|---------------------|----------------------------------|
| `id`                  | bigserial     | PK                  |                                  |
| `site_id`             | bigint        | FK → sites.id       |                                  |
| `scan_result_id`      | bigint        | FK → scan_results.id| NULLABLE                         |
| `title`               | varchar(500)  | NOT NULL            | Título de la vulnerabilidad      |
| `description`         | text          | NULLABLE            | Descripción detallada            |
| `severity`            | varchar(20)   | NOT NULL            | critical/high/medium/low/info    |
| `category`            | varchar(100)  | NULLABLE            | SQLi/XSS/CSRF/outdated/etc.      |
| `cve_id`              | varchar(20)   | NULLABLE            | Identificador CVE si aplica      |
| `affected_component`  | varchar(255)  | NULLABLE            | Componente afectado              |
| `affected_version`    | varchar(50)   | NULLABLE            | Versión afectada                 |
| `remediation`         | text          | NULLABLE            | Pasos para remediar              |
| `is_active`           | boolean       | DEFAULT true        | Si sigue activa                  |
| `is_false_positive`   | boolean       | DEFAULT false       | Si fue marcada como falso positivo|
| `detected_at`         | timestamptz   | NOT NULL            |                                  |
| `resolved_at`         | timestamptz   | NULLABLE            |                                  |
| `created_at`          | timestamptz   | NOT NULL            |                                  |
| `updated_at`          | timestamptz   | NOT NULL            |                                  |

---

#### `scan_results` — Resultados de escaneos de seguridad

| Columna          | Tipo          | Restricciones    | Descripción                       |
|------------------|---------------|------------------|-----------------------------------|
| `id`             | bigserial     | PK               |                                   |
| `site_id`        | bigint        | FK → sites.id    |                                   |
| `scan_type`      | varchar(50)   | NOT NULL         | security/headers/links/technology |
| `started_at`     | timestamptz   | NOT NULL         |                                   |
| `completed_at`   | timestamptz   | NULLABLE         |                                   |
| `status`         | varchar(20)   | DEFAULT 'pending'| pending/running/completed/failed  |
| `findings_count` | int           | DEFAULT 0        |                                   |
| `critical_count` | smallint      | DEFAULT 0        |                                   |
| `high_count`     | smallint      | DEFAULT 0        |                                   |
| `medium_count`   | smallint      | DEFAULT 0        |                                   |
| `low_count`      | smallint      | DEFAULT 0        |                                   |
| `raw_output`     | jsonb         | DEFAULT '{}'     | Salida completa del scanner       |
| `created_at`     | timestamptz   | NOT NULL         |                                   |
| `updated_at`     | timestamptz   | NOT NULL         |                                   |

---

### 3.6 Links rotos

#### `broken_links` — Páginas con error 404 u otros errores

| Columna           | Tipo          | Restricciones    | Descripción                        |
|-------------------|---------------|------------------|------------------------------------|
| `id`              | bigserial     | PK               |                                    |
| `site_id`         | bigint        | FK → sites.id    |                                    |
| `url`             | varchar(2048) | NOT NULL         | URL con error                      |
| `found_on`        | varchar(2048) | NULLABLE         | Página donde se encontró el link   |
| `http_code`       | smallint      | NULLABLE         | Código HTTP del error              |
| `first_detected_at`| timestamptz  | NOT NULL         | Primera detección                  |
| `last_checked_at` | timestamptz   | NULLABLE         | Última verificación                |
| `is_resolved`     | boolean       | DEFAULT false    | Si fue resuelto                    |
| `resolved_at`     | timestamptz   | NULLABLE         |                                    |
| `created_at`      | timestamptz   | NOT NULL         |                                    |
| `updated_at`      | timestamptz   | NOT NULL         |                                    |

---

### 3.7 Alertas y notificaciones

#### `alert_rules` — Reglas configurables de alerta

| Columna               | Tipo          | Restricciones    | Descripción                              |
|-----------------------|---------------|------------------|------------------------------------------|
| `id`                  | bigserial     | PK               |                                          |
| `name`                | varchar(255)  | NOT NULL         | Nombre de la regla                       |
| `description`         | text          | NULLABLE         |                                          |
| `metric_type`         | varchar(100)  | NOT NULL         | uptime/ssl_expiry/response_time/score/etc|
| `condition_operator`  | varchar(10)   | NOT NULL         | lt/lte/gt/gte/eq/neq                     |
| `condition_value`     | varchar(100)  | NOT NULL         | Valor umbral                             |
| `severity`            | varchar(20)   | NOT NULL         | critical/high/medium/low                 |
| `is_active`           | boolean       | DEFAULT true     |                                          |
| `applies_to`          | varchar(20)   | DEFAULT 'all'    | all/group/site                           |
| `target_id`           | bigint        | NULLABLE         | ID del grupo o sitio específico          |
| `cooldown_minutes`    | int           | DEFAULT 60       | Minutos de silencio tras alerta          |
| `channel_ids`         | jsonb         | DEFAULT '[]'     | Canales a notificar                      |
| `created_at`          | timestamptz   | NOT NULL         |                                          |
| `updated_at`          | timestamptz   | NOT NULL         |                                          |

---

#### `alerts` — Alertas generadas

| Columna             | Tipo          | Restricciones          | Descripción                      |
|---------------------|---------------|------------------------|----------------------------------|
| `id`                | bigserial     | PK                     |                                  |
| `site_id`           | bigint        | FK → sites.id          | NULLABLE (puede ser global)      |
| `alert_rule_id`     | bigint        | FK → alert_rules.id    | NULLABLE                         |
| `title`             | varchar(500)  | NOT NULL               |                                  |
| `message`           | text          | NULLABLE               |                                  |
| `severity`          | varchar(20)   | NOT NULL               |                                  |
| `status`            | varchar(20)   | DEFAULT 'open'         | open/acknowledged/resolved       |
| `triggered_at`      | timestamptz   | NOT NULL               |                                  |
| `acknowledged_at`   | timestamptz   | NULLABLE               |                                  |
| `acknowledged_by`   | bigint        | FK → users.id          | NULLABLE                         |
| `resolved_at`       | timestamptz   | NULLABLE               |                                  |
| `resolved_by`       | bigint        | FK → users.id          | NULLABLE                         |
| `context`           | jsonb         | DEFAULT '{}'           | Contexto detallado de la alerta  |
| `created_at`        | timestamptz   | NOT NULL               |                                  |
| `updated_at`        | timestamptz   | NOT NULL               |                                  |

**Índices:**
```sql
CREATE INDEX idx_alerts_site_status ON alerts(site_id, status, triggered_at DESC);
CREATE INDEX idx_alerts_open ON alerts(triggered_at DESC) WHERE status = 'open';
```

---

#### `notification_channels` — Canales de notificación configurados

| Columna       | Tipo          | Restricciones    | Descripción                              |
|---------------|---------------|------------------|------------------------------------------|
| `id`          | bigserial     | PK               |                                          |
| `name`        | varchar(255)  | NOT NULL         | Nombre descriptivo                       |
| `type`        | varchar(50)   | NOT NULL         | email/slack/teams/telegram/webhook       |
| `config`      | jsonb         | NOT NULL         | Configuración cifrada del canal          |
| `is_active`   | boolean       | DEFAULT true     |                                          |
| `created_at`  | timestamptz   | NOT NULL         |                                          |
| `updated_at`  | timestamptz   | NOT NULL         |                                          |

---

#### `notifications_sent` — Registro de notificaciones enviadas

| Columna       | Tipo          | Restricciones               | Descripción                  |
|---------------|---------------|-----------------------------|------------------------------|
| `id`          | bigserial     | PK                          |                              |
| `alert_id`    | bigint        | FK → alerts.id              |                              |
| `channel_id`  | bigint        | FK → notification_channels.id|                             |
| `status`      | varchar(20)   | DEFAULT 'pending'           | pending/sent/failed          |
| `sent_at`     | timestamptz   | NULLABLE                    |                              |
| `error_message`| text         | NULLABLE                    |                              |
| `created_at`  | timestamptz   | NOT NULL                    |                              |

---

### 3.8 Línea del tiempo

#### `site_events` — Historial de eventos por sitio

| Columna         | Tipo          | Restricciones    | Descripción                                  |
|-----------------|---------------|------------------|----------------------------------------------|
| `id`            | bigserial     | PK               |                                              |
| `site_id`       | bigint        | FK → sites.id    |                                              |
| `event_type`    | varchar(100)  | NOT NULL         | ssl_renewed/site_down/site_up/php_updated/   |
|                 |               |                  | cms_updated/vuln_found/vuln_resolved/        |
|                 |               |                  | scan_completed/check_failed/manual_note      |
| `title`         | varchar(500)  | NOT NULL         | Título legible del evento                    |
| `description`   | text          | NULLABLE         | Descripción detallada                        |
| `severity`      | varchar(20)   | DEFAULT 'info'   | info/warning/error/critical                  |
| `metadata`      | jsonb         | DEFAULT '{}'     | Datos adicionales del evento                 |
| `occurred_at`   | timestamptz   | NOT NULL         | Cuándo ocurrió el evento                     |
| `created_by`    | bigint        | FK → users.id    | NULLABLE (null = sistema)                    |
| `created_at`    | timestamptz   | NOT NULL         |                                              |

**Índices:**
```sql
CREATE INDEX idx_site_events_site_occurred ON site_events(site_id, occurred_at DESC);
CREATE INDEX idx_site_events_type ON site_events(event_type, occurred_at DESC);
```

---

### 3.9 Reportes

#### `reports` — Reportes generados

| Columna        | Tipo          | Restricciones    | Descripción                           |
|----------------|---------------|------------------|---------------------------------------|
| `id`           | bigserial     | PK               |                                       |
| `name`         | varchar(255)  | NOT NULL         | Nombre del reporte                    |
| `type`         | varchar(50)   | NOT NULL         | daily/weekly/monthly/custom           |
| `scope`        | varchar(20)   | NOT NULL         | global/group/site/server              |
| `scope_id`     | bigint        | NULLABLE         | ID del recurso específico             |
| `generated_by` | bigint        | FK → users.id    | NULLABLE (null = automático)          |
| `period_start` | date          | NOT NULL         | Inicio del periodo                    |
| `period_end`   | date          | NOT NULL         | Fin del periodo                       |
| `file_path`    | varchar(500)  | NULLABLE         | Ruta del archivo generado             |
| `status`       | varchar(20)   | DEFAULT 'pending'| pending/generating/ready/failed       |
| `created_at`   | timestamptz   | NOT NULL         |                                       |
| `updated_at`   | timestamptz   | NOT NULL         |                                       |

---

#### `report_schedules` — Programación de reportes automáticos

| Columna              | Tipo          | Restricciones    | Descripción                      |
|----------------------|---------------|------------------|----------------------------------|
| `id`                 | bigserial     | PK               |                                  |
| `name`               | varchar(255)  | NOT NULL         |                                  |
| `report_type`        | varchar(50)   | NOT NULL         |                                  |
| `scope`              | varchar(20)   | NOT NULL         |                                  |
| `scope_id`           | bigint        | NULLABLE         |                                  |
| `frequency`          | varchar(20)   | NOT NULL         | daily/weekly/monthly             |
| `delivery_channels`  | jsonb         | DEFAULT '[]'     | IDs de canales de notificación   |
| `is_active`          | boolean       | DEFAULT true     |                                  |
| `last_run_at`        | timestamptz   | NULLABLE         |                                  |
| `next_run_at`        | timestamptz   | NULLABLE         |                                  |
| `created_at`         | timestamptz   | NOT NULL         |                                  |
| `updated_at`         | timestamptz   | NOT NULL         |                                  |

---

### 3.10 Usuarios y acceso

#### `users` — Usuarios del sistema

| Columna                         | Tipo          | Restricciones    | Descripción                       |
|---------------------------------|---------------|------------------|-----------------------------------|
| `id`                            | bigserial     | PK               |                                   |
| `name`                          | varchar(255)  | NOT NULL         |                                   |
| `email`                         | varchar(255)  | UNIQUE NOT NULL  |                                   |
| `email_verified_at`             | timestamptz   | NULLABLE         |                                   |
| `password`                      | varchar(255)  | NULLABLE         | Null si usa SSO                   |
| `avatar`                        | varchar(500)  | NULLABLE         |                                   |
| `department`                    | varchar(255)  | NULLABLE         | Dependencia UDG                   |
| `is_active`                     | boolean       | DEFAULT true     |                                   |
| `last_login_at`                 | timestamptz   | NULLABLE         |                                   |
| `two_factor_secret`             | text          | NULLABLE         | Cifrado                           |
| `two_factor_recovery_codes`     | text          | NULLABLE         | Cifrado                           |
| `two_factor_confirmed_at`       | timestamptz   | NULLABLE         |                                   |
| `remember_token`                | varchar(100)  | NULLABLE         |                                   |
| `created_at`                    | timestamptz   | NOT NULL         |                                   |
| `updated_at`                    | timestamptz   | NOT NULL         |                                   |

> Los roles y permisos se manejan con `spatie/laravel-permission` (tablas: `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`).

---

### 3.11 Auditoría

#### `access_logs` — Log de accesos al sistema

| Columna           | Tipo          | Restricciones    | Descripción                    |
|-------------------|---------------|------------------|--------------------------------|
| `id`              | bigserial     | PK               |                                |
| `user_id`         | bigint        | FK → users.id    | NULLABLE (intentos fallidos)   |
| `action`          | varchar(50)   | NOT NULL         | login/logout/failed_login      |
| `ip_address`      | inet          | NOT NULL         |                                |
| `user_agent`      | varchar(500)  | NULLABLE         |                                |
| `endpoint`        | varchar(500)  | NULLABLE         |                                |
| `request_method`  | varchar(10)   | NULLABLE         |                                |
| `response_code`   | smallint      | NULLABLE         |                                |
| `duration_ms`     | int           | NULLABLE         |                                |
| `created_at`      | timestamptz   | NOT NULL         |                                |

> La actividad de usuarios en el sistema se registra con `spatie/laravel-activitylog` (tabla `activity_log`).

---

### 3.12 Configuración del sistema

#### `settings` — Configuración global

| Columna       | Tipo          | Restricciones    | Descripción                    |
|---------------|---------------|------------------|--------------------------------|
| `id`          | bigserial     | PK               |                                |
| `key`         | varchar(255)  | UNIQUE NOT NULL  | Clave única (ej: check_interval)|
| `value`       | text          | NULLABLE         | Valor serializado              |
| `type`        | varchar(20)   | DEFAULT 'string' | string/integer/boolean/json    |
| `description` | text          | NULLABLE         | Descripción legible            |
| `is_public`   | boolean       | DEFAULT false    | Si es visible sin autenticación|
| `created_at`  | timestamptz   | NOT NULL         |                                |
| `updated_at`  | timestamptz   | NOT NULL         |                                |

---

## 4. Estrategia de índices

### Tablas de alta frecuencia de escritura

```sql
-- site_checks (escritura cada 5 min × 200 sitios = 40 inserts/min)
CREATE INDEX CONCURRENTLY idx_sc_site_checked ON site_checks(site_id, checked_at DESC);
CREATE INDEX CONCURRENTLY idx_sc_status_recent ON site_checks(status, checked_at DESC)
  WHERE status != 'up';

-- server_metrics
CREATE INDEX CONCURRENTLY idx_sm_server_recorded ON server_metrics(server_id, recorded_at DESC);
```

### Tablas de alta frecuencia de lectura

```sql
-- Dashboard principal (carga sites con su estado y score)
CREATE INDEX CONCURRENTLY idx_sites_dashboard ON sites(current_status, current_score, site_group_id)
  WHERE is_active = true AND is_monitored = true;

-- SSL próximos a vencer
CREATE INDEX CONCURRENTLY idx_ssl_expiry_alert ON ssl_certificates(days_remaining, site_id)
  WHERE days_remaining <= 30 AND is_expired = false;
```

---

## 5. Particionado de tablas de series de tiempo

```sql
-- Ejemplo: site_checks particionada por mes
CREATE TABLE site_checks (
    ...
) PARTITION BY RANGE (checked_at);

CREATE TABLE site_checks_2026_06 PARTITION OF site_checks
    FOR VALUES FROM ('2026-06-01') TO ('2026-07-01');

CREATE TABLE site_checks_2026_07 PARTITION OF site_checks
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');
-- (las particiones futuras se crean automáticamente con un job programado)
```

---

## 6. Retención de datos

| Tabla              | Retención   | Política                              |
|--------------------|-------------|---------------------------------------|
| `site_checks`      | 12 meses    | Eliminar partición al año             |
| `server_metrics`   | 3 meses     | Eliminar partición a los 3 meses      |
| `traffic_metrics`  | 6 meses     | Eliminar partición a los 6 meses      |
| `security_scores`  | Indefinida  | Mantener solo el último por sitio     |
| `alerts`           | Indefinida  | Comprimir resueltos > 6 meses         |
| `site_events`      | Indefinida  | Historial permanente                  |
| `access_logs`      | 6 meses     | Por cumplimiento y auditoría          |
| `activity_log`     | 1 año       | Por cumplimiento y auditoría          |

---

*Última actualización: 2026-06-29 | Versión 1.0*
