<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Classification\Rules;

use Modules\Inventory\Services\Classification\AssetFingerprint;

final class TechnologyFingerprintRule implements AssetClassificationRuleInterface
{
    public function name(): string
    {
        return 'technology_fingerprint';
    }

    public function evaluate(AssetFingerprint $fingerprint): ?array
    {
        $typeScores = [];
        $roleScores = [];
        $matched = [];

        foreach ($fingerprint->technologies as $technology) {
            $slug = mb_strtolower((string) ($technology['slug'] ?? ''));

            if ($slug === '') {
                continue;
            }

            $matched[] = $slug;

            if (in_array($slug, ['laravel', 'symfony', 'spring', 'django'], true)) {
                $typeScores['web_application'] = ($typeScores['web_application'] ?? 0.0) + 2.2;
                $roleScores['institucional'] = ($roleScores['institucional'] ?? 0.0) + 1.0;
            }

            if (in_array($slug, ['drupal', 'wordpress'], true)) {
                $typeScores['website'] = ($typeScores['website'] ?? 0.0) + 2.5;
            }

            if (in_array($slug, ['moodle'], true)) {
                $typeScores['web_application'] = ($typeScores['web_application'] ?? 0.0) + 2.6;
                $roleScores['lms'] = ($roleScores['lms'] ?? 0.0) + 3.2;
            }

            if (in_array($slug, ['jenkins', 'gitlab', 'gitea'], true)) {
                $typeScores['devops'] = ($typeScores['devops'] ?? 0.0) + 3.0;
                $roleScores['desarrollo'] = ($roleScores['desarrollo'] ?? 0.0) + 1.8;
            }

            if (in_array($slug, ['postgresql', 'mysql', 'mariadb'], true)) {
                $typeScores['database'] = ($typeScores['database'] ?? 0.0) + 2.9;
                $roleScores['infraestructura'] = ($roleScores['infraestructura'] ?? 0.0) + 1.4;
            }
        }

        if ($typeScores === [] && $roleScores === []) {
            return null;
        }

        return [
            'rule' => $this->name(),
            'type_scores' => $typeScores,
            'role_scores' => $roleScores,
            'confidence_hint' => 0.78,
            'observations' => ['La clasificacion se reforzo por stack tecnologico detectado.'],
            'recommendations' => ['Actualizar deteccion de tecnologias para activos con stack cambiante.'],
            'evidence' => [
                'technology_slugs' => array_values(array_unique($matched)),
            ],
        ];
    }
}
