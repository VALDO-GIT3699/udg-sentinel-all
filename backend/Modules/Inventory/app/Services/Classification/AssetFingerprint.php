<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Classification;

final readonly class AssetFingerprint
{
    /**
     * @param array<string, mixed> $dns
     * @param array<string, array<int, string>> $httpHeaders
     * @param array<int, string> $redirectChain
     * @param array<int, array<string, mixed>> $technologies
     * @param array<string, mixed> $ssl
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public int $siteId,
        public string $host,
        public string $url,
        public ?int $httpStatus,
        public ?string $contentType,
        public array $httpHeaders,
        public string $htmlExcerpt,
        public bool $looksLikeJson,
        public bool $looksLikeXml,
        public array $redirectChain,
        public array $technologies,
        public array $dns,
        public array $ssl,
        public array $metadata,
    ) {
    }
}
