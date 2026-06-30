# UDG Sentinel — Plataforma de Monitoreo Institucional

> Plataforma empresarial de observabilidad, seguridad e inventario técnico para los sitios web de la **Universidad de Guadalajara (udg.mx)**.

---

## ¿Qué es UDG Sentinel?

UDG Sentinel no es un dashboard. Es una **plataforma de observabilidad institucional** diseñada para monitorear, analizar y proteger más de 200 sitios web bajo el dominio `udg.mx`, con capacidad de escalar a 2,000+ sitios sin rediseñar su arquitectura.

---

## Características principales

| Módulo               | Descripción                                                                 |
|----------------------|-----------------------------------------------------------------------------|
| **Disponibilidad**   | Monitoreo de uptime con verificación cada 1–5 minutos por sitio            |
| **SSL/TLS**          | Seguimiento de certificados, días para vencer, alertas tempranas           |
| **Rendimiento**      | Tiempo de respuesta con percentiles p50/p95/p99, tendencias históricas      |
| **Tráfico**          | Métricas en tiempo real, detección de picos, análisis de patrones           |
| **Tecnología**       | Detección de CMS (Drupal/Laravel/WP), versiones de PHP, DB, frameworks     |
| **Seguridad**        | Score de salud, headers HTTP, vulnerabilidades CVE, módulos Drupal          |
| **Infraestructura**  | CPU, RAM, disco y carga por servidor                                        |
| **Inventario**       | Catálogo maestro de sitios, dependencias y responsables                    |
| **Alertas**          | Reglas configurables, múltiples canales (email, Teams, Telegram, Slack)    |
| **Reportes**         | PDF automáticos diarios/semanales/mensuales por dependencia                |
| **Línea del tiempo** | Historial de eventos por sitio (caídas, renovaciones SSL, actualizaciones) |
| **Auditoría**        | Registro completo de acciones críticas en el sistema                       |

---

## Stack tecnológico

| Capa              | Tecnología                          |
|-------------------|-------------------------------------|
| Backend           | Laravel 12 (PHP 8.2+)               |
| Base de datos     | PostgreSQL 16                       |
| Cache / Colas     | Redis 7                             |
| Frontend          | Inertia.js 2 + Vue 3 + TypeScript   |
| UI                | Tailwind CSS 4 + Shadcn Vue         |
| Gráficas          | ApexCharts                          |
| Módulos           | nwidart/laravel-modules             |
| Roles/Permisos    | spatie/laravel-permission           |
| Auditoría         | spatie/laravel-activitylog          |
| PDF               | barryvdh/laravel-dompdf             |
| Colas             | Laravel Horizon + Redis             |
| Contenedores      | Docker + Docker Compose             |
| Servidor web      | Nginx                               |
| CI/CD             | GitHub Actions                      |

---

## Estructura del repositorio

```
UDG-Sentinel/
├── backend/                  # Aplicación Laravel 12 (portal principal)
│   ├── app/
│   ├── Modules/              # Módulos independientes
│   │   ├── Inventory/
│   │   ├── Monitoring/
│   │   ├── SSL/
│   │   ├── Technology/
│   │   ├── Security/
│   │   ├── Notifications/
│   │   ├── Reports/
│   │   ├── Auth/
│   │   ├── Audit/
│   │   └── Dashboard/
│   └── ...
├── collectors/               # Recolectores especializados (Python/PHP)
├── infrastructure/
│   ├── docker/               # Definiciones de contenedores
│   ├── nginx/                # Configuración del servidor web
│   └── production/           # Configuraciones de producción
├── docs/                     # Documentación técnica completa
│   ├── architecture/
│   ├── database/
│   ├── api/
│   ├── security/
│   ├── deployment/
│   └── adr/                  # Architecture Decision Records
├── scripts/                  # Scripts de setup, backup, deploy
├── tests/                    # Pruebas de integración e2e
└── .github/workflows/        # CI/CD pipelines
```

---

## Instalación rápida (desarrollo)

### Requisitos previos

- PHP 8.2+
- Composer 2.x
- Node.js 20+
- PostgreSQL 16
- Redis 7
- Docker (opcional pero recomendado)

### Con Docker (recomendado)

```bash
# Clonar repositorio
git clone https://github.com/UDG/udg-sentinel.git
cd udg-sentinel

# Levantar infraestructura
docker compose -f infrastructure/docker/docker-compose.yml up -d

# Instalar dependencias
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app npm install && npm run build

# Acceder al sistema
# http://localhost:8080
```

### Manual

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Configurar .env con datos de DB y Redis
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

---

## Documentación

| Documento                                             | Descripción                        |
|-------------------------------------------------------|------------------------------------|
| [Arquitectura](docs/architecture/Architecture.md)     | Diseño del sistema completo        |
| [Base de datos](docs/database/Database.md)            | Esquema y modelo de datos          |
| [API](docs/api/API.md)                                | Referencia de la API REST          |
| [Seguridad](docs/security/Security.md)                | Políticas y controles de seguridad |
| [Despliegue](docs/deployment/Deployment.md)           | Guía de instalación en producción  |
| [Estándares de código](docs/standars/CodingStandars.md) | Guía de desarrollo                |
| [ADR-001](docs/adr/ADR-001.md)                        | Decisiones de arquitectura         |

---

## Módulos del sistema

Cada módulo es **completamente independiente**. Cambiar un módulo no afecta a los demás.

```
Inventory      → Catálogo de sitios, servidores y dependencias
Monitoring     → Uptime, respuesta, tráfico
SSL            → Certificados y vencimientos
Technology     → Detección de stack tecnológico
Security       → Score de salud, vulnerabilidades, headers
Notifications  → Alertas y canales de notificación
Reports        → Generación de reportes PDF
Auth           → Autenticación y gestión de usuarios
Audit          → Trazabilidad de acciones
Dashboard      → Vistas agregadas y KPIs
```

---

## Score de salud

Cada sitio tiene un **score calculado de 0 a 100** con los siguientes factores:

| Factor                       | Impacto   |
|------------------------------|-----------|
| Sitio caído                  | -30 pts   |
| SSL vencido                  | -25 pts   |
| SSL < 7 días para vencer     | -20 pts   |
| SSL < 30 días para vencer    | -10 pts   |
| PHP versión vulnerable       | -20 pts   |
| CMS desactualizado           | -15 pts   |
| Vulnerabilidad crítica       | -25 pts   |
| Vulnerabilidad alta          | -15 pts   |
| Headers de seguridad faltantes | -8 pts  |
| Sin HTTPS                    | -20 pts   |
| Tiempo de respuesta > 5s     | -15 pts   |
| Tiempo de respuesta > 2s     | -5 pts    |
| Links rotos encontrados      | -5 pts    |

| Score  | Nivel      | Color  |
|--------|------------|--------|
| 90-100 | EXCELENTE  | Verde  |
| 70-89  | BUENO      | Azul   |
| 50-69  | MEDIO      | Amarillo |
| 30-49  | BAJO       | Naranja |
| 1-29   | CRÍTICO    | Rojo   |
| 0      | CAÍDO      | Rojo oscuro |

---

## Seguridad

- HTTPS obligatorio en producción
- Autenticación con 2FA
- RBAC con roles granulares
- Protección CSRF, XSS, SQLi
- Content Security Policy (CSP)
- Rate limiting por endpoint
- Auditoría completa de acciones
- Cifrado de secretos con variables de entorno
- Preparado para integración SSO institucional (SAML 2.0 / OAuth 2.0)

---

## Licencia

Propiedad de la **Universidad de Guadalajara**. Uso interno institucional.

---

## Contacto técnico

Desarrollo: Coordinación de Tecnologías de la Información — UDG  
Repositorio: Acceso restringido — red institucional
