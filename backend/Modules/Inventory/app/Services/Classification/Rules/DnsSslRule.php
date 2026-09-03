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

        // Nota: se elimino a proposito la puntuacion "tiene MX -> es correo
        // institucional". El MX se configura a nivel de zona DNS, no de sitio:
        // casi cualquier subdominio *.udg.mx hereda el MX real de udg.mx sin
        // que el sitio en si tenga nada que ver con correo. Esto etiquetaba
        // ~1 de cada 3 sitios (bibliotecas, centros universitarios, paginas
        // institucionales normales) como "correo_institucional" solo por
        // heredar esa zona. La deteccion real de webmail vive en
        // HostnameHeuristicRule (host empieza con mail./correo.) y deberia
        // reforzarse con ContentSignatureRule si se detecta una firma de
        // interfaz de webmail real (Roundcube, Zimbra, OWA, etc.).
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
