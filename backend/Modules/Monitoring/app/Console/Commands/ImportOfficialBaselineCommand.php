<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use Illuminate\Console\Command;
use Modules\Monitoring\Services\OfficialBaseline\OfficialBaselineImporter;

final class ImportOfficialBaselineCommand extends Command
{
    protected $signature = 'monitoring:import-official-baseline
        {--source=docs/right_sites/true_sites.csv : Ruta del CSV baseline (relativa o absoluta)}
        {--dry-run : Solo analiza la fuente sin persistir}';

    protected $description = 'Importa el baseline oficial desde CSV a tablas independientes del estado operativo (sites).';

    public function handle(OfficialBaselineImporter $importer): int
    {
        $sourceOption = (string) $this->option('source');
        $sourcePath = $this->resolveSourcePath($sourceOption);

        if ($sourcePath === null) {
            $this->error(sprintf('No se encontro el archivo baseline: %s', $sourceOption));

            return self::FAILURE;
        }

        $summary = $importer->summarize($sourcePath);

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry-run completado. No se persistieron cambios.');
            $this->line(sprintf('Filas detectadas: %d', (int) $summary['total_rows']));
            $this->line(sprintf('Dominios unicos normalizados: %d', (int) $summary['unique_domains']));

            return self::SUCCESS;
        }

        $snapshot = $importer->import(
            sourcePath: $sourcePath,
            importedBy: null,
            sourceName: basename($sourcePath),
        );

        $this->info('Baseline oficial importada correctamente.');
        $this->line(sprintf('Snapshot ID: %d', (int) $snapshot->id));
        $this->line(sprintf('Filas importadas: %d', (int) $snapshot->total_rows));
        $this->line(sprintf('Dominios unicos: %d', (int) $snapshot->unique_domains));

        return self::SUCCESS;
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
