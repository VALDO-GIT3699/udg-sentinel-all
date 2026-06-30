# Estándares de Código — UDG Sentinel

**Versión:** 1.0  
**Fecha:** 2026-06-29  
**Aplicable a:** Todo el código del repositorio  

---

## 1. La regla de oro

> Si no puedes explicar por qué escribiste una línea de código, no la escribas.

---

## 2. Principios de diseño

### SOLID

| Principio             | Descripción                                              | Aplicación en este proyecto                     |
|-----------------------|----------------------------------------------------------|-------------------------------------------------|
| **S** — Single Responsibility | Cada clase tiene una sola razón para cambiar   | Un Service por caso de uso. Un Repository por modelo. |
| **O** — Open/Closed    | Abierto para extensión, cerrado para modificación       | Interfaces en Repositories. Estrategias para el score calculator. |
| **L** — Liskov Substitution | Las subclases deben poder sustituir a sus padres  | Implementar interfaces completas.               |
| **I** — Interface Segregation | Interfaces pequeñas y específicas              | `SiteRepositoryInterface` en lugar de `RepositoryInterface` genérica. |
| **D** — Dependency Inversion | Depender de abstracciones, no de implementaciones | Inyección de dependencias via constructor. Service Container de Laravel. |

### Prohibiciones absolutas

```php
// ❌ NUNCA — Lógica condicional por nombre de sitio
if ($site->domain === 'cucei.udg.mx') {
    // lógica especial
}

// ❌ NUNCA — SQL raw sin bindings
DB::statement("SELECT * FROM sites WHERE domain = '$domain'");

// ❌ NUNCA — Credenciales en código
$password = 'miContraseña123';

// ❌ NUNCA — Consulta N+1
foreach ($sites as $site) {
    echo $site->latestCheck->status; // N+1 query
}

// ❌ NUNCA — $guarded vacío
protected $guarded = [];

// ❌ NUNCA — catch vacío
try {
    // algo
} catch (Exception $e) {
    // silencio
}

// ❌ NUNCA — lógica de negocio en controllers
class SiteController extends Controller {
    public function show($id) {
        $score = 100;
        foreach ($site->vulnerabilities as $v) {
            if ($v->severity === 'critical') $score -= 25;
            // ... 50 líneas de lógica
        }
    }
}
```

---

## 3. Estructura de capas (obligatoria)

```
Request
    │
    ▼
Controller          (Solo: validar, llamar al Service, retornar respuesta)
    │
    ▼
Service             (Lógica de negocio. Orquesta Repositories y otros Services.)
    │
    ▼
Repository          (Consultas a base de datos. Retorna modelos o colecciones.)
    │
    ▼
Model (Eloquent)    (Definición de relaciones, casts, scopes y fillable.)
```

---

## 4. Estándares PHP (Laravel)

### 4.1 Nomenclatura

| Elemento            | Estilo            | Ejemplo                            |
|---------------------|-------------------|------------------------------------|
| Clases              | PascalCase        | `HealthScoreCalculator`            |
| Métodos / funciones | camelCase         | `calculateHealthScore()`           |
| Variables           | camelCase         | `$siteChecks`                      |
| Constantes          | UPPER_SNAKE_CASE  | `MAX_RETRY_ATTEMPTS`               |
| Tablas DB           | snake_case plural | `site_checks`                      |
| Columnas DB         | snake_case        | `response_time_ms`                 |
| Rutas API           | kebab-case plural | `/api/v1/site-checks`              |
| Archivos            | PascalCase (clases), snake_case (configs) | `SiteRepository.php`, `auth.php` |

### 4.2 Controllers

```php
// ✅ Correcto — Controller delgado
class SiteController extends Controller
{
    public function __construct(
        private readonly SiteService $siteService,
    ) {}

    public function show(Site $site): SiteResource
    {
        $this->authorize('view', $site);

        return new SiteResource(
            $this->siteService->getSiteWithDetails($site)
        );
    }
}
```

### 4.3 Services

```php
// ✅ Correcto — Service con lógica de negocio
final class SiteService
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly HealthScoreCalculator $scoreCalculator,
    ) {}

    public function getSiteWithDetails(Site $site): SiteDetailDTO
    {
        $site = $this->siteRepository->findWithRelations($site->id);
        $score = $this->scoreCalculator->calculate($site);

        return SiteDetailDTO::fromModel($site, $score);
    }
}
```

### 4.4 Repositories

```php
// ✅ Correcto — Contrato (interface)
interface SiteRepositoryInterface
{
    public function findWithRelations(int $id): Site;
    public function findAllActive(): Collection;
    public function findByCriticalStatus(): Collection;
}

// ✅ Correcto — Implementación Eloquent
final class EloquentSiteRepository implements SiteRepositoryInterface
{
    public function findWithRelations(int $id): Site
    {
        return Site::with([
            'siteGroup',
            'latestCheck',
            'latestScore',
            'sslCertificate',
        ])->findOrFail($id);
    }

    public function findAllActive(): Collection
    {
        return Site::query()
            ->where('is_active', true)
            ->where('is_monitored', true)
            ->with(['latestCheck', 'latestScore'])
            ->orderBy('current_score')
            ->get();
    }
}
```

### 4.5 Modelos Eloquent

```php
// ✅ Correcto
final class Site extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'site_group_id',
        'name',
        'slug',
        'domain',
        'url',
        'is_active',
        'is_monitored',
        'priority',
        'check_interval_min',
        'notes',
        'tags',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'is_monitored' => 'boolean',
        'tags'         => 'array',
        'last_checked_at' => 'datetime',
    ];

    // Relaciones
    public function siteGroup(): BelongsTo
    {
        return $this->belongsTo(SiteGroup::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(SiteCheck::class);
    }

    public function latestCheck(): HasOne
    {
        return $this->hasOne(SiteCheck::class)->latestOfMany('checked_at');
    }

    // Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_monitored', true);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('current_score', '<', 30);
    }
}
```

### 4.6 Form Requests

```php
// ✅ Correcto — Toda validación en Form Request
final class CreateSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sites.create');
    }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:255'],
            'domain'           => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'url'              => ['required', 'url', 'max:500'],
            'site_group_id'    => ['required', 'integer', 'exists:site_groups,id'],
            'check_interval_min' => ['integer', 'min:1', 'max:60'],
            'priority'         => ['integer', 'in:1,2,3'],
        ];
    }
}
```

### 4.7 Jobs

```php
// ✅ Correcto — Job aislado, con manejo de errores
final class HttpCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30; // segundos entre reintentos

    public function __construct(
        private readonly int $siteId,
    ) {}

    public function handle(HttpCheckerService $checker): void
    {
        $site = Site::findOrFail($this->siteId);
        $checker->check($site);
    }

    public function failed(Throwable $exception): void
    {
        // Registrar el fallo sin lanzar otra excepción
        Log::error('HttpCheckJob falló', [
            'site_id' => $this->siteId,
            'error'   => $exception->getMessage(),
        ]);
    }
}
```

### 4.8 Tipos estrictos

```php
<?php

declare(strict_types=1);
```

**Todos los archivos PHP comienzan con `declare(strict_types=1);`**. Sin excepción.

### 4.9 PHPStan — Nivel de análisis estático

El proyecto opera con **PHPStan nivel 8**. Todo código nuevo debe pasar sin errores antes de hacer merge.

```bash
./vendor/bin/phpstan analyse --level=8
```

---

## 5. Estándares TypeScript / Vue 3

### 5.1 Composables sobre Mixins

```typescript
// ✅ Correcto — Composable
export function useSiteStatus(siteId: Ref<number>) {
  const status = ref<SiteStatus | null>(null)
  const isLoading = ref(false)

  const fetchStatus = async () => {
    isLoading.value = true
    try {
      status.value = await api.getSiteStatus(siteId.value)
    } finally {
      isLoading.value = false
    }
  }

  return { status, isLoading, fetchStatus }
}
```

### 5.2 Tipos explícitos

```typescript
// ✅ Correcto
interface Site {
  id: number
  name: string
  domain: string
  currentStatus: 'up' | 'down' | 'degraded' | 'unknown'
  currentScore: number
  currentScoreLevel: 'excellent' | 'good' | 'medium' | 'low' | 'critical'
  lastCheckedAt: string | null
}

// ❌ Incorrecto
const site: any = {}
```

### 5.3 Componentes Vue 3

```vue
<!-- ✅ Correcto — Script Setup + TypeScript -->
<script setup lang="ts">
import type { Site } from '@/types/site'

interface Props {
  site: Site
}

const props = defineProps<Props>()
const emit = defineEmits<{
  acknowledge: [alertId: number]
}>()
</script>
```

---

## 6. Estándares de base de datos

### 6.1 Migraciones

```php
// ✅ Correcto — Migración clara, reversible, con comentarios
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')
                  ->constrained()
                  ->cascadeOnDelete();
            $table->timestampTz('checked_at');
            $table->string('status', 20);       // up/down/degraded/timeout
            $table->smallInteger('http_code')->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->integer('response_size_bytes')->nullable();
            $table->ipAddress('ip_resolved')->nullable();
            $table->string('redirect_url', 500)->nullable();
            $table->text('error_message')->nullable();
            $table->string('checked_from', 100)->default('sentinel');
            $table->timestampTz('created_at');

            // Índice principal de consultas del dashboard
            $table->index(['site_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_checks');
    }
};
```

### 6.2 Carga ansiosa (Eager Loading) — Obligatorio

```php
// ✅ Correcto
$sites = Site::with(['latestCheck', 'sslCertificate', 'latestScore'])
             ->active()
             ->paginate(50);

// ❌ Incorrecto — N+1 query
$sites = Site::all();
foreach ($sites as $site) {
    echo $site->latestCheck->status; // Una query extra por sitio
}
```

---

## 7. Gestión de errores

```php
// ✅ Correcto
try {
    $result = $this->httpChecker->check($site);
} catch (ConnectionException $e) {
    // Error esperado y manejado específicamente
    $this->recordFailedCheck($site, 'connection_error', $e->getMessage());
} catch (TimeoutException $e) {
    $this->recordFailedCheck($site, 'timeout', $e->getMessage());
} catch (Throwable $e) {
    // Error inesperado — registrar y relanzar
    Log::critical('Error inesperado en HttpChecker', [
        'site_id' => $site->id,
        'error'   => $e->getMessage(),
        'trace'   => $e->getTraceAsString(),
    ]);
    throw $e;
}
```

---

## 8. Testing

### Cobertura mínima requerida

| Capa           | Cobertura mínima | Tipo de prueba                   |
|----------------|------------------|----------------------------------|
| Services       | 80%              | Unit tests                       |
| Jobs           | 80%              | Unit tests + Feature tests       |
| Controllers    | 70%              | Feature tests (HTTP)             |
| Repositories   | 60%              | Integration tests con DB test    |
| Score Calculator | 100%           | Unit tests con todos los casos   |
| Componentes Vue | 60%             | Vitest                           |

### Estructura de pruebas

```
tests/
├── Unit/
│   ├── Services/
│   ├── Jobs/
│   └── Calculators/
├── Feature/
│   ├── Api/
│   └── Web/
└── Integration/
    └── Repositories/

backend/Modules/*/Tests/
├── Unit/
└── Feature/
```

---

## 9. Revisión de código (Code Review)

Todo Pull Request debe cumplir:

- [ ] PHPStan nivel 8 sin errores.
- [ ] `composer test` pasa al 100%.
- [ ] `npm run type-check` pasa.
- [ ] Sin `console.log` ni `dd()` ni `dump()` en código final.
- [ ] Sin código comentado (si se necesita conservar, usar un TODO con issue #número).
- [ ] Las migraciones tienen su método `down()` implementado.
- [ ] Los Jobs tienen `failed()` implementado.
- [ ] Sin credenciales ni tokens en el diff.
- [ ] Sin imports sin usar.

---

## 10. Commits y ramas

### Formato de commits (Conventional Commits)

```
type(scope): descripción en español

feat(monitoring): agregar Job de verificación SSL con alertas
fix(auth): corregir bloqueo de cuenta al cambiar contraseña
refactor(security): extraer lógica de score a HealthScoreCalculator
docs(database): actualizar esquema con tabla drupal_modules
test(monitoring): agregar pruebas para HttpCheckJob
chore(deps): actualizar spatie/laravel-permission a 6.x
```

### Flujo de ramas

```
main           → producción estable
develop        → integración de features
feature/xyz    → desarrollo de características
fix/xyz        → corrección de bugs
hotfix/xyz     → corrección urgente en producción
```

---

*Última actualización: 2026-06-29 | Versión 1.0*
