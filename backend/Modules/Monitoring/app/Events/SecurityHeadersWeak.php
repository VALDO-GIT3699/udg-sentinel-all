<?php

declare(strict_types=1);

namespace Modules\Monitoring\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Monitoring\Events\Concerns\SkipsBroadcastWhenUnavailable;

final class SecurityHeadersWeak implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;
    use SkipsBroadcastWhenUnavailable;

    /**
     * @param string[] $missing  Names of missing/failing headers
     */
    public function __construct(
        public readonly int $siteId,
        public readonly int $score,
        public readonly string $level,
        public readonly array $missing,
        public readonly string $checkedAt,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('monitoring.sites');
    }

    public function broadcastAs(): string
    {
        return 'security.headers.weak';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'siteId'    => $this->siteId,
            'score'     => $this->score,
            'level'     => $this->level,
            'missing'   => $this->missing,
            'checkedAt' => $this->checkedAt,
        ];
    }
}
