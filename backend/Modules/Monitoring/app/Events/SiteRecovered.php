<?php

declare(strict_types=1);

namespace Modules\Monitoring\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Monitoring\Events\Concerns\SkipsBroadcastWhenUnavailable;

final class SiteRecovered implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;
    use SkipsBroadcastWhenUnavailable;

    public function __construct(
        public readonly int $siteId,
        public readonly string $siteName,
        public readonly string $url,
        public readonly string $recoveredAt,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('monitoring.sites');
    }

    public function broadcastAs(): string
    {
        return 'site.recovered';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'siteId'      => $this->siteId,
            'siteName'    => $this->siteName,
            'url'         => $this->url,
            'recoveredAt' => $this->recoveredAt,
        ];
    }
}
