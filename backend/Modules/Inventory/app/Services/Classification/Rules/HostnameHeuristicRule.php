<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Classification\Rules;

use Modules\Inventory\Services\Classification\AssetFingerprint;

final class HostnameHeuristicRule implements AssetClassificationRuleInterface
{
    public function name(): string
    {
        return 'hostname_heuristic';
    }

    public function evaluate(AssetFingerprint $fingerprint): ?array
    {
        $host = mb_strtolower($fingerprint->host);
        $typeScores = [];
        $roleScores = [];
        $matched = [];

        $map = [
            'api.' => ['type' => 'rest_api', 'role' => 'integracion', 'weight' => 2.5],
            'graphql.' => ['type' => 'graphql', 'role' => 'integracion', 'weight' => 2.8],
            'soap.' => ['type' => 'soap_api', 'role' => 'integracion', 'weight' => 2.6],
            'mail.' => ['type' => 'mail_server', 'role' => 'correo_institucional', 'weight' => 2.8],
            'correo.' => ['type' => 'mail_server', 'role' => 'correo_institucional', 'weight' => 3.0],
            'vpn.' => ['type' => 'vpn', 'role' => 'infraestructura', 'weight' => 2.8],
            'dns.' => ['type' => 'dns', 'role' => 'infraestructura', 'weight' => 2.4],
            'git.' => ['type' => 'devops', 'role' => 'control_de_versiones', 'weight' => 3.0],
            'jenkins.' => ['type' => 'devops', 'role' => 'desarrollo', 'weight' => 2.8],
            'moodle.' => ['type' => 'web_application', 'role' => 'lms', 'weight' => 3.2],
            'siiau.' => ['type' => 'web_application', 'role' => 'sistema_escolar', 'weight' => 3.2],
            'sso.' => ['type' => 'authentication', 'role' => 'seguridad', 'weight' => 2.7],
            'ldap.' => ['type' => 'authentication', 'role' => 'seguridad', 'weight' => 2.7],
            'cuc' => ['type' => 'website', 'role' => 'centro_universitario', 'weight' => 1.9],
            'sems.' => ['type' => 'website', 'role' => 'institucional', 'weight' => 1.6],
        ];

        foreach ($map as $needle => $weights) {
            if (! str_contains($host, $needle)) {
                continue;
            }

            $type = (string) $weights['type'];
            $role = (string) $weights['role'];
            $weight = (float) $weights['weight'];

            $typeScores[$type] = ($typeScores[$type] ?? 0.0) + $weight;
            $roleScores[$role] = ($roleScores[$role] ?? 0.0) + $weight;
            $matched[] = $needle;
        }

        if ($typeScores === [] && $roleScores === []) {
            return null;
        }

        return [
            'rule' => $this->name(),
            'type_scores' => $typeScores,
            'role_scores' => $roleScores,
            'confidence_hint' => 0.68,
            'observations' => ['Coincidencias por patron de hostname institucional.'],
            'recommendations' => ['Validar manualmente activos con coincidencias ambiguas de hostname.'],
            'evidence' => [
                'host' => $host,
                'matched_tokens' => $matched,
            ],
        ];
    }
}
