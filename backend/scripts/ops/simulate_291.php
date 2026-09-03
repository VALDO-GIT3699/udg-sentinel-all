<?php

declare(strict_types=1);

use App\Models\Site;
use Modules\Monitoring\Jobs\RunTechnologyScanJob;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;
use Modules\Monitoring\Support\HttpFailureClassifier;

$sites = Site::query()->with(['siteTechnologies.technology'])->orderBy('domain')->get(['id', 'domain', 'url']);

$before = [];

foreach ($sites as $site) {
    $primarySlug = $site->siteTechnologies->filter(fn ($st) => $st->is_primary)
        ->map(fn ($st) => $st->technology?->slug)->filter()->first();
    $before[$site->domain] = $primarySlug ?? 'no-determinado';
}

$noDeterminado = array_filter($before, fn ($slug) => $slug === 'no-determinado');

echo 'Total sitios: '.count($before).PHP_EOL;
echo 'No determinado ANTES: '.count($noDeterminado).PHP_EOL.PHP_EOL;

$factory = app(MonitoringHttpClientFactory::class);
$job = new RunTechnologyScanJob(0);
$ref = new ReflectionClass($job);
$buildFingerprint = $ref->getMethod('buildFingerprint');
$buildFingerprint->setAccessible(true);
$classify = $ref->getMethod('classifyPrimaryTechnology');
$classify->setAccessible(true);

$transitions = [];
$stillNoDeterminado = 0;
$newLaravel = [];
$newUnconfigured = [];
$newOther = [];
$stillIndeterminate = [];
$sinRespuesta = 0;

$sitesById = $sites->keyBy('domain');

foreach (array_keys($noDeterminado) as $domain) {
    if (str_starts_with($domain, '148.202') || str_contains($domain, 'invalid')) {
        $sinRespuesta++;

        continue;
    }

    $site = $sitesById[$domain];
    $url = 'https://'.$domain;
    $response = null;
    $tlsIssue = false;

    try {
        $response = $factory->make(['Accept' => 'text/html,*/*;q=0.8'])->get($url);
    } catch (Throwable $e) {
        if (HttpFailureClassifier::isTlsFailure($e)) {
            $tlsIssue = true;

            try {
                $response = $factory->make(['Accept' => 'text/html,*/*;q=0.8'], 'default', true)->get($url);
            } catch (Throwable $e2) {
                $sinRespuesta++;

                continue;
            }
        } else {
            $sinRespuesta++;

            continue;
        }
    }

    $fingerprint = $buildFingerprint->invoke($job, $site, $response, $factory, $tlsIssue);
    $classification = $classify->invoke($job, $fingerprint);
    $newSlug = $classification['slug'];

    if ($newSlug === 'no-determinado') {
        $stillNoDeterminado++;
        $stillIndeterminate[] = $domain;

        continue;
    }

    if ($newSlug === 'inactivo') {
        continue;
    }

    $transitions[] = [$domain, 'no-determinado', $newSlug];

    if ($newSlug === 'laravel') {
        $newLaravel[] = $domain;
    } elseif ($newSlug === 'dominio-no-configurado') {
        $newUnconfigured[] = $domain;
    } else {
        $newOther[] = $domain.' => '.$newSlug;
    }
}

echo '=== TRANSICIONES (no-determinado -> algo distinto) ==='.PHP_EOL;

foreach ($transitions as [$domain, $antes, $despues]) {
    echo "$domain: $antes -> $despues".PHP_EOL;
}

echo PHP_EOL.'=== RESUMEN ==='.PHP_EOL;
echo 'No determinado ANTES:                 '.count($noDeterminado).PHP_EOL;
echo 'Sin respuesta (excluidos, sin cambio): '.$sinRespuesta.PHP_EOL;
echo 'Pasan a Laravel:                       '.count($newLaravel).' ('.implode(', ', $newLaravel).')'.PHP_EOL;
echo 'Pasan a Dominio no configurado:        '.count($newUnconfigured).PHP_EOL;
echo 'Pasan a otra clasificacion:            '.count($newOther).' ('.implode(', ', $newOther).')'.PHP_EOL;
echo 'Permanecen No determinado:             '.$stillNoDeterminado.PHP_EOL;
echo 'No determinado DESPUES (estimado):     '.($stillNoDeterminado + $sinRespuesta).PHP_EOL;
