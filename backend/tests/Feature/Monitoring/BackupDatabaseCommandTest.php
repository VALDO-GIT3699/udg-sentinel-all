<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El comando corre pg_dump de verdad -no se intenta mockear el binario:
 * Process::fake() no intercepta comandos en forma de array de la manera
 * esperada en esta version, y un mock no demostraria que pg_dump realmente
 * esta instalado y sabe hablar con Postgres-. La conexion "pgsql" sigue
 * apuntando al Postgres real de este contenedor incluso corriendo bajo la
 * suite (solo la conexion POR DEFECTO se sobreescribe a sqlite en
 * phpunit.xml), asi que esta prueba genera un respaldo real y no destructivo
 * de la base de datos de desarrollo -exactamente lo mismo que ya se verifico
 * a mano- y lo borra al terminar.
 */
final class BackupDatabaseCommandTest extends TestCase
{
    #[Test]
    public function it_creates_a_real_valid_postgres_dump(): void
    {
        $this->artisan('monitoring:backup-database', ['--keep-days' => 14])
            ->assertSuccessful();

        $files = glob($this->backupDir().DIRECTORY_SEPARATOR.'udg-sentinel_*.dump') ?: [];
        $this->assertNotEmpty($files, 'El comando no dejó ningún archivo .dump en storage/app/backups.');

        $dump = $files[0];
        $this->assertGreaterThan(0, filesize($dump));

        // "PGDMP" es el magic header del formato custom de pg_dump -confirma
        // que el archivo es un respaldo real y estructuralmente valido, no
        // solo un archivo vacio o con un mensaje de error.
        $header = fread(fopen($dump, 'rb'), 5);
        $this->assertSame('PGDMP', $header);
    }

    #[Test]
    public function it_fails_cleanly_when_the_database_does_not_exist(): void
    {
        config(['database.connections.pgsql.database' => 'esta_base_de_datos_no_existe']);

        $this->artisan('monitoring:backup-database')
            ->assertFailed();

        $files = glob($this->backupDir().DIRECTORY_SEPARATOR.'udg-sentinel_*.dump') ?: [];
        $this->assertEmpty($files, 'No debe quedar ningún .dump cuando pg_dump falla.');
    }

    #[Test]
    public function it_prunes_local_backups_older_than_the_retention_window(): void
    {
        if (! is_dir($this->backupDir())) {
            mkdir($this->backupDir(), 0755, true);
        }

        $old = $this->backupDir().DIRECTORY_SEPARATOR.'phpunit-old.dump';
        $recent = $this->backupDir().DIRECTORY_SEPARATOR.'phpunit-recent.dump';

        file_put_contents($old, 'fake-old-dump');
        file_put_contents($recent, 'fake-recent-dump');
        touch($old, now()->subDays(30)->getTimestamp());
        touch($recent, now()->subDays(1)->getTimestamp());

        $this->artisan('monitoring:backup-database', ['--keep-days' => 14])
            ->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml sobreescribe DB_DATABASE a ":memory:" globalmente
        // (pensado para la conexion sqlite por defecto); para este comando
        // se restaura el nombre real de la base de datos de Postgres.
        config(['database.connections.pgsql.database' => env('DB_DATABASE_REAL', 'udg_sentinel')]);

        // El activity_log del comando escribe en la conexion POR DEFECTO
        // (sqlite :memory: en esta suite), y esta clase no usa
        // RefreshDatabase a proposito -no se quiere migrar/limpiar la
        // conexion pgsql real-, asi que esa tabla nunca existe aqui.
        config(['activitylog.enabled' => false]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir().DIRECTORY_SEPARATOR.'udg-sentinel_*.dump') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->backupDir().DIRECTORY_SEPARATOR.'phpunit-*.dump') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function backupDir(): string
    {
        return storage_path('app/backups');
    }
}
