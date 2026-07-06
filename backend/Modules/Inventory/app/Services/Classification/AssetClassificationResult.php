<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Classification;

final readonly class AssetClassificationResult
{
    /**
     * @param array<int, array<string, mixed>> $evidence
     * @param array<string, float> $typeScores
     * @param array<string, float> $roleScores
     */
    public function __construct(
        public string $assetType,
        public string $assetRole,
        public int $confidencePct,
        public array $evidence,
        public array $typeScores,
        public array $roleScores,
        public string $classifierVersion,
        public string $ruleEngineVersion,
        public array $rulesUsed,
        public array $observations,
        public array $recommendations,
        public string $resultHash,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'asset_type' => $this->assetType,
            'asset_role' => $this->assetRole,
            'confidence_pct' => $this->confidencePct,
            'evidence' => $this->evidence,
            'scores' => [
                'type' => $this->typeScores,
                'role' => $this->roleScores,
            ],
            'classifier_version' => $this->classifierVersion,
            'rule_engine_version' => $this->ruleEngineVersion,
            'rules_used' => $this->rulesUsed,
            'observations' => $this->observations,
            'recommendations' => $this->recommendations,
            'result_hash' => $this->resultHash,
        ];
    }
}
