<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use Illuminate\Console\Command;

final class SeedUdgSitesCommand extends Command
{
    protected $signature = 'monitoring:seed-udg-sites
        {--dry-run : Solo muestra lo que se aplicaria sin guardar cambios}
        {--replace : Elimina sitios que no esten en la fuente oficial}
        {--validate-live : Obsoleto. Se ignora para mantener compatibilidad}
        {--target=220 : Obsoleto. Se ignora para mantener compatibilidad}';

    protected $description = 'Alias de compatibilidad para la sincronizacion oficial del inventario institucional.';

    public function handle(): int
    {
        $this->warn('monitoring:seed-udg-sites esta obsoleto y ahora redirige al inventario oficial.');

        if ((bool) $this->option('validate-live') || (int) $this->option('target') !== 220) {
            $this->line('Las opciones --validate-live y --target se ignoran en este alias.');
        }

        $parameters = [];

        if ((bool) $this->option('dry-run')) {
            $parameters['--dry-run'] = true;
        }

        if ((bool) $this->option('replace')) {
            $parameters['--replace'] = true;
        }

        $this->call('monitoring:sync-official-inventory', $parameters);

        return self::SUCCESS;
    }
}
