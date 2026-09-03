<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Monitoring\Services\OfficialBaseline\OfficialBaselineImporter;

class OfficialBaselineSeeder extends Seeder
{
    public function run(): void
    {
        $relativeSource = 'docs/right_sites/true_sites.csv';
        $candidates = [
            base_path($relativeSource),
            base_path('../'.$relativeSource),
        ];

        $sourcePath = null;

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $sourcePath = $candidate;
                break;
            }
        }

        if ($sourcePath === null) {
            $this->command?->warn('OfficialBaselineSeeder omitido: no se encontro docs/right_sites/true_sites.csv.');

            return;
        }

        /** @var OfficialBaselineImporter $importer */
        $importer = app(OfficialBaselineImporter::class);
        $importer->import($sourcePath, null, basename($sourcePath));

        $this->command?->info('OfficialBaselineSeeder: baseline importada correctamente.');
    }
}
