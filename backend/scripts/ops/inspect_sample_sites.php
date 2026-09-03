<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Modules\Monitoring\Services\SiteInspection\VerticalSiteInspectionEngine;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

/** @var VerticalSiteInspectionEngine $engine */
$engine = app(VerticalSiteInspectionEngine::class);

$sites = [
    ['url' => 'https://cdu.udg.mx', 'expected' => 'WordPress'],
    ['url' => 'https://centrocultural.org.mx', 'expected' => 'WordPress'],
    ['url' => 'https://fundacionudg.org', 'expected' => 'WordPress'],
    ['url' => 'https://hoteles.udg.mx', 'expected' => 'WordPress'],
    ['url' => 'https://teatrodiana.com', 'expected' => 'WordPress'],
    ['url' => 'https://rectoria.udg.mx', 'expected' => 'Drupal 7'],
    ['url' => 'https://cucba.udg.mx', 'expected' => 'Drupal 7'],
    ['url' => 'https://cienciaudg.mx', 'expected' => 'Drupal 9'],
    ['url' => 'https://bpej.udg.mx', 'expected' => 'Drupal 9'],
    ['url' => 'https://cgai.udg.mx', 'expected' => 'Drupal 10'],
    ['url' => 'https://proesde.udg.mx', 'expected' => 'Drupal 10'],
    ['url' => 'https://ci.cgai.udg.mx', 'expected' => 'Drupal 10'],
    ['url' => 'https://escolar.udg.mx', 'expected' => 'Drupal 10'],
    ['url' => 'https://titulacion.udg.mx', 'expected' => 'Drupal 10'],
    ['url' => 'https://serviciosocial.udg.mx', 'expected' => 'Drupal 10'],
    ['url' => 'https://patrimonio.udg.mx', 'expected' => 'Drupal 10'],
    ['url' => 'https://cgsu.udg.mx', 'expected' => 'Drupal 10'],
    ['url' => 'https://wdg.biblio.udg.mx/index.php', 'expected' => 'Joomla'],
    ['url' => 'https://artesescenicas.udg.mx', 'expected' => 'PHP'],
    ['url' => 'https://www.cucea.udg.mx', 'expected' => 'Laravel'],
];

$rows = [];

foreach ($sites as $site) {
    $result = $engine->inspectTarget($site['url']);
    $technology = is_array($result['technology'] ?? null) ? $result['technology'] : [];

    $detected = trim((string) ($technology['detected'] ?? $technology['cms'] ?? 'No determinado'));
    $version = trim((string) ($technology['cms_version'] ?? 'No determinado'));
    $confidence = (int) ($technology['confidence'] ?? 0);
    $expected = (string) $site['expected'];

    $correct = false;

    if ($expected === 'PHP') {
        $correct = $detected === 'PHP';
    } elseif ($expected === 'Joomla') {
        $correct = $detected === 'Joomla';
    } elseif ($expected === 'Laravel') {
        $correct = $detected === 'Laravel';
    } elseif ($expected === 'WordPress') {
        $correct = $detected === 'WordPress';
    } elseif (str_starts_with($expected, 'Drupal')) {
        $major = trim((string) preg_replace('/^Drupal\s*/', '', $expected));
        $correct = $detected === 'Drupal' && ($version === $major || str_starts_with($version, $major.'.'));
    }

    $rows[] = [
        'Sitio' => $site['url'],
        'Esperado' => $expected,
        'Detectado' => $detected,
        'Version' => $version,
        'Confidence' => $confidence,
        'Correcto' => $correct,
    ];
}

$correctCount = count(array_filter($rows, static fn (array $row): bool => $row['Correcto'] === true));

fwrite(STDOUT, json_encode([
    'rows' => $rows,
    'total' => count($rows),
    'correct' => $correctCount,
    'accuracy_pct' => round(($correctCount / max(1, count($rows))) * 100, 2),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
