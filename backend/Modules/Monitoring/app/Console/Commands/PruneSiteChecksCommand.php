<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use App\Contracts\Repositories\SiteCheckRepositoryInterface;
use Illuminate\Console\Command;

final class PruneSiteChecksCommand extends Command
{
    protected $signature = 'monitoring:prune-site-checks
                            {--days=90 : Dias de retencion; checks mas antiguos seran eliminados}';

    protected $description = 'RF-07: Elimina registros de site_checks mas antiguos que N dias (por defecto 90).';

    public function handle(SiteCheckRepositoryInterface $siteCheckRepository): int
    {
        $days = max(1, (int) $this->option('days'));

        $deleted = $siteCheckRepository->pruneOlderThan($days);

        $this->info(sprintf(
            'Pruning completado: %d registros de site_checks eliminados (retencion: %d dias).',
            $deleted,
            $days
        ));

        return self::SUCCESS;
    }
}
