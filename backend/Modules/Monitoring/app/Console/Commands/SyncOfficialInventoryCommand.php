<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Services\Reconciliation\InventorySourceParser;

final class SyncOfficialInventoryCommand extends Command
{
    protected $signature = 'monitoring:sync-official-inventory
        {--source=docs/right_sites/true_sites.csv : Ruta relativa de la fuente oficial (CSV/MD)}
        {--replace : Elimina sitios que no esten en la fuente oficial}
        {--merge-only : Modo aditivo: solo agrega sitios cuyo dominio no exista ya; nunca actualiza ni purga sitios existentes. Ignora --replace}
        {--dry-run : No persiste cambios}';

    protected $description = 'Sincroniza el inventario oficial desde el markdown institucional sin descubrimiento adicional.';

    public function handle(): int
    {
        $sourceOption = (string) $this->option('source');
        $sourcePath = $this->resolveSourcePath($sourceOption);

        if ($sourcePath === null) {
            $this->warn(sprintf('No se encontro la fuente oficial en %s. No se aplicaron cambios.', $sourceOption));

            return self::SUCCESS;
        }

        $parser = InventorySourceParser::default();

        try {
            $rows = $parser->parse($sourcePath);
        } catch (
            \Throwable $exception
        ) {
            $this->error('No se pudo leer el markdown oficial: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->warn('La fuente oficial no contiene filas importables. No se aplicaron cambios.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $replace = (bool) $this->option('replace');
        $mergeOnly = (bool) $this->option('merge-only');

        if ($mergeOnly && $replace) {
            $this->warn('--replace se ignora porque --merge-only esta activo (modo puramente aditivo).');
            $replace = false;
        }

        // En modo aditivo, la identidad es dominio-canonico + ruta de la URL: distintas
        // paginas del mismo dominio (p. ej. una carrera por ruta bajo el mismo campus)
        // son sitios distintos que el motor de inspeccion revisa por separado, ya que
        // escanea la URL completa, no solo el dominio. Solo se omite una fila cuando
        // esa combinacion exacta (dominio + ruta) ya esta registrada.
        $existingIdentities = $mergeOnly
            ? Site::query()->get(['domain', 'url'])
                ->map(fn (Site $site): string => $this->siteIdentity((string) $site->domain, (string) $site->url))
                ->filter(fn (string $identity): bool => $identity !== '')
                ->flip()
                ->all()
            : [];

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            $groups = [];

            foreach ($rows as $row) {
                $entity = $this->normalizeText((string) ($row['entidad'] ?? 'Sin entidad'));
                $groups[$entity] = $this->upsertGroup($entity, $dryRun);
            }

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $touchedSiteIds = [];
            $rowKeyOccurrences = [];

            foreach ($rows as $row) {
                $rowKeySeed = $this->buildOfficialRowKeySeed($row);
                $occurrence = $rowKeyOccurrences[$rowKeySeed] ?? 0;
                $rowKeyOccurrences[$rowKeySeed] = $occurrence + 1;
                $rowKey = $this->buildOfficialRowKey($rowKeySeed, $occurrence);
                $sourceUrl = trim((string) ($row['dominio'] ?? ''));
                $domain = $this->normalizeDomain($sourceUrl);
                $url = $this->normalizeUrl($sourceUrl, $domain);

                if ($domain === '') {
                    $domain = $this->buildPlaceholderDomain($rowKey);
                }

                if ($url === '') {
                    $url = $this->buildUrl($domain);
                }

                if ($mergeOnly) {
                    $identity = $this->siteIdentity($domain, $url);

                    if (isset($existingIdentities[$identity])) {
                        $skipped++;
                        continue;
                    }
                }

                $entity = $this->normalizeText((string) ($row['entidad'] ?? 'Sin entidad'));
                $groupId = $groups[$entity];
                $name = trim((string) ($row['nombre_del_sitio'] ?? $row['nombre'] ?? $domain));
                $site = $mergeOnly ? null : Site::query()->whereJsonContains('tags', $rowKey)->first();
                $isActive = $this->toBoolean($row['sitio_activo'] ?? null);
                $isMonitored = $isActive && ! $this->isSyntheticDomain($domain);
                $projectStatus = trim((string) ($row['estatus_proyecto'] ?? ''));
                $comments = trim((string) ($row['comentarios'] ?? ''));
                $serverIp = $this->sanitizeServerIp((string) ($row['ip_servidor'] ?? ''));

                if ($this->isMissingServerMarker($serverIp)) {
                    $serverIp = $this->extractIpFromDomain($domain) ?? '';
                }

                if ($site === null) {
                    $site = new Site;
                    $site->forceFill([
                        'site_group_id' => $groupId,
                        'name' => $name,
                        'slug' => $this->buildUniqueSlug($entity, $name, $domain, $rowKey),
                        'domain' => $domain,
                        'url' => $url,
                        'is_active' => $isActive,
                        'is_monitored' => $isMonitored,
                        'priority' => $this->priorityFromProjectStatus($projectStatus),
                        'current_status' => 'unknown',
                        'current_score' => 100,
                        'current_score_level' => 'unknown',
                        'check_interval_min' => $isActive ? 5 : 15,
                        'notes' => $this->composeNotes($projectStatus, $comments),
                        'tags' => ['official', 'institutional', $rowKey],
                    ]);

                    if (! $dryRun) {
                        $site->save();
                        $created++;
                    }

                    if ($mergeOnly) {
                        // Evita crear dos sitios si la misma fuente trae exactamente la
                        // misma combinacion dominio+ruta repetida en mas de una fila.
                        $existingIdentities[$this->siteIdentity($domain, $url)] = true;
                    }
                } else {
                    $site->forceFill([
                        'site_group_id' => $groupId,
                        'name' => $name,
                        'domain' => $domain,
                        'url' => $url,
                        'is_active' => $isActive,
                        'is_monitored' => $isMonitored,
                        'priority' => $this->priorityFromProjectStatus($projectStatus),
                        'notes' => $this->composeNotes($projectStatus, $comments),
                        'tags' => ['official', 'institutional', $rowKey],
                    ]);

                    if (! $dryRun) {
                        $site->save();
                        $updated++;
                    }
                }

                if (! $dryRun) {
                    $this->syncServer($site, $serverIp);
                    $touchedSiteIds[] = (int) $site->id;
                }
            }

            // "--replace" retira (soft-delete) los sitios oficiales que ya no aparecen en la
            // fuente, sin tocar los que siguen presentes ni su historial de monitoreo. Nunca
            // se trunca la tabla completa: eso borraría también sitios dados de alta a mano.
            if ($replace && ! $dryRun && $touchedSiteIds !== []) {
                Site::query()
                    ->whereJsonContains('tags', 'official')
                    ->whereNotIn('id', $touchedSiteIds)
                    ->delete();
            }

            if ($replace && ! $dryRun) {
                SiteGroup::query()
                    ->doesntHave('sites')
                    ->delete();
            }

            if ($dryRun) {
                DB::rollBack();
            }

            $this->info(sprintf(
                'Inventario oficial sincronizado. Filas procesadas: %d, creados: %d, actualizados: %d, ya existentes (omitidos): %d.',
                count($rows),
                $created,
                $updated,
                $skipped,
            ));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            if ($dryRun && DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }
    }

    private function upsertGroup(string $entity, bool $dryRun): int
    {
        $slug = Str::slug($entity);
        $group = SiteGroup::query()->where('slug', $slug)->first();

        if ($group !== null) {
            return (int) $group->id;
        }

        if ($dryRun) {
            return 0;
        }

        $group = SiteGroup::query()->create([
            'name' => $entity,
            'slug' => $slug,
            'description' => 'Dependencia institucional importada desde el inventario oficial.',
            'color' => '#0F766E',
        ]);

        return (int) $group->id;
    }

    private function syncServer(Site $site, string $serverIp): void
    {
        $serverIp = $this->sanitizeServerIp($serverIp);

        if ($serverIp === '' || $this->isMissingServerMarker($serverIp)) {
            return;
        }

        if (! $this->isValidIpv4($serverIp)) {
            return;
        }

        $server = Server::query()->firstOrCreate(
            ['ip_address' => $serverIp],
            [
                'name' => $site->name,
                'hostname' => (string) $site->domain,
                'os' => 'unknown',
                'provider' => 'institutional',
                'location' => 'unknown',
                'ssh_port' => 22,
                'ssh_user' => 'unknown',
                'is_accessible' => false,
                'notes' => 'Servidor importado desde el inventario oficial.',
            ],
        );

        $site->servers()->syncWithoutDetaching([
            $server->id => ['is_primary' => true],
        ]);
    }

    private function normalizeDomain(string $value): string
    {
        $value = trim($value);

        if ($value === '' || mb_strtolower($value) === 'nd') {
            return '';
        }

        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = explode('/', $value, 2)[0];

        return mb_strtolower($value);
    }

    /**
     * Mismo criterio de EloquentSiteRepository::dashboardCanonicalDomainSql para
     * reconocer "www.foo.udg.mx" y "foo.udg.mx" como el mismo sitio en --merge-only.
     */
    private function canonicalDomain(string $domain): string
    {
        return (string) preg_replace('/^(?:(?:www\d*|portal\d*|web\d*|home)\.)+/i', '', mb_strtolower(trim($domain)));
    }

    /**
     * Identidad para --merge-only: dominio canonico + ruta de la URL. Dos filas del
     * mismo dominio pero con rutas distintas (p. ej. una pagina por carrera bajo el
     * mismo campus) son sitios distintos, porque el motor de inspeccion escanea la
     * URL completa y puede detectar fallas por pagina que no aplican al resto.
     */
    private function siteIdentity(string $domain, string $url): string
    {
        $canonicalDomain = $this->canonicalDomain($domain);

        if ($canonicalDomain === '') {
            return '';
        }

        $path = rtrim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');

        return $canonicalDomain.'|'.mb_strtolower($path);
    }

    private function extractIpFromDomain(string $domain): ?string
    {
        $candidate = trim($domain);

        if ($candidate === '') {
            return null;
        }

        return preg_match('/^(?:\d{1,3}\.){3}\d{1,3}$/', $candidate) === 1 ? $candidate : null;
    }

    private function isMissingServerMarker(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));

        return $normalized === '' || in_array($normalized, ['externo', 'no tiene', 'no', 'sin dato', 'na', 'n/a'], true);
    }

    private function sanitizeServerIp(string $value): string
    {
        $normalized = trim($value);

        if ($normalized === '') {
            return '';
        }

        // Algunas filas del CSV llegan con IP separada por comas en lugar de puntos.
        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function isValidIpv4(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private function normalizeText(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = mb_strtolower($this->normalizeText((string) $value));

        return in_array($normalized, ['1', 'true', 'si', 'sí', 'activo', 'activa', 'yes', 'y'], true);
    }

    private function buildUrl(string $domain): string
    {
        $domain = trim($domain);

        if ($domain === '') {
            return '';
        }

        return preg_match('#^https?://#i', $domain) === 1 ? $domain : 'https://'.$domain;
    }

    private function normalizeUrl(string $sourceUrl, string $domain): string
    {
        $sourceUrl = trim($sourceUrl);

        if ($sourceUrl !== '' && mb_strtolower($sourceUrl) === 'nd') {
            return '';
        }

        if ($sourceUrl !== '') {
            if (preg_match('#^https?://#i', $sourceUrl) === 1) {
                return $sourceUrl;
            }

            return 'https://'.ltrim($sourceUrl, '/');
        }

        return $this->buildUrl($domain);
    }

    /**
     * Contenido estable de la fila (entidad+nombre+dominio), independiente de su
     * posicion en el archivo. Reordenar o insertar filas en la fuente oficial no
     * debe hacer que una fila existente parezca "nueva" en la proxima sincronizacion.
     *
     * @param  array<string, mixed>  $row
     */
    private function buildOfficialRowKeySeed(array $row): string
    {
        $entity = mb_strtolower($this->normalizeText((string) ($row['entidad'] ?? '')));
        $name = mb_strtolower($this->normalizeText((string) ($row['nombre_del_sitio'] ?? $row['nombre'] ?? '')));
        $domain = mb_strtolower($this->normalizeText((string) ($row['dominio'] ?? '')));

        return implode('|', [$entity, $name, $domain]);
    }

    /**
     * $occurrence distingue filas con contenido identico dentro de la misma corrida
     * (p. ej. dos altas legitimas para el mismo dominio) sin depender del indice
     * absoluto de la fila, que cambia cada vez que se edita la fuente oficial.
     */
    private function buildOfficialRowKey(string $rowKeySeed, int $occurrence): string
    {
        $hash = sha1($rowKeySeed);

        return $occurrence > 0
            ? 'official-row:'.$hash.'-'.$occurrence
            : 'official-row:'.$hash;
    }

    private function buildPlaceholderDomain(string $rowKey): string
    {
        return 'sin-dominio-'.substr(sha1($rowKey), 0, 16).'.invalid';
    }

    private function isSyntheticDomain(string $domain): bool
    {
        return str_ends_with($domain, '.invalid');
    }

    private function buildUniqueSlug(string $entity, string $name, string $domain, string $rowKey): string
    {
        $seed = trim($entity.' '.$name.' '.$domain);
        $base = Str::slug($seed !== '' ? $seed : 'sitio-oficial');

        if ($base === '') {
            $base = 'sitio-oficial';
        }

        $suffix = substr(sha1($rowKey), 0, 8);
        $maxBaseLength = 100 - 1 - strlen($suffix);
        $base = mb_substr($base, 0, $maxBaseLength);
        $base = rtrim($base, '-');

        if ($base === '') {
            $base = 'sitio-oficial';
            $base = mb_substr($base, 0, $maxBaseLength);
            $base = rtrim($base, '-');
        }

        $slug = $base.'-'.$suffix;

        if (! Site::query()->where('slug', $slug)->exists()) {
            return $slug;
        }

        $i = 2;

        while (Site::query()->where('slug', $slug.'-'.$i)->exists()) {
            $i++;
        }

        return $slug.'-'.$i;
    }

    private function priorityFromProjectStatus(string $status): int
    {
        $status = mb_strtolower(trim($status));

        if ($status === '' || str_contains($status, 'migrado')) {
            return 2;
        }

        if (str_contains($status, 'solicitud') || str_contains($status, 'baja') || str_contains($status, 'inaccesible')) {
            return 3;
        }

        return 2;
    }

    private function composeNotes(string $projectStatus, string $comments): string
    {
        $projectStatus = trim($projectStatus);
        $comments = trim($comments);

        if ($projectStatus === '' && $comments === '') {
            return '';
        }

        if ($projectStatus === '') {
            return $comments;
        }

        if ($comments === '') {
            return $projectStatus;
        }

        return $projectStatus.' · '.$comments;
    }

    private function hasSignificantPath(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return $path !== '' && $path !== '/';
    }

    private function resolveSourcePath(string $source): ?string
    {
        $source = trim($source);

        if ($source === '') {
            return null;
        }

        $candidates = [];

        if ($this->isAbsolutePath($source)) {
            $candidates[] = $source;
        } else {
            $candidates[] = base_path($source);
            $candidates[] = base_path('../'.ltrim($source, '/\\'));
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $realPath = realpath($candidate);

                return $realPath !== false ? $realPath : $candidate;
            }
        }

        return null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1 || str_starts_with($path, '/');
    }
}
