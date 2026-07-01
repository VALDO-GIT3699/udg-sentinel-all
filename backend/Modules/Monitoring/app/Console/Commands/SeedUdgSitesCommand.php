<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use App\Models\Site;
use App\Models\SiteGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class SeedUdgSitesCommand extends Command
{
    protected $signature = 'monitoring:seed-udg-sites
        {--dry-run : Solo muestra lo que se insertaria/actualizaria sin guardar cambios}
        {--validate-live : Intenta conservar solo hosts con respuesta HTTP o DNS}
        {--target=220 : Objetivo minimo de sitios oficiales a cargar}';

    protected $description = 'Descubre y registra mas de 200 sitios oficiales *.udg.mx para el dashboard de monitoreo.';

    /**
     * @var array<string, array{name: string, color: string, description: string}>
     */
    private const GROUPS = [
        'sistemas-criticos' => [
            'name' => 'Sistemas Criticos',
            'color' => '#B91C1C',
            'description' => 'Sistemas transaccionales y de alto impacto operativo.',
        ],
        'centros-universitarios' => [
            'name' => 'Centros Universitarios',
            'color' => '#2563EB',
            'description' => 'Portales de centros universitarios y extensiones regionales.',
        ],
        'sems-prepas' => [
            'name' => 'SEMS y Preparatorias',
            'color' => '#CA8A04',
            'description' => 'Escuelas del Sistema de Educacion Media Superior.',
        ],
        'administracion-general' => [
            'name' => 'Administracion General',
            'color' => '#0F766E',
            'description' => 'Rectoria, coordinaciones y dependencias institucionales.',
        ],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $validateLive = (bool) $this->option('validate-live');
        $target = max(200, (int) $this->option('target'));

        $catalog = $this->buildOfficialCatalog($target);

        if ($validateLive) {
            $this->line('Validando hosts con verificacion DNS/HTTP, puede tomar algunos minutos...');
            $catalog = $this->filterReachableSites($catalog, $target);
        }

        if ($dryRun) {
            $this->warn('Modo dry-run activo: no se guardaran cambios.');
            DB::beginTransaction();
        }

        try {
            $cleanup = $this->purgeNonOfficialData($catalog);
            $groups = $this->upsertGroups();
            $sites = $this->upsertSites($groups['ids'], $catalog);
            $postCleanup = $this->purgeSitesOutsideOfficialCatalog($catalog);

            if ($dryRun) {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            if ($dryRun && DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $e;
        }

        $this->newLine();
        $this->info('Carga de catalogo UDG finalizada.');
        $this->table(
            ['Elemento', 'Creados', 'Actualizados', 'Sin cambios'],
            [
                ['Sitios en catalogo', count($catalog), 0, 0],
                ['Sitios basura eliminados', $cleanup['sites_removed'], 0, 0],
                ['Grupos basura eliminados', $cleanup['groups_removed'], 0, 0],
                ['Grupos', $groups['stats']['created'], $groups['stats']['updated'], $groups['stats']['unchanged']],
                ['Sitios', $sites['created'], $sites['updated'], $sites['unchanged']],
                ['Sitios no oficiales eliminados', $postCleanup['sites_removed'], 0, 0],
                ['Grupos no oficiales eliminados', $postCleanup['groups_removed'], 0, 0],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildOfficialCatalog(int $target): array
    {
        $catalog = [];

        $this->appendCriticalSystems($catalog);
        $this->appendUniversityCenters($catalog);
        $this->appendSemsAndPrepas($catalog);
        $this->appendAdministration($catalog);
        $this->appendPatternDiscovery($catalog);

        usort($catalog, static function (array $a, array $b): int {
            return strcmp((string) $a['domain'], (string) $b['domain']);
        });

        if (count($catalog) < $target) {
            $this->warn(sprintf(
                'Catalogo generado con %d sitios. Sugerencia: usa --validate-live=false o incrementa patrones.',
                count($catalog)
            ));
        }

        return $catalog;
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     */
    private function appendCriticalSystems(array &$catalog): void
    {
        $systems = [
            ['Portal Principal UDG', 'www.udg.mx', 1, 2],
            ['Portal UDG', 'udg.mx', 1, 2],
            ['SIIAU Escolar', 'siiauescolar.siiau.udg.mx', 1, 2],
            ['SIIAU Publico', 'siiau.udg.mx', 1, 2],
            ['SIIAU Personal', 'siiaupersonal.siiau.udg.mx', 1, 2],
            ['SIIAU Servicios', 'servicios.siiau.udg.mx', 1, 2],
            ['Correo UDG', 'correo.udg.mx', 1, 2],
            ['Identidad UDG', 'identidad.udg.mx', 1, 2],
            ['Bibliotecas UDG', 'bibliotecas.udg.mx', 1, 3],
            ['Repositorio UDG', 'riudg.udg.mx', 2, 5],
            ['Campus Virtual UDG', 'virtual.udg.mx', 1, 2],
            ['CGAI UDG', 'cgai.udg.mx', 2, 5],
            ['CGTI UDG', 'cgti.udg.mx', 2, 5],
            ['Academicos UDG', 'academicos.udg.mx', 2, 5],
        ];

        foreach ($systems as [$name, $host, $priority, $interval]) {
            $this->pushSite(
                catalog: $catalog,
                group: 'sistemas-criticos',
                name: (string) $name,
                domain: (string) $host,
                priority: (int) $priority,
                checkInterval: (int) $interval,
                notes: 'Sitio critico institucional UDG.',
                tags: ['udg', 'discovery', 'critical']
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     */
    private function appendUniversityCenters(array &$catalog): void
    {
        $centers = [
            'cucea' => 'CUCEA',
            'cucei' => 'CUCEI',
            'cucs' => 'CUCS',
            'cucsh' => 'CUCSH',
            'cucba' => 'CUCBA',
            'cuc' => 'CUCosta',
            'cuci' => 'CUCienega',
            'cutonala' => 'CUTonala',
            'cuvalles' => 'CUValles',
            'culagos' => 'CULagos',
            'cucosta' => 'CUCosta',
            'cucsur' => 'CUCSur',
            'cucsur2' => 'CUCSur Extension',
            'cualtos' => 'CUALTOS',
            'cunorte' => 'CUNorte',
            'cusur' => 'CUSur',
            'cunorte2' => 'CUNorte Extension',
            'cunortevirtual' => 'CUNorte Virtual',
            'cucienega' => 'CUCienega',
            'cuv' => 'CUV',
        ];

        $suffixes = [
            '' => 'Portal',
            'www.' => 'Portal WWW',
            'escolar.' => 'Escolar',
            'biblioteca.' => 'Biblioteca',
            'posgrados.' => 'Posgrados',
            'investigacion.' => 'Investigacion',
        ];

        foreach ($centers as $subdomain => $label) {
            $baseHost = $subdomain . '.udg.mx';

            foreach ($suffixes as $prefix => $suffixLabel) {
                $host = $prefix . $baseHost;
                $name = sprintf('%s - %s', $label, $suffixLabel);

                $this->pushSite(
                    catalog: $catalog,
                    group: 'centros-universitarios',
                    name: $name,
                    domain: $host,
                    priority: $prefix === '' || $prefix === 'www.' ? 2 : 3,
                    checkInterval: $prefix === '' || $prefix === 'www.' ? 5 : 10,
                    notes: 'Centro universitario UDG detectado por catalogo expandido.',
                    tags: ['udg', 'centro-universitario']
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     */
    private function appendSemsAndPrepas(array &$catalog): void
    {
        $regionalAliases = [
            'el-salto',
            'tonala',
            'tlajomulco',
            'zapopan',
            'tepatitlan',
            'ocotlan',
            'autlan',
            'lagos',
            'tamazula',
            'jocotepec',
            'atotonilco',
            'colotlan',
            'tequila',
            'zapotlanejo',
            'san-juan',
            'encarnacion',
            'ameca',
            'la-barca',
            'sayula',
            'ciudad-guzman',
            'tala',
            'arandas',
            'zapotiltic',
            'teocaltiche',
            'union-de-tula',
            'jamay',
            'chapala',
            'mazamitla',
            'degollado',
            'yahualica',
        ];

        for ($i = 1; $i <= 120; $i++) {
            $this->pushSite(
                catalog: $catalog,
                group: 'sems-prepas',
                name: sprintf('Preparatoria UDG %d', $i),
                domain: sprintf('prepa%d.sems.udg.mx', $i),
                priority: 3,
                checkInterval: 10,
                notes: 'Sitio academico del SEMS.',
                tags: ['udg', 'sems', 'prepa']
            );
        }

        foreach ($regionalAliases as $alias) {
            $hosts = [
                sprintf('prepa-%s.sems.udg.mx', $alias),
                sprintf('bga-%s.sems.udg.mx', $alias),
                sprintf('preparatoria-%s.udg.mx', $alias),
            ];

            foreach ($hosts as $host) {
                $this->pushSite(
                    catalog: $catalog,
                    group: 'sems-prepas',
                    name: sprintf('SEMS %s', Str::headline(str_replace('-', ' ', $alias))),
                    domain: $host,
                    priority: 3,
                    checkInterval: 12,
                    notes: 'Sede regional de preparatoria UDG.',
                    tags: ['udg', 'sems', 'regional']
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     */
    private function appendAdministration(array &$catalog): void
    {
        $dependencies = [
            'rectoria',
            'secretaria-general',
            'abogacia',
            'contraloria',
            'planeacion',
            'finanzas',
            'tesoreria',
            'transparencia',
            'archivo',
            'difusion',
            'comunicacion-social',
            'internacional',
            'vinculacion',
            'egresados',
            'coordinacion-general-academica',
            'coordinacion-investigacion',
            'coordinacion-extension',
            'coordinacion-control-escolar',
            'coordinacion-recursos-humanos',
            'coordinacion-servicios-generales',
            'coordinacion-tecnologias',
            'coordinacion-infraestructura',
            'coordinacion-juridica',
            'coordinacion-bibliotecas',
            'coordinacion-desarrollo',
            'coordinacion-sustentabilidad',
            'coordinacion-administrativa',
            'defensoria',
            'udgtv',
            'radio-udg',
            'gaceta',
            'sri',
            'cultura',
            'deportes',
            'servicios-estudiantiles',
            'becas',
            'biblioteca-publica',
            'editorial',
            'prensa',
            'eventos',
            'congresos',
            'certificacion',
            'innovacion',
            'propiedad-intelectual',
            'calidad',
            'atencion-ciudadana',
            'seguridad-informatica',
            'desarrollo-web',
            'mesa-servicio',
            'analitica',
            'datos-abiertos',
        ];

        foreach ($dependencies as $slug) {
            foreach (['', 'www.', 'portal.'] as $prefix) {
                $host = $prefix . $slug . '.udg.mx';
                $this->pushSite(
                    catalog: $catalog,
                    group: 'administracion-general',
                    name: sprintf('Dependencia %s', Str::headline(str_replace('-', ' ', $slug))),
                    domain: $host,
                    priority: 2,
                    checkInterval: 8,
                    notes: 'Dependencia institucional UDG agregada por descubrimiento expandido.',
                    tags: ['udg', 'administracion']
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     */
    private function appendPatternDiscovery(array &$catalog): void
    {
        $prefixes = [
            'aula', 'admisiones', 'agenda', 'api', 'apps', 'avisos', 'campus', 'capacitacion', 'catalogo',
            'centro', 'cursos', 'dashboard', 'documentos', 'encuestas', 'estadisticas', 'eventos', 'federacion',
            'gestion', 'helpdesk', 'intranet', 'investigacion', 'landing', 'licenciaturas', 'maestrias', 'mapa',
            'micrositio', 'nube', 'observatorio', 'oferta', 'pagos', 'planes', 'portal', 'postgrados', 'practicas',
            'programas', 'proyectos', 'registro', 'revistas', 'sede', 'servicios', 'sistemas', 'soporte',
            'tramites', 'transparencia', 'videoteca', 'wiki', 'zona-estudiantes',
        ];

        $roots = ['udg.mx', 'sems.udg.mx', 'virtual.udg.mx'];

        foreach ($roots as $root) {
            foreach ($prefixes as $prefix) {
                $this->pushSite(
                    catalog: $catalog,
                    group: 'administracion-general',
                    name: sprintf('Servicio %s', Str::headline(str_replace('-', ' ', $prefix))),
                    domain: $prefix . '.' . $root,
                    priority: 3,
                    checkInterval: 15,
                    notes: 'Dominio detectado por patron institucional de descubrimiento.',
                    tags: ['udg', 'discovery', 'pattern']
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     * @param array<int, string> $tags
     */
    private function pushSite(
        array &$catalog,
        string $group,
        string $name,
        string $domain,
        int $priority,
        int $checkInterval,
        string $notes,
        array $tags = []
    ): void {
        $normalizedDomain = mb_strtolower(trim($domain));

        if ($normalizedDomain === '' || ($normalizedDomain !== 'udg.mx' && ! str_ends_with($normalizedDomain, '.udg.mx'))) {
            return;
        }

        $keyed = [];
        foreach ($catalog as $item) {
            $keyed[(string) $item['domain']] = $item;
        }

        $payload = [
            'group_slug' => $group,
            'name' => $name,
            'domain' => $normalizedDomain,
            'url' => 'https://' . $normalizedDomain,
            'priority' => max(1, min(4, $priority)),
            'check_interval_min' => max(2, $checkInterval),
            'notes' => $notes,
            'tags' => array_values(array_unique(array_merge(['udg', 'seed-monitoring'], $tags))),
        ];

        if (! isset($keyed[$normalizedDomain])) {
            $catalog[] = $payload;
            return;
        }

        $existing = $keyed[$normalizedDomain];
        if ((int) $payload['priority'] < (int) ($existing['priority'] ?? 4)) {
            foreach ($catalog as $index => $item) {
                if ((string) $item['domain'] === $normalizedDomain) {
                    $catalog[$index] = $payload;
                    break;
                }
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     * @return array<int, array<string, mixed>>
     */
    private function filterReachableSites(array $catalog, int $target): array
    {
        $reachable = [];

        foreach ($catalog as $candidate) {
            $domain = (string) $candidate['domain'];

            $dnsOk = $this->hasDnsRecord($domain);
            $httpOk = false;

            try {
                $status = Http::timeout(6)->withoutVerifying()->head((string) $candidate['url'])->status();
                $httpOk = $status > 0 && $status < 600;
            } catch (\Throwable) {
                $httpOk = false;
            }

            if ($dnsOk || $httpOk) {
                $reachable[] = $candidate;
            }
        }

        if (count($reachable) >= $target) {
            return $reachable;
        }

        $this->warn(sprintf(
            'La validacion live dejo %d sitios (< %d), se conserva el catalogo completo para no perder cobertura.',
            count($reachable),
            $target
        ));

        return $catalog;
    }

    private function hasDnsRecord(string $domain): bool
    {
        if (! function_exists('dns_get_record')) {
            return false;
        }

        try {
            $records = @dns_get_record($domain, DNS_A + DNS_AAAA + DNS_CNAME);
            return is_array($records) && $records !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{stats: array{created: int, updated: int, unchanged: int}, ids: array<string, int>}
     */
    private function upsertGroups(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $ids = [];

        foreach (self::GROUPS as $slug => $payload) {
            /** @var SiteGroup|null $existing */
            $existing = SiteGroup::query()->where('slug', $slug)->first();

            if ($existing === null) {
                $group = SiteGroup::query()->create([
                    'name' => $payload['name'],
                    'slug' => $slug,
                    'description' => $payload['description'],
                    'color' => $payload['color'],
                ]);
                $ids[$slug] = (int) $group->id;
                $stats['created']++;
                continue;
            }

            $changes = [
                'name' => $payload['name'],
                'description' => $payload['description'],
                'color' => $payload['color'],
            ];

            if ($existing->only(array_keys($changes)) !== $changes) {
                $existing->fill($changes);
                $existing->save();
                $stats['updated']++;
            } else {
                $stats['unchanged']++;
            }

            $ids[$slug] = (int) $existing->id;
        }

        return [
            'stats' => $stats,
            'ids' => $ids,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     * @return array{sites_removed: int, groups_removed: int}
     */
    private function purgeNonOfficialData(array $catalog): array
    {
        $officialDomains = array_values(array_unique(array_map(
            static fn (array $site): string => (string) $site['domain'],
            $catalog
        )));

        $siteIds = Site::query()
            ->where('url', 'like', '%.example.local%')
            ->orWhere('domain', 'like', '%.example.local%')
            ->orWhereNotIn('domain', $officialDomains)
            ->pluck('id')
            ->all();

        $sitesRemoved = 0;
        if ($siteIds !== []) {
            $sitesRemoved = Site::withTrashed()
                ->whereIn('id', $siteIds)
                ->forceDelete();
        }

        $officialGroupSlugs = array_keys(self::GROUPS);

        $groupsRemoved = SiteGroup::query()
            ->whereNotIn('slug', $officialGroupSlugs)
            ->delete();

        return [
            'sites_removed' => (int) $sitesRemoved,
            'groups_removed' => (int) $groupsRemoved,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     * @return array{sites_removed: int, groups_removed: int}
     */
    private function purgeSitesOutsideOfficialCatalog(array $catalog): array
    {
        $officialDomains = array_values(array_unique(array_map(
            static fn (array $site): string => (string) $site['domain'],
            $catalog
        )));

        $officialGroupSlugs = array_keys(self::GROUPS);

        $sitesRemoved = Site::withTrashed()
            ->whereNotIn('domain', $officialDomains)
            ->forceDelete();

        $groupsRemoved = SiteGroup::query()
            ->whereNotIn('slug', $officialGroupSlugs)
            ->whereDoesntHave('sites')
            ->delete();

        return [
            'sites_removed' => (int) $sitesRemoved,
            'groups_removed' => (int) $groupsRemoved,
        ];
    }

    /**
     * @param array<string, int> $groupIds
     * @param array<int, array<string, mixed>> $catalog
     * @return array{created: int, updated: int, unchanged: int}
     */
    private function upsertSites(array $groupIds, array $catalog): array
    {
        $siteStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($catalog as $site) {
            if (! isset($groupIds[(string) $site['group_slug']])) {
                $this->warn('Grupo no encontrado para sitio: ' . (string) $site['name']);
                continue;
            }

            $groupId = $groupIds[(string) $site['group_slug']];

            /** @var Site|null $existing */
            $existing = Site::query()->where('domain', (string) $site['domain'])->first();

            $payload = [
                'site_group_id' => $groupId,
                'name' => (string) $site['name'],
                'slug' => Str::slug((string) $site['name']) . '-' . Str::slug((string) $site['domain']),
                'domain' => (string) $site['domain'],
                'url' => (string) $site['url'],
                'is_active' => true,
                'is_monitored' => true,
                'priority' => (int) $site['priority'],
                'current_status' => 'unknown',
                'check_interval_min' => (int) $site['check_interval_min'],
                'notes' => (string) $site['notes'],
                'tags' => $site['tags'] ?? ['udg', 'seed-monitoring'],
            ];

            if ($existing === null) {
                Site::query()->create($payload);
                $siteStats['created']++;
                continue;
            }

            unset($payload['slug']);

            $hasChanges = false;
            foreach ($payload as $key => $value) {
                if ($existing->{$key} !== $value) {
                    $hasChanges = true;
                    break;
                }
            }

            if ($hasChanges) {
                $existing->fill($payload);
                $existing->save();
                $siteStats['updated']++;
            } else {
                $siteStats['unchanged']++;
            }
        }

        return $siteStats;
    }
}
