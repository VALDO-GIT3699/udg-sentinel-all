<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Respaldo de la base de datos de produccion (Postgres) via pg_dump en
 * formato custom (-Fc): comprimido internamente y restaurable de forma
 * selectiva con pg_restore, a diferencia de un dump de texto plano.
 *
 * No depende de ningun paquete nuevo de Composer -pg_dump ya viene con el
 * cliente postgresql16-client instalado en el Dockerfile-, asi que es
 * quirurgico: un comando, programado una vez al dia junto al resto del
 * mantenimiento.
 */
final class BackupDatabaseCommand extends Command
{
    protected $signature = 'monitoring:backup-database
        {--keep-days=14 : Cuantos dias de respaldos locales conservar}
        {--disk= : Disco de Laravel (config/filesystems.php) al que ademas copiar el respaldo, p. ej. un bucket S3 fuera de este servidor}';

    protected $description = 'Genera un respaldo de la base de datos Postgres via pg_dump y poda los respaldos locales antiguos.';

    public function handle(): int
    {
        $connection = config('database.connections.pgsql');

        if (! is_array($connection)) {
            $this->error('La conexion "pgsql" no esta configurada.');

            return self::FAILURE;
        }

        $backupDir = storage_path('app/backups');

        if (! is_dir($backupDir) && ! @mkdir($backupDir, 0755, true) && ! is_dir($backupDir)) {
            $this->error("No se pudo crear el directorio de respaldos: {$backupDir}");

            return self::FAILURE;
        }

        $filename = sprintf('udg-sentinel_%s.dump', now()->format('Y-m-d_His'));
        $fullPath = $backupDir.DIRECTORY_SEPARATOR.$filename;

        $this->info("Generando respaldo: {$filename}");

        $result = Process::env(['PGPASSWORD' => (string) ($connection['password'] ?? '')])
            ->timeout(600)
            ->run([
                'pg_dump',
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? '5432'),
                '--username='.(string) ($connection['username'] ?? ''),
                '--format=custom',
                '--file='.$fullPath,
                (string) ($connection['database'] ?? ''),
            ]);

        if (! $result->successful()) {
            // pg_dump abre/trunca --file antes de intentar conectarse, asi que
            // un fallo de conexion igual deja un archivo vacio en disco.
            @unlink($fullPath);
            $this->error('pg_dump falló: '.trim($result->errorOutput()));
            $this->logOutcome(false, $filename, trim($result->errorOutput()));

            return self::FAILURE;
        }

        $sizeBytes = @filesize($fullPath) ?: 0;

        if ($sizeBytes === 0) {
            @unlink($fullPath);
            $this->error('pg_dump terminó sin error pero el archivo de respaldo está vacío.');
            $this->logOutcome(false, $filename, 'archivo de respaldo vacío');

            return self::FAILURE;
        }

        $this->info(sprintf('Respaldo creado: %s (%s)', $filename, $this->formatBytes($sizeBytes)));

        $offsiteDisk = (string) ($this->option('disk') ?? '');

        if ($offsiteDisk !== '') {
            try {
                Storage::disk($offsiteDisk)->put('backups/'.$filename, fopen($fullPath, 'r'));
                $this->info("Copiado también al disco \"{$offsiteDisk}\".");
            } catch (\Throwable $exception) {
                $this->warn("No se pudo copiar al disco \"{$offsiteDisk}\": ".$exception->getMessage());
            }
        }

        $pruned = $this->pruneOldBackups($backupDir, max(1, (int) $this->option('keep-days')));

        if ($pruned > 0) {
            $this->info("Se eliminaron {$pruned} respaldo(s) local(es) con más de {$this->option('keep-days')} días.");
        }

        $this->logOutcome(true, $filename, null, $sizeBytes);

        return self::SUCCESS;
    }

    private function pruneOldBackups(string $backupDir, int $keepDays): int
    {
        $cutoff = now()->subDays($keepDays)->getTimestamp();
        $pruned = 0;

        foreach (glob($backupDir.DIRECTORY_SEPARATOR.'*.dump') ?: [] as $file) {
            if (is_file($file) && (int) filemtime($file) < $cutoff) {
                @unlink($file);
                $pruned++;
            }
        }

        return $pruned;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return round($value, 1).' '.$units[$unitIndex];
    }

    private function logOutcome(bool $success, string $filename, ?string $error = null, ?int $sizeBytes = null): void
    {
        if (! function_exists('activity')) {
            return;
        }

        activity()
            ->withProperties(array_filter([
                'action' => $success ? 'database.backup.completed' : 'database.backup.failed',
                'filename' => $filename,
                'size_bytes' => $sizeBytes,
                'error' => $error,
            ], static fn (mixed $value): bool => $value !== null))
            ->log($success ? 'Respaldo de base de datos completado' : 'Respaldo de base de datos falló');
    }
}
