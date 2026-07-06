<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use App\Models\CmsDetail;
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
        {--source=docs/right_sites/sitios_udg_marzo.md : Ruta relativa al markdown oficial}
        {--replace : Elimina sitios que no esten en la fuente oficial}
        {--dry-run : No persiste cambios}';

    protected $description = 'Sincroniza el inventario oficial desde el markdown institucional sin descubrimiento adicional.';

    public function handle(): int
    {
        $sourcePath = base_path((string) $this->option('source'));

        if (! is_file($sourcePath)) {
            $this->warn(sprintf('No se encontro la fuente oficial en %s. No se aplicaron cambios.', $sourcePath));

            return self::SUCCESS;
        }

        $parser = InventorySourceParser::default();

        try {
            $rows = $parser->parse($sourcePath);
        } catch (
            \Throwable $exception
        ) {
            $this->error('No se pudo leer el markdown oficial: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->warn('La fuente oficial no contiene filas importables. No se aplicaron cambios.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $replace = (bool) $this->option('replace');
        $domains = [];

        if ($dryRun) {
            DB::beginTransaction();
        } elseif ($replace) {
            DB::statement('TRUNCATE TABLE sites RESTART IDENTITY CASCADE');
        }

        try {
            $groups = [];
            foreach ($rows as $row) {
                $entity = $this->normalizeText((string) ($row['entidad'] ?? 'Sin entidad'));
                $groups[$entity] = $this->upsertGroup($entity, $dryRun);
            }

            $created = 0;
            $updated = 0;

            foreach ($rows as $row) {
                $sourceUrl = trim((string) ($row['dominio'] ?? ''));
                $domain = $this->normalizeDomain($sourceUrl);
                $url = $this->normalizeUrl($sourceUrl, $domain);

                if ($domain === '') {
                    continue;
                }

                $domains[] = $domain;

                $entity = $this->normalizeText((string) ($row['entidad'] ?? 'Sin entidad'));
                $groupId = $groups[$entity];
                $name = trim((string) ($row['nombre_del_sitio'] ?? $row['nombre'] ?? $domain));
                $site = Site::query()->where('domain', $domain)->first();
                $isActive = $this->toBoolean($row['sitio_activo'] ?? null);
                $projectStatus = trim((string) ($row['estatus_proyecto'] ?? ''));
                $comments = trim((string) ($row['comentarios'] ?? ''));
                $cmsType = $this->mapCmsType((string) ($row['cms'] ?? ''));
                $serverIp = trim((string) ($row['ip_servidor'] ?? ''));

                if ($this->isMissingServerMarker($serverIp)) {
                    $serverIp = $this->extractIpFromDomain($domain) ?? '';
                }

                if ($site === null) {
                    $site = new Site();
                    $site->forceFill([
                        'site_group_id' => $groupId,
                        'name' => $name,
                        'slug' => Str::slug($entity . '-' . $domain),
                        'domain' => $domain,
                        'url' => $url,
                        'is_active' => $isActive,
                        'is_monitored' => $isActive,
                        'priority' => $this->priorityFromProjectStatus($projectStatus),
                        'current_status' => 'unknown',
                        'current_score' => 100,
                        'current_score_level' => 'unknown',
                        'check_interval_min' => $isActive ? 5 : 15,
                        'notes' => $this->composeNotes($projectStatus, $comments),
                        'tags' => ['official', 'institutional'],
                    ]);

                    if (! $dryRun) {
                        $site->save();
                        $created++;
                    }
                } else {
                    $site->forceFill([
                        'site_group_id' => $groupId,
                        'name' => $name,
                        'url' => $url,
                        'is_active' => $isActive,
                        'is_monitored' => $isActive,
                        'priority' => $this->priorityFromProjectStatus($projectStatus),
                        'notes' => $this->composeNotes($projectStatus, $comments),
                        'tags' => ['official', 'institutional'],
                    ]);

                    if (! $dryRun) {
                        $site->save();
                        $updated++;
                    }
                }

                if (! $dryRun) {
                    $this->syncServer($site, $serverIp);
                    $this->syncCmsDetail($site, $cmsType, (string) ($row['cms'] ?? ''), $comments);
                }
            }

            if ($replace && ! $dryRun) {
                SiteGroup::query()
                    ->doesntHave('sites')
                    ->delete();
            }

            if ($dryRun) {
                DB::rollBack();
            }

            $this->info(sprintf('Inventario oficial sincronizado. Filas procesadas: %d, creados: %d, actualizados: %d.', count($rows), $created, $updated));

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
        $serverIp = trim($serverIp);

        if ($serverIp === '' || $this->isMissingServerMarker($serverIp)) {
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
            ]
        );

        $site->servers()->syncWithoutDetaching([
            $server->id => ['is_primary' => true],
        ]);
    }

    private function syncCmsDetail(Site $site, string $cmsType, string $cmsLabel, string $comments): void
    {
        if ($cmsType === '') {
            return;
        }

        $payload = [
            'cms_type' => $cmsType,
            'cms_version' => null,
            'db_type' => null,
            'db_version' => null,
            'php_version' => null,
            'php_is_vulnerable' => false,
            'server_software' => null,
            'theme_name' => trim($cmsLabel) !== '' ? trim($cmsLabel) : null,
            'theme_version' => null,
            'modules_count' => 0,
            'has_updates' => false,
            'has_security_updates' => false,
            'last_scanned_at' => now(),
        ];

        CmsDetail::query()->updateOrCreate(
            ['site_id' => (int) $site->id],
            $payload,
        );
    }

    private function normalizeDomain(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = explode('/', $value, 2)[0];

        return mb_strtolower($value);
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

        return preg_match('#^https?://#i', $domain) === 1 ? $domain : 'https://' . $domain;
    }

    private function normalizeUrl(string $sourceUrl, string $domain): string
    {
        $sourceUrl = trim($sourceUrl);

        if ($sourceUrl !== '') {
            if (preg_match('#^https?://#i', $sourceUrl) === 1) {
                return $sourceUrl;
            }

            return 'https://' . ltrim($sourceUrl, '/');
        }

        return $this->buildUrl($domain);
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

        return $projectStatus . ' · ' . $comments;
    }

    private function mapCmsType(string $value): string
    {
        $normalized = mb_strtolower(trim($value));

        return match (true) {
            str_contains($normalized, 'wordpress') => 'wordpress',
            str_contains($normalized, 'd7') || str_contains($normalized, 'd9') || str_contains($normalized, 'd10') || str_contains($normalized, 'drupal') => 'drupal',
            str_contains($normalized, 'php') => 'php',
            str_contains($normalized, 'wix') => 'wix',
            str_contains($normalized, 'joomla') => 'joomla',
            str_contains($normalized, 'magneto') || str_contains($normalized, 'magento') => 'magento',
            $normalized !== '' => 'other',
            default => 'unknown',
        };
    }
}
