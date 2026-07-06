<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Inventory\Services\Classification\AssetClassificationEngine;
use Modules\Inventory\Services\Classification\AssetFingerprint;
use Modules\Inventory\Services\Classification\Rules\ContentSignatureRule;
use Modules\Inventory\Services\Classification\Rules\DnsSslRule;
use Modules\Inventory\Services\Classification\Rules\HostnameHeuristicRule;
use Modules\Inventory\Services\Classification\Rules\TechnologyFingerprintRule;
use Tests\TestCase;

final class AssetClassificationEngineTest extends TestCase
{
    public function test_it_classifies_moodle_asset_as_web_application_lms(): void
    {
        $engine = new AssetClassificationEngine([
            new TechnologyFingerprintRule(),
            new ContentSignatureRule(),
            new DnsSslRule(),
            new HostnameHeuristicRule(),
        ]);

        $fingerprint = new AssetFingerprint(
            siteId: 1,
            host: 'moodle.sems.udg.mx',
            url: 'https://moodle.sems.udg.mx',
            httpStatus: 200,
            contentType: 'text/html; charset=UTF-8',
            httpHeaders: ['server' => ['nginx']],
            htmlExcerpt: '<html><title>Moodle</title><body>Moodle platform</body></html>',
            looksLikeJson: false,
            looksLikeXml: false,
            redirectChain: [],
            technologies: [
                ['slug' => 'moodle', 'name' => 'Moodle', 'confidence_pct' => 96],
                ['slug' => 'php', 'name' => 'PHP', 'confidence_pct' => 90],
            ],
            dns: [
                'a_count' => 1,
                'aaaa_count' => 0,
                'mx_count' => 0,
                'cname_count' => 0,
                'txt_count' => 0,
            ],
            ssl: [
                'is_valid' => true,
                'days_remaining' => 120,
            ],
            metadata: [
                'site_group' => 'sems',
                'current_status' => 'up',
            ],
        );

        $result = $engine->classify($fingerprint);

        $this->assertSame('web_application', $result->assetType);
        $this->assertSame('lms', $result->assetRole);
        $this->assertGreaterThanOrEqual(60, $result->confidencePct);
        $this->assertNotEmpty($result->evidence);
    }
}
