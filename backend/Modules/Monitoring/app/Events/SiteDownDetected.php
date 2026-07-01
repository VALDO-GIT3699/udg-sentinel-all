<?php

declare(strict_types=1);

namespace Modules\Monitoring\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Monitoring\Events\Concerns\SkipsBroadcastWhenUnavailable;

final class SiteDownDetected implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;
    use SkipsBroadcastWhenUnavailable;

    public function __construct(
        public readonly int $siteId,
        public readonly string $siteName,
        public readonly string $url,
        public readonly string $severity,
        public readonly ?string $cause,
        public readonly string $detectedAt,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('monitoring.sites');
    }

    public function broadcastAs(): string
    {
        return 'site.down.detected';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'siteId'     => $this->siteId,
            'siteName'   => $this->siteName,
            'url'        => $this->url,
            'severity'   => $this->severity,
            'cause'      => $this->cause,
            'detectedAt' => $this->detectedAt,
        ];
    }
}
