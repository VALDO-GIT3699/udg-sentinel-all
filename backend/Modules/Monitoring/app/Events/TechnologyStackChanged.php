<?php

declare(strict_types=1);

namespace Modules\Monitoring\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Monitoring\Events\Concerns\SkipsBroadcastWhenUnavailable;

final class TechnologyStackChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;
    use SkipsBroadcastWhenUnavailable;

    /**
     * @param int                  $siteId
     * @param string[]             $added      Tecnologias nuevas detectadas en este snapshot
     * @param string[]             $removed    Tecnologias que desaparecieron respecto al snapshot anterior
     * @param array<string, mixed> $snapshot   Snapshot completo de tecnologias actuales
     */
    public function __construct(
        public readonly int $siteId,
        public readonly array $added,
        public readonly array $removed,
        public readonly array $snapshot,
        public readonly string $detectedAt,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('monitoring.sites');
    }

    public function broadcastAs(): string
    {
        return 'technology.stack.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'siteId'     => $this->siteId,
            'added'      => $this->added,
            'removed'    => $this->removed,
            'snapshot'   => $this->snapshot,
            'detectedAt' => $this->detectedAt,
        ];
    }
}
