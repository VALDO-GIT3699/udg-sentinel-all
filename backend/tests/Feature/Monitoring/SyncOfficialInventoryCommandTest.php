<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Site;
use App\Models\SiteGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SyncOfficialInventoryCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_preserves_row_cardinality_for_official_inventory_sources(): void
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'official_inventory_csv_');
        $this->assertNotFalse($tmpBase);
        // The parser picks a format by file extension, so the fixture needs ".csv".
        $tmpFile = $tmpBase.'.csv';

        file_put_contents((string) $tmpFile, implode("\n", [
            'Clasificación,Entidad,Nombre del sitio,Dominio,Sitio activo,CMS,IP servidor,Certificado de seguridad,Estatus proyecto,Comentarios',
            'AG,CGAI,Portal HTTPS,https://portal-a.udg.mx/,Sí,D10,148.202.34.140,Activo,Migrado y publicado,',
            'AG,CGAI,Portal HTTP,http://portal-b.udg.mx/,Sí,D9,148.202.34.141,Activo,2da etapa,',
            'AG,CGAI,Portal sin protocolo,crea.udg.mx,No,PHP,,No tiene,Solicitud de baja,',
            'AG,CGAI,Portal por IP,148.202.248.71/ciju,No,PHP,,No tiene,2da etapa,',
            'AG,CGAI,Entrada ND,nd,No,D10,,No tiene,En proceso de migración,',
            'AG,CGAI,Duplicado exacto,https://portal-a.udg.mx/,Sí,D10,148.202.34.140,Activo,Migrado y publicado,',
        ]));

        try {
            $exitCode = $this->artisan('monitoring:sync-official-inventory', [
                '--source' => (string) $tmpFile,
            ])->run();

            $this->assertSame(0, $exitCode);
            $this->assertSame(6, Site::query()->count());
            $this->assertSame(2, Site::query()->where('domain', 'portal-a.udg.mx')->count());
            $this->assertSame(1, Site::query()->where('domain', 'portal-b.udg.mx')->count());
            $this->assertSame(1, Site::query()->where('domain', 'crea.udg.mx')->count());
            $this->assertSame(1, Site::query()->where('domain', '148.202.248.71')->count());

            $ndSite = Site::query()->where('domain', 'like', 'sin-dominio-%.invalid')->first();
            $this->assertNotNull($ndSite);
            $this->assertFalse((bool) $ndSite->is_monitored);
        } finally {
            @unlink($tmpFile);
            @unlink($tmpBase);
        }
    }

    #[Test]
    public function replace_soft_deletes_only_official_sites_dropped_from_the_source_and_keeps_others_untouched(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Manual',
            'slug' => 'manual-group',
            'color' => '#0EA5E9',
        ]);

        $manualSite = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio agregado a mano',
            'slug' => 'sitio-agregado-a-mano',
            'domain' => 'manual.udg.mx',
            'url' => 'https://manual.udg.mx',
            'tags' => ['manual'],
        ]);

        $firstFile = $this->writeCsvFixture([
            'AG,CGAI,Sitio A,https://sitio-a.udg.mx/,Sí,D10,,Activo,Migrado y publicado,',
            'AG,CGAI,Sitio B,https://sitio-b.udg.mx/,Sí,D10,,Activo,Migrado y publicado,',
        ]);

        try {
            $this->artisan('monitoring:sync-official-inventory', [
                '--source' => $firstFile,
                '--replace' => true,
            ])->assertExitCode(0);

            $siteA = Site::query()->where('domain', 'sitio-a.udg.mx')->firstOrFail();
            $siteB = Site::query()->where('domain', 'sitio-b.udg.mx')->firstOrFail();

            $secondFile = $this->writeCsvFixture([
                'AG,CGAI,Sitio A,https://sitio-a.udg.mx/,Sí,D10,,Activo,Migrado y publicado,',
            ]);

            try {
                $this->artisan('monitoring:sync-official-inventory', [
                    '--source' => $secondFile,
                    '--replace' => true,
                ])->assertExitCode(0);

                // Sitio A sigue presente en la fuente: debe conservar su identidad (mismo id).
                $this->assertSame($siteA->id, Site::query()->where('domain', 'sitio-a.udg.mx')->firstOrFail()->id);

                // Sitio B salio de la fuente oficial: se retira (soft-delete), no se destruye.
                $this->assertNull(Site::query()->find($siteB->id));
                $this->assertNotNull(Site::withTrashed()->find($siteB->id));
                $this->assertTrue(Site::withTrashed()->findOrFail($siteB->id)->trashed());

                // Un sitio dado de alta a mano (sin el tag "official") nunca se toca.
                $this->assertNotNull(Site::query()->find($manualSite->id));
                $this->assertFalse(Site::query()->findOrFail($manualSite->id)->trashed());
            } finally {
                @unlink($secondFile);
            }
        } finally {
            @unlink($firstFile);
        }
    }

    #[Test]
    public function merge_only_adds_sites_with_new_domains_and_skips_the_rest_without_touching_them(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Manual',
            'slug' => 'manual-group-merge',
            'color' => '#0EA5E9',
        ]);

        // Ya existe con distinto slug/nombre/tags al que traeria el CSV: --merge-only
        // debe reconocerlo por dominio y NO tocarlo ni crear un duplicado.
        $existing = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Nombre distinto capturado a mano',
            'slug' => 'nombre-distinto-manual',
            'domain' => 'www.existente.udg.mx',
            'url' => 'https://www.existente.udg.mx',
            'notes' => 'nota original que no debe perderse',
        ]);

        $file = $this->writeCsvFixture([
            'AG,CGAI,Sitio Existente,https://existente.udg.mx/,Sí,D10,,Activo,Migrado y publicado,',
            'AG,CGAI,Sitio Nuevo,https://nuevo-desde-csv.udg.mx/,Sí,WordPress,,Activo,2da etapa,',
        ]);

        try {
            $this->artisan('monitoring:sync-official-inventory', [
                '--source' => $file,
                '--merge-only' => true,
            ])->assertExitCode(0);

            // El existente (mismo dominio canonico, con o sin www) no se modifica.
            $existing->refresh();
            $this->assertSame('Nombre distinto capturado a mano', $existing->name);
            $this->assertSame('nota original que no debe perderse', $existing->notes);
            $this->assertSame(1, Site::query()->where('domain', 'www.existente.udg.mx')->count());
            $this->assertSame(0, Site::query()->where('domain', 'existente.udg.mx')->count());

            // El que no existia se agrega.
            $this->assertSame(1, Site::query()->where('domain', 'nuevo-desde-csv.udg.mx')->count());
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function merge_only_ignores_replace_and_never_purges_sites(): void
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-merge-ignore-replace',
            'color' => '#0EA5E9',
        ]);

        $untouched = Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio que no viene en este CSV',
            'slug' => 'sitio-fuera-del-csv',
            'domain' => 'fuera-del-csv.udg.mx',
            'url' => 'https://fuera-del-csv.udg.mx',
        ]);

        $file = $this->writeCsvFixture([
            'AG,CGAI,Sitio Nuevo,https://nuevo-otro.udg.mx/,Sí,WordPress,,Activo,2da etapa,',
        ]);

        try {
            $this->artisan('monitoring:sync-official-inventory', [
                '--source' => $file,
                '--merge-only' => true,
                '--replace' => true,
            ])->assertExitCode(0);

            $this->assertFalse($untouched->fresh()->trashed());
            $this->assertSame(1, Site::query()->where('domain', 'nuevo-otro.udg.mx')->count());
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function merge_only_treats_distinct_paths_on_the_same_domain_as_distinct_sites(): void
    {
        // El motor de inspeccion escanea la URL completa (no solo el dominio), asi que
        // dos paginas del mismo campus (una por carrera) son sitios distintos: cada una
        // puede fallar o funcionar de forma independiente.
        $file = $this->writeCsvFixture([
            'AG,CUCEI,Ingenieria Civil,https://www.cucei.udg.mx/carreras/civil,Sí,WordPress,,Activo,2da etapa,',
            'AG,CUCEI,Ingenieria Quimica,https://www.cucei.udg.mx/carreras/quimica,Sí,WordPress,,Activo,2da etapa,',
        ]);

        try {
            $this->artisan('monitoring:sync-official-inventory', [
                '--source' => $file,
                '--merge-only' => true,
            ])->assertExitCode(0);

            $this->assertSame(2, Site::query()->where('domain', 'www.cucei.udg.mx')->count());
            $this->assertSame(
                1,
                Site::query()->where('domain', 'www.cucei.udg.mx')->where('url', 'like', '%civil')->count(),
            );
            $this->assertSame(
                1,
                Site::query()->where('domain', 'www.cucei.udg.mx')->where('url', 'like', '%quimica')->count(),
            );

            // Re-ejecutar el mismo merge no debe duplicar ninguna de las dos paginas.
            $this->artisan('monitoring:sync-official-inventory', [
                '--source' => $file,
                '--merge-only' => true,
            ])->assertExitCode(0);

            $this->assertSame(2, Site::query()->where('domain', 'www.cucei.udg.mx')->count());
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function merge_only_is_idempotent_for_rows_without_a_real_domain(): void
    {
        $file = $this->writeCsvFixture([
            'AG,CGSEG,Coordinacion General de Seguridad Universitaria,nd,No,Otro,,No tiene,2da etapa,',
        ]);

        try {
            $this->artisan('monitoring:sync-official-inventory', [
                '--source' => $file,
                '--merge-only' => true,
            ])->assertExitCode(0);

            $this->assertSame(1, Site::query()->where('domain', 'like', 'sin-dominio-%.invalid')->count());

            // Re-ejecutar el mismo merge (misma fuente, sin dominio real) no debe crear
            // un segundo sitio placeholder para la misma fila.
            $this->artisan('monitoring:sync-official-inventory', [
                '--source' => $file,
                '--merge-only' => true,
            ])->assertExitCode(0);

            $this->assertSame(1, Site::query()->where('domain', 'like', 'sin-dominio-%.invalid')->count());
        } finally {
            @unlink($file);
        }
    }

    /**
     * @param  list<string>  $rows
     */
    private function writeCsvFixture(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'official_inventory_csv_').'.csv';

        file_put_contents($path, implode("\n", array_merge(
            ['Clasificación,Entidad,Nombre del sitio,Dominio,Sitio activo,CMS,IP servidor,Certificado de seguridad,Estatus proyecto,Comentarios'],
            $rows,
        )));

        return $path;
    }
}
