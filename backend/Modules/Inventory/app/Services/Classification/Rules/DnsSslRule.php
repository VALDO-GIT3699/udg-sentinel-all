<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Classification\Rules;

use Modules\Inventory\Services\Classification\AssetFingerprint;

final class DnsSslRule implements AssetClassificationRuleInterface
{
    public function name(): string
    {
        return 'dns_ssl';
    }

    public function evaluate(AssetFingerprint $fingerprint): ?array
    {
        $typeScores = [];
        $roleScores = [];
        $evidence = [];

        $dns = $fingerprint->dns;
        $mxRecords = (int) ($dns['mx_count'] ?? 0);

        if ($mxRecords > 0) {
            $typeScores['mail_server'] = ($typeScores['mail_server'] ?? 0.0) + 3.0;
            $roleScores['correo_institucional'] = ($roleScores['correo_institucional'] ?? 0.0) + 2.8;
            $evidence[] = 'mx_records';
        }

        if (($dns['a_count'] ?? 0) > 0 || ($dns['aaaa_count'] ?? 0) > 0) {
            $typeScores['website'] = ($typeScores['website'] ?? 0.0) + 0.8;
            $evidence[] = 'address_records';
        }

        $ssl = $fingerprint->ssl;

        if ((bool) ($ssl['is_valid'] ?? false)) {
            $typeScores['website'] = ($typeScores['website'] ?? 0.0) + 0.6;
            $typeScores['web_application'] = ($typeScores['web_application'] ?? 0.0) + 0.6;
            $evidence[] = 'valid_ssl';
        }

        if ($typeScores === [] && $roleScores === []) {
            return null;
        }

        return [
            'rule' => $this->name(),
            'type_scores' => $typeScores,
            'role_scores' => $roleScores,
            'confidence_hint' => 0.61,
            'observations' => ['Se utilizo evidencia de DNS y SSL para ajustar la clasificacion.'],
            'recommendations' => ['Mantener registros DNS y certificados alineados al tipo real del activo.'],
            'evidence' => [
                'dns' => $dns,
                'ssl' => [
                    'is_valid' => (bool) ($ssl['is_valid'] ?? false),
                    'days_remaining' => $ssl['days_remaining'] ?? null,
                ],
                'matched' => $evidence,
            ],
        ];
    }
}
