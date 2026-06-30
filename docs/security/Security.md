# Seguridad — UDG Sentinel

**Versión:** 1.0  
**Fecha:** 2026-06-29  
**Referencia:** OWASP Top 10 (2021), OWASP ASVS Level 2  

---

## 1. Principio rector

> La seguridad no es una característica que se añade al final. Es una decisión de diseño que se toma desde el primer commit.

---

## 2. Controles por capa

### 2.1 Capa de red y transporte

| Control                    | Implementación                                              | Obligatorio |
|----------------------------|-------------------------------------------------------------|-------------|
| TLS 1.2+ obligatorio       | Nginx: `ssl_protocols TLSv1.2 TLSv1.3;`                   | Sí          |
| TLS 1.0 / 1.1 deshabilitado| Nginx: protocolo mínimo TLSv1.2                            | Sí          |
| HSTS                       | `Strict-Transport-Security: max-age=31536000; includeSubDomains` | Sí   |
| Redireccion HTTP → HTTPS   | Nginx 301 permanente                                        | Sí          |
| Rate Limiting global       | Nginx: `limit_req_zone` 100 req/min por IP                 | Sí          |
| Rate Limiting en login     | Laravel: `throttle:5,1` (5 intentos por minuto)            | Sí          |
| Firewall de aplicación     | Reglas Nginx + middleware Laravel personalizado             | Sí          |

### 2.2 Capa de aplicación (Laravel)

| Control                   | Implementación                                                  |
|---------------------------|-----------------------------------------------------------------|
| Protección CSRF           | Laravel VerifyCsrfToken middleware en todas las rutas web       |
| Prevención XSS            | Blade escapa automáticamente. `{!! !!}` solo donde sea necesario y revisado. |
| Prevención SQLi           | Eloquent ORM + query bindings. Cero SQL raw sin bindeo.         |
| Mass Assignment            | `$fillable` explícito en todos los modelos. Nunca `$guarded = []`. |
| Content Security Policy   | Middleware CSP con directivas estrictas                         |
| X-Frame-Options           | `DENY` en todas las páginas                                     |
| X-Content-Type-Options    | `nosniff` en todas las respuestas                               |
| Referrer-Policy           | `strict-origin-when-cross-origin`                               |
| Permissions-Policy        | Deshabilitar APIs del navegador no usadas                       |

### 2.3 Autenticación y autorización

| Control                          | Implementación                                        |
|----------------------------------|-------------------------------------------------------|
| Autenticación robusta            | Email + password (bcrypt cost 12) + 2FA opcional     |
| Autenticación de dos factores    | TOTP (Google Authenticator/Authy) + códigos de recuperación |
| SSO-ready                        | Arquitectura preparada para SAML 2.0 / OAuth 2.0 institucional |
| RBAC granular                    | spatie/laravel-permission: roles y permisos por módulo |
| Sesiones seguras                 | Redis, `httponly`, `secure`, `samesite=strict`        |
| Expiración de sesión             | 8 horas de inactividad en producción                  |
| Invalidación de sesión           | Al cambiar contraseña o desde panel de administración |
| Bloqueo de cuenta                | Tras 10 intentos fallidos en 1 hora → bloqueo temporal |
| Verificación de email            | Obligatoria para cuentas nuevas                       |

### 2.4 Gestión de secretos y configuración

| Control                          | Implementación                                         |
|----------------------------------|--------------------------------------------------------|
| Cero credenciales en código      | Todo en `.env`. `.env` no se versiona nunca.           |
| `.env.example` documentado       | Cada variable con comentario explicativo               |
| Cifrado de campos sensibles      | `encrypted:` cast de Laravel para tokens de canales    |
| Variables de entorno en Docker   | Docker secrets o HashiCorp Vault en producción         |
| Rotación de APP_KEY              | Procedimiento documentado en runbooks                  |
| API tokens                       | Laravel Sanctum, tokens con expiración, scope limitado |

### 2.5 Auditoría y trazabilidad

| Evento auditado                        | Destino                    |
|----------------------------------------|----------------------------|
| Login exitoso / fallido                | access_logs                |
| Logout                                 | access_logs                |
| Creación/edición/eliminación de sitios | activity_log (Spatie)      |
| Cambio de roles/permisos               | activity_log               |
| Acceso a datos sensibles               | activity_log               |
| Cambio de configuración global         | activity_log               |
| Generación de reportes                 | activity_log               |
| Acciones de administración             | activity_log               |
| Cambio de contraseña                   | activity_log               |
| Activación/desactivación de 2FA        | activity_log               |

---

## 3. Roles y permisos del sistema

### 3.1 Roles predefinidos

| Rol             | Descripción                                              |
|-----------------|----------------------------------------------------------|
| `super_admin`   | Acceso total. Solo 1-2 personas. Sin restricciones.      |
| `admin`         | Gestión de usuarios, sitios, servidores y configuración. |
| `operator`      | Monitoreo operativo: ver alertas, reconocer incidentes, ver reportes. |
| `viewer`        | Solo lectura. Dashboard y métricas sin configuración.    |

### 3.2 Matriz de permisos por módulo

| Permiso                         | super_admin | admin | operator | viewer |
|---------------------------------|-------------|-------|----------|--------|
| `sites.view`                    | ✅          | ✅    | ✅       | ✅     |
| `sites.create`                  | ✅          | ✅    | ❌       | ❌     |
| `sites.edit`                    | ✅          | ✅    | ❌       | ❌     |
| `sites.delete`                  | ✅          | ✅    | ❌       | ❌     |
| `monitoring.view`               | ✅          | ✅    | ✅       | ✅     |
| `monitoring.configure`          | ✅          | ✅    | ❌       | ❌     |
| `alerts.view`                   | ✅          | ✅    | ✅       | ✅     |
| `alerts.acknowledge`            | ✅          | ✅    | ✅       | ❌     |
| `alerts.resolve`                | ✅          | ✅    | ✅       | ❌     |
| `reports.view`                  | ✅          | ✅    | ✅       | ✅     |
| `reports.generate`              | ✅          | ✅    | ✅       | ❌     |
| `security.view`                 | ✅          | ✅    | ✅       | ✅     |
| `security.scan`                 | ✅          | ✅    | ❌       | ❌     |
| `users.view`                    | ✅          | ✅    | ❌       | ❌     |
| `users.manage`                  | ✅          | ✅    | ❌       | ❌     |
| `settings.view`                 | ✅          | ✅    | ❌       | ❌     |
| `settings.edit`                 | ✅          | ❌    | ❌       | ❌     |
| `audit.view`                    | ✅          | ✅    | ❌       | ❌     |

---

## 4. Política de contraseñas

- Mínimo 12 caracteres.
- Al menos una letra mayúscula, una minúscula, un número y un carácter especial.
- No puede ser igual a las 5 contraseñas anteriores.
- Expiración: 180 días (configurable por el admin).
- Verificación contra lista de contraseñas comunes (Have I Been Pwned API o lista local).

---

## 5. Headers HTTP de seguridad (producción)

```nginx
# Configuración Nginx para headers de seguridad
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'nonce-{NONCE}'; style-src 'self' 'nonce-{NONCE}'; img-src 'self' data: https:; font-src 'self'; connect-src 'self' wss:; frame-ancestors 'none';" always;
```

---

## 6. OWASP Top 10 — Cobertura

| OWASP Top 10 (2021)                            | Medida de mitigación                                           |
|------------------------------------------------|----------------------------------------------------------------|
| A01 — Broken Access Control                    | RBAC + Policies Eloquent + middleware de autorización          |
| A02 — Cryptographic Failures                   | TLS 1.2+, bcrypt cost 12, cifrado de campos sensibles, HSTS    |
| A03 — Injection                                | Eloquent ORM, query bindings, validación estricta de entradas  |
| A04 — Insecure Design                          | Revisión de arquitectura en ADR, threat modeling previo al desarrollo |
| A05 — Security Misconfiguration               | Variables de entorno, headers de seguridad, Docker hardening   |
| A06 — Vulnerable and Outdated Components       | Dependabot/Renovate en GitHub Actions, auditoría semanal       |
| A07 — Identification and Authentication Failures | 2FA, rate limiting en login, sesiones seguras, bloqueo de cuentas |
| A08 — Software and Data Integrity Failures     | Verificación de integridad de paquetes (composer.lock, package-lock.json), CI verificado |
| A09 — Security Logging and Monitoring Failures | activity_log, access_logs, alertas internas del sistema        |
| A10 — Server-Side Request Forgery (SSRF)       | Validación estricta de URLs en recolectores, lista blanca de dominios |

---

## 7. Proceso de respuesta a incidentes

1. **Detección**: el sistema genera alerta automática o el operador la detecta.
2. **Clasificación**: nivel de severidad (critical/high/medium/low).
3. **Contención**: si es crítico, el operador puede desactivar el monitoreo del sitio afectado temporalmente.
4. **Investigación**: revisar `activity_log` y `access_logs` para determinar el origen.
5. **Remediación**: aplicar corrección según runbook correspondiente.
6. **Documentación**: registrar el incidente en `site_events` con `created_by` del operador.
7. **Revisión post-incidente**: análisis de causa raíz documentado.

---

## 8. Actualizaciones de seguridad

- **Dependabot** configurado para detectar vulnerabilidades en composer y npm.
- Pipeline CI ejecuta `composer audit` y `npm audit` en cada push.
- Las actualizaciones de seguridad se aplican en un máximo de **72 horas** tras la publicación del CVE.
- Las actualizaciones de PHP y PostgreSQL siguen el calendario de soporte oficial.

---

## 9. Backups y recuperación

- Backup automático de PostgreSQL cada 24 horas.
- Retención de backups: 30 días.
- Backup cifrado en reposo (AES-256).
- Procedimiento de restauración documentado y probado trimestralmente.
- RTO objetivo: < 4 horas. RPO objetivo: < 24 horas.

---

*Última actualización: 2026-06-29 | Versión 1.0*
