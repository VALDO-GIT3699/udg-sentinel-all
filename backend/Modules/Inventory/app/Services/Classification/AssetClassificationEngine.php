<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Classification;

use Modules\Inventory\Services\Classification\Rules\AssetClassificationRuleInterface;

final class AssetClassificationEngine
{
    private const RULE_ENGINE_VERSION = 'rule-engine-v1';

    /**
     * @param iterable<int, AssetClassificationRuleInterface> $rules
     */
    public function __construct(private readonly iterable $rules)
    {
    }

    public function classify(AssetFingerprint $fingerprint): AssetClassificationResult
    {
        $typeScores = [];
        $roleScores = [];
        $evidence = [];
        $rulesUsed = [];
        $observations = [];
        $recommendations = [];

        foreach ($this->rules as $rule) {
            $result = $rule->evaluate($fingerprint);

            if (! is_array($result)) {
                continue;
            }

            $evidence[] = $result;
            $rulesUsed[] = (string) ($result['rule'] ?? 'unknown_rule');

            foreach ((array) ($result['type_scores'] ?? []) as $type => $score) {
                $typeScores[(string) $type] = ($typeScores[(string) $type] ?? 0.0) + (float) $score;
            }

            foreach ((array) ($result['role_scores'] ?? []) as $role => $score) {
                $roleScores[(string) $role] = ($roleScores[(string) $role] ?? 0.0) + (float) $score;
            }

            foreach ((array) ($result['observations'] ?? []) as $item) {
                $observations[] = (string) $item;
            }

            foreach ((array) ($result['recommendations'] ?? []) as $item) {
                $recommendations[] = (string) $item;
            }
        }

        if ($typeScores === []) {
            $typeScores['unknown'] = 1.0;
        }

        if ($roleScores === []) {
            $roleScores['unknown'] = 1.0;
        }

        arsort($typeScores);
        arsort($roleScores);

        $assetType = (string) array_key_first($typeScores);
        $assetRole = (string) array_key_first($roleScores);

        $confidence = $this->calculateConfidence($typeScores, $roleScores, count($evidence));

        $payloadToHash = [
            'asset_type' => $assetType,
            'asset_role' => $assetRole,
            'type_scores' => $typeScores,
            'role_scores' => $roleScores,
            'rules_used' => array_values(array_unique($rulesUsed)),
            'observations' => array_values(array_unique($observations)),
            'recommendations' => array_values(array_unique($recommendations)),
        ];

        return new AssetClassificationResult(
            assetType: $assetType,
            assetRole: $assetRole,
            confidencePct: $confidence,
            evidence: $evidence,
            typeScores: $typeScores,
            roleScores: $roleScores,
            classifierVersion: (string) config('inventory.classification.version', 'asset-classifier-v1'),
            ruleEngineVersion: self::RULE_ENGINE_VERSION,
            rulesUsed: array_values(array_unique($rulesUsed)),
            observations: array_values(array_unique($observations)),
            recommendations: array_values(array_unique($recommendations)),
            resultHash: hash('sha256', json_encode($payloadToHash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        );
    }

    /**
     * @param array<string, float> $typeScores
     * @param array<string, float> $roleScores
     */
    private function calculateConfidence(array $typeScores, array $roleScores, int $evidenceCount): int
    {
        $topType = (float) reset($typeScores);
        $topRole = (float) reset($roleScores);

        $sumType = array_sum($typeScores);
        $sumRole = array_sum($roleScores);

        $typeShare = $sumType > 0 ? $topType / $sumType : 0.0;
        $roleShare = $sumRole > 0 ? $topRole / $sumRole : 0.0;

        $evidenceFactor = min(1.0, 0.45 + ($evidenceCount * 0.12));
        $base = (($typeShare + $roleShare) / 2) * 100;

        return (int) max(0, min(99, round($base * $evidenceFactor)));
    }
}
