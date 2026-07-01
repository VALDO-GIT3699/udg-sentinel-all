<?php

declare(strict_types=1);

namespace Modules\Monitoring\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Monitoring\Events\Concerns\SkipsBroadcastWhenUnavailable;

final class SslExpired implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;
    use SkipsBroadcastWhenUnavailable;

    public function __construct(
        public readonly int $siteId,
        public readonly int $daysOverdue,
        public readonly string $checkedAt,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('monitoring.ssl');
    }

    public function broadcastAs(): string
    {
        return 'ssl.expired';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'siteId'     => $this->siteId,
            'daysOverdue' => $this->daysOverdue,
            'checkedAt'  => $this->checkedAt,
        ];
    }
}
