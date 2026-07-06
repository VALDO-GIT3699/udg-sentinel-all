<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Classification;

use App\Contracts\Repositories\AssetClassificationRepositoryInterface;
use App\Contracts\Repositories\SiteRepositoryInterface;
use App\Models\Site;
use App\Support\AssetIntelligenceSchema;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Events\AssetClassified;
use Modules\Inventory\Events\AssetReclassified;
use Modules\Inventory\Events\ClassificationOverridden;
use Modules\Inventory\Services\Classification\Rules\ContentSignatureRule;
use Modules\Inventory\Services\Classification\Rules\DnsSslRule;
use Modules\Inventory\Services\Classification\Rules\HostnameHeuristicRule;
use Modules\Inventory\Services\Classification\Rules\TechnologyFingerprintRule;

final class AssetClassificationService
{
    public function __construct(
        private readonly AssetClassificationRepositoryInterface $assetClassificationRepository,
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly AssetFingerprintBuilder $assetFingerprintBuilder,
        private readonly AssetIntelligenceSchema $assetSchema,
    ) {
    }

    public function classifyAutomatically(Site $site): ?AssetClassificationResult
    {
        if (! $this->assetSchema->isReady()) {
            return null;
        }

        $locked = $site->asset_classification_source === 'manual' || $site->asset_classification_locked_at !== null;

        if ($locked) {
            return null;
        }

        $engine = new AssetClassificationEngine([
            new TechnologyFingerprintRule(),
            new ContentSignatureRule(),
            new DnsSslRule(),
            new HostnameHeuristicRule(),
        ]);

        $fingerprint = $this->assetFingerprintBuilder->build($site);
        $result = $engine->classify($fingerprint);
        $previousType = (string) ($site->asset_type ?? 'unknown');
        $previousRole = (string) ($site->asset_role ?? 'unknown');

        DB::transaction(function () use ($site, $result): void {
            $this->assetClassificationRepository->createForSite($site, [
                'source' => 'automatic',
                'asset_type' => $result->assetType,
                'asset_role' => $result->assetRole,
                'confidence_pct' => $result->confidencePct,
                'evidence' => $result->evidence,
                'scores' => [
                    'type' => $result->typeScores,
                    'role' => $result->roleScores,
                ],
                'classifier_version' => $result->classifierVersion,
                    'rule_engine_version' => $result->ruleEngineVersion,
                    'result_hash' => $result->resultHash,
                    'rules_used' => $result->rulesUsed,
                    'observations' => $result->observations,
                    'recommendations' => $result->recommendations,
                'classified_at' => now(),
                'is_current' => true,
            ]);

            $this->siteRepository->update($site, [
                'asset_type' => $result->assetType,
                'asset_role' => $result->assetRole,
                'asset_confidence_pct' => $result->confidencePct,
                'asset_classification_source' => 'automatic',
                'asset_classifier_version' => $result->classifierVersion,
                'asset_last_classified_at' => now(),
                'asset_classification_evidence' => [
                    'rules' => $result->evidence,
                    'scores' => [
                        'type' => $result->typeScores,
                        'role' => $result->roleScores,
                    ],
                ],
            ]);
        });

        event(new AssetClassified(
            siteId: (int) $site->id,
            source: 'automatic',
            payload: $result->toArray(),
            classifiedAt: now()->toIso8601String(),
        ));

        if ($previousType !== $result->assetType || $previousRole !== $result->assetRole) {
            event(new AssetReclassified(
                siteId: (int) $site->id,
                previousType: $previousType,
                previousRole: $previousRole,
                newType: $result->assetType,
                newRole: $result->assetRole,
                classifiedAt: now()->toIso8601String(),
            ));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setManualClassification(Site $site, array $payload, ?int $userId = null): AssetClassificationResult
    {
        if (! $this->assetSchema->isReady()) {
            throw new \RuntimeException('Asset Intelligence no esta disponible en el esquema actual.');
        }

        $assetType = (string) ($payload['asset_type'] ?? 'unknown');
        $assetRole = (string) ($payload['asset_role'] ?? 'unknown');
        $confidence = max(0, min(100, (int) ($payload['confidence_pct'] ?? 100)));
        $notes = isset($payload['notes']) ? (string) $payload['notes'] : null;
        $previousType = (string) ($site->asset_type ?? 'unknown');
        $previousRole = (string) ($site->asset_role ?? 'unknown');
        $previousSource = (string) ($site->asset_classification_source ?? 'none');

        $result = new AssetClassificationResult(
            assetType: $assetType,
            assetRole: $assetRole,
            confidencePct: $confidence,
            evidence: [[
                'rule' => 'manual_override',
                'type_scores' => [$assetType => 1.0],
                'role_scores' => [$assetRole => 1.0],
                'confidence_hint' => 1.0,
                'evidence' => ['notes' => $notes],
            ]],
            typeScores: [$assetType => 1.0],
            roleScores: [$assetRole => 1.0],
            classifierVersion: (string) config('inventory.classification.version', 'asset-classifier-v1'),
            ruleEngineVersion: 'rule-engine-v1',
            rulesUsed: ['manual_override'],
            observations: ['Clasificacion establecida manualmente por administracion.'],
            recommendations: [],
            resultHash: hash('sha256', $assetType . '|' . $assetRole . '|' . $confidence . '|' . (string) $notes),
        );

        DB::transaction(function () use ($site, $result, $userId, $notes): void {
            $this->assetClassificationRepository->createForSite($site, [
                'source' => 'manual',
                'asset_type' => $result->assetType,
                'asset_role' => $result->assetRole,
                'confidence_pct' => $result->confidencePct,
                'evidence' => $result->evidence,
                'scores' => [
                    'type' => $result->typeScores,
                    'role' => $result->roleScores,
                ],
                'classifier_version' => $result->classifierVersion,
                'classified_at' => now(),
                'is_current' => true,
                'created_by' => $userId,
                'notes' => $notes,
                'rule_engine_version' => $result->ruleEngineVersion,
                'rules_used' => $result->rulesUsed,
                'observations' => $result->observations,
                'recommendations' => $result->recommendations,
                'result_hash' => $result->resultHash,
            ]);

            $this->siteRepository->update($site, [
                'asset_type' => $result->assetType,
                'asset_role' => $result->assetRole,
                'asset_confidence_pct' => $result->confidencePct,
                'asset_classification_source' => 'manual',
                'asset_classifier_version' => $result->classifierVersion,
                'asset_last_classified_at' => now(),
                'asset_classification_locked_at' => now(),
                'asset_classification_evidence' => [
                    'manual' => true,
                    'notes' => $notes,
                ],
            ]);
        });

        event(new AssetClassified(
            siteId: (int) $site->id,
            source: 'manual',
            payload: $result->toArray(),
            classifiedAt: now()->toIso8601String(),
        ));

        if ($previousType !== $result->assetType || $previousRole !== $result->assetRole) {
            event(new AssetReclassified(
                siteId: (int) $site->id,
                previousType: $previousType,
                previousRole: $previousRole,
                newType: $result->assetType,
                newRole: $result->assetRole,
                classifiedAt: now()->toIso8601String(),
            ));
        }

        event(new ClassificationOverridden(
            siteId: (int) $site->id,
            previousSource: $previousSource,
            newSource: 'manual',
            reason: $notes !== null && trim($notes) !== '' ? $notes : 'manual_override',
            userId: $userId,
            overriddenAt: now()->toIso8601String(),
        ));

        return $result;
    }
}
