<?php

declare(strict_types=1);

namespace Modules\Monitoring\Events\Concerns;

trait SkipsBroadcastWhenUnavailable
{
    public function broadcastWhen(): bool
    {
        if (app()->environment('local')) {
            return false;
        }

        $defaultConnection = (string) config('broadcasting.default', 'null');

        if ($defaultConnection === '' || $defaultConnection === 'null' || $defaultConnection === 'log') {
            return false;
        }

        return class_exists('Pusher\\Pusher');
    }

    public function shouldBroadcast(): bool
    {
        return $this->broadcastWhen();
    }
}
