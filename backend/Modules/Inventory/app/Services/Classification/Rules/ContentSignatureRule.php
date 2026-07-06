<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Classification\Rules;

use Modules\Inventory\Services\Classification\AssetFingerprint;

final class ContentSignatureRule implements AssetClassificationRuleInterface
{
    public function name(): string
    {
        return 'content_signature';
    }

    public function evaluate(AssetFingerprint $fingerprint): ?array
    {
        $typeScores = [];
        $roleScores = [];
        $evidence = [];

        $contentType = mb_strtolower((string) ($fingerprint->contentType ?? ''));
        $html = mb_strtolower($fingerprint->htmlExcerpt);

        if ($fingerprint->looksLikeJson || str_contains($contentType, 'application/json')) {
            $typeScores['rest_api'] = ($typeScores['rest_api'] ?? 0.0) + 3.4;
            $roleScores['integracion'] = ($roleScores['integracion'] ?? 0.0) + 1.8;
            $evidence[] = 'json_payload';
        }

        if ($fingerprint->looksLikeXml || str_contains($contentType, 'xml') || str_contains($contentType, 'soap')) {
            $typeScores['soap_api'] = ($typeScores['soap_api'] ?? 0.0) + 3.0;
            $roleScores['integracion'] = ($roleScores['integracion'] ?? 0.0) + 1.6;
            $evidence[] = 'xml_or_soap';
        }

        if (str_contains($html, 'moodle')) {
            $typeScores['web_application'] = ($typeScores['web_application'] ?? 0.0) + 2.8;
            $roleScores['lms'] = ($roleScores['lms'] ?? 0.0) + 3.4;
            $evidence[] = 'moodle_signature';
        }

        if (str_contains($html, 'drupal')) {
            $typeScores['website'] = ($typeScores['website'] ?? 0.0) + 2.2;
            $roleScores['institucional'] = ($roleScores['institucional'] ?? 0.0) + 1.3;
            $evidence[] = 'drupal_signature';
        }

        if (str_contains($html, 'siiau')) {
            $typeScores['web_application'] = ($typeScores['web_application'] ?? 0.0) + 3.2;
            $roleScores['sistema_escolar'] = ($roleScores['sistema_escolar'] ?? 0.0) + 3.5;
            $roleScores['siiau'] = ($roleScores['siiau'] ?? 0.0) + 3.8;
            $evidence[] = 'siiau_signature';
        }

        if ($typeScores === [] && $roleScores === []) {
            return null;
        }

        return [
            'rule' => $this->name(),
            'type_scores' => $typeScores,
            'role_scores' => $roleScores,
            'confidence_hint' => 0.74,
            'observations' => ['Se detectaron firmas de contenido y/o content-type relevantes.'],
            'recommendations' => ['Mantener endpoints canónicos con respuestas consistentes para mejorar clasificación.'],
            'evidence' => [
                'content_type' => $contentType,
                'signatures' => $evidence,
            ],
        ];
    }
}
