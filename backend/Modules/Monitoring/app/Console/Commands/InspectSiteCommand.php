<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use Illuminate\Console\Command;
use Modules\Monitoring\Services\SiteInspection\VerticalSiteInspectionEngine;

final class InspectSiteCommand extends Command
{
    protected $signature = 'inspect:site {url_o_dominio : URL completa o dominio a inspeccionar}';

    protected $description = 'Ejecuta inspeccion vertical por sitio y devuelve JSON consolidado.';

    public function __construct(
        private readonly VerticalSiteInspectionEngine $engine,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $target = trim((string) $this->argument('url_o_dominio'));

        if ($target === '') {
            $this->error('Debes proporcionar una URL o dominio valido.');

            return self::FAILURE;
        }

        try {
            $result = $this->engine->inspectTarget($target);

            $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            if ($json === false) {
                $this->error('No fue posible serializar el resultado de inspeccion.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Error en inspeccion: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
