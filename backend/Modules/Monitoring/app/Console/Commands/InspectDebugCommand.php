<?php

declare(strict_types=1);

namespace Modules\Monitoring\Console\Commands;

use Illuminate\Console\Command;
use Modules\Monitoring\Services\SiteInspection\VerticalSiteInspectionEngine;

final class InspectDebugCommand extends Command
{
    protected $signature = 'inspect:debug {url_o_dominio : URL completa o dominio a inspeccionar}';

    protected $description = 'Depura el motor de fingerprinting mostrando heurísticas, puntuaciones, evidencia y descarte de tecnologías.';

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
            $report = $this->engine->inspectDebugTarget($target);
            $debug = is_array($report['fingerprint_debug'] ?? null) ? $report['fingerprint_debug'] : [];
            $candidates = is_array($debug['candidates'] ?? null) ? $debug['candidates'] : [];
            $discarded = is_array($debug['discarded'] ?? null) ? $debug['discarded'] : [];

            $this->line('Objetivo: '.$target);
            $this->line('URL final: '.(string) (($report['http']['final_url'] ?? $target)));
            $this->line('HTTP: '.(string) (($report['http']['status'] ?? 'N/D')).' | Latencia: '.(string) (($report['http']['latency_ms'] ?? 'N/D')).' ms');
            $this->newLine();
            $this->line('Decision final');
            $this->line('Detectado: '.(string) ($debug['detected'] ?? 'No determinado'));
            $this->line('Categoria: '.(string) ($debug['category'] ?? 'unknown'));
            $this->line('Version: '.(string) ($debug['version'] ?? 'No determinado'));
            $this->line('Confidence: '.(string) ($debug['confidence'] ?? 0));
            $this->line('Evidencia: '.json_encode($debug['evidence'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->newLine();
            $this->line('Heuristicas evaluadas');

            foreach ($candidates as $candidate) {
                $this->line('- '.(string) ($candidate['label'] ?? 'No determinado').' => '.(string) ($candidate['score'] ?? 0));

                foreach ((array) ($candidate['rules'] ?? []) as $rule) {
                    $status = (($rule['matched'] ?? false) === true) ? 'MATCH' : 'MISS';
                    $this->line('  * '.(string) ($rule['heuristic'] ?? 'heuristic').' | '.$status.' | +'.(string) ($rule['score'] ?? 0).' | '.json_encode($rule['evidence'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            }

            $this->newLine();
            $this->line('Tecnologias descartadas');

            foreach ($discarded as $item) {
                $this->line('- '.(string) ($item['technology'] ?? 'No determinado').' => '.(string) ($item['score'] ?? 0).' | '.(string) ($item['reason'] ?? 'Sin razon registrada'));
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Error en depuracion: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
