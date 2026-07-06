<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Classification\Rules;

use Modules\Inventory\Services\Classification\AssetFingerprint;

interface AssetClassificationRuleInterface
{
    public function name(): string;

    /**
     * @return array<string, mixed>|null
     */
    public function evaluate(AssetFingerprint $fingerprint): ?array;
}
