<?php

declare(strict_types=1);

namespace Modules\Monitoring\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class CertificateExpiring
{
    use Dispatchable;

    public function __construct(
        public int $siteId,
        public int $daysRemaining,
        public string $severity,
        public string $checkedAt,
    ) {
    }
}
