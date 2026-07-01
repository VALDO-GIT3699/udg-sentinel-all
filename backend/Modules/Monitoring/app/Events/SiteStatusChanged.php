<?php

declare(strict_types=1);

namespace Modules\Monitoring\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Monitoring\Events\Concerns\SkipsBroadcastWhenUnavailable;

final class SiteStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;
    use SkipsBroadcastWhenUnavailable;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public readonly int $siteId, public readonly array $payload)
    {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('monitoring.sites');
    }

    public function broadcastAs(): string
    {
        return 'site.status.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'siteId' => $this->siteId,
            ...$this->payload,
        ];
    }
}
