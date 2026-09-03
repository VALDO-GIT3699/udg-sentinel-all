<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\OfficialBaselineSite;
use App\Models\OfficialBaselineSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ImportOfficialBaselineCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_imports_baseline_into_independent_tables_without_touching_sites(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'baseline_csv_');
        $this->assertNotFalse($tmpFile);

        file_put_contents((string) $tmpFile, implode("\n", [
            'Clasificación,Entidad,Nombre del sitio,Dominio,Sitio activo,CMS,IP servidor,Certificado de seguridad,Estatus proyecto,Comentarios',
            'AG,CGAI,Sitio Uno,https://uno.udg.mx/,Sí,Drupal 10,148.202.1.1,Activo,Migrado y publicado,',
            'AG,CGAI,Sitio Dos,http://dos.udg.mx/,No,WordPress,Externo,No tiene,Eliminado,Ticket 99999',
        ]));

        try {
            $exitCode = $this->artisan('monitoring:import-official-baseline', [
                '--source' => (string) $tmpFile,
            ])->run();

            $this->assertSame(0, $exitCode);
            $this->assertDatabaseCount('sites', 0);
            $this->assertDatabaseCount('official_baseline_snapshots', 1);
            $this->assertDatabaseCount('official_baseline_sites', 2);

            $snapshot = OfficialBaselineSnapshot::query()->first();
            $this->assertNotNull($snapshot);
            $this->assertTrue((bool) $snapshot->is_current);
            $this->assertSame(2, (int) $snapshot->total_rows);
            $this->assertSame(2, (int) $snapshot->unique_domains);

            $baselineSite = OfficialBaselineSite::query()->where('normalized_domain', 'dos.udg.mx')->first();
            $this->assertNotNull($baselineSite);
            $this->assertFalse((bool) $baselineSite->is_active);
            $this->assertSame('99999', (string) $baselineSite->ticket_number);
        } finally {
            @unlink((string) $tmpFile);
        }
    }

    #[Test]
    public function dry_run_does_not_persist_baseline_data(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'baseline_csv_');
        $this->assertNotFalse($tmpFile);

        file_put_contents((string) $tmpFile, implode("\n", [
            'Clasificación,Entidad,Nombre del sitio,Dominio,Sitio activo,CMS,IP servidor,Certificado de seguridad,Estatus proyecto,Comentarios',
            'AG,CGAI,Sitio Uno,https://uno.udg.mx/,Sí,Drupal 10,148.202.1.1,Activo,Migrado y publicado,',
        ]));

        try {
            $exitCode = $this->artisan('monitoring:import-official-baseline', [
                '--source' => (string) $tmpFile,
                '--dry-run' => true,
            ])->run();

            $this->assertSame(0, $exitCode);
            $this->assertDatabaseCount('official_baseline_snapshots', 0);
            $this->assertDatabaseCount('official_baseline_sites', 0);
            $this->assertDatabaseCount('sites', 0);
        } finally {
            @unlink((string) $tmpFile);
        }
    }
}
