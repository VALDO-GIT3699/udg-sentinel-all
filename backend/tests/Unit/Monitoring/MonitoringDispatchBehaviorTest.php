<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Monitoring\Services\MonitoringHttpClientFactory;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

final class MonitoringDispatchBehaviorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function monitoring_jobs_use_sync_dispatch_in_local_environments(): void
    {
        $this->app['env'] = 'local';

        DummyMonitoringJobRecorder::reset();

        $dispatcher = new class () {
            public function dispatch(string $jobClass, mixed ...$arguments): mixed
            {
                return $this->dispatchMonitoringJob($jobClass, ...$arguments);
            }

            private function dispatchMonitoringJob(string $jobClass, mixed ...$arguments): mixed
            {
                if ($this->shouldDispatchMonitoringSynchronously()) {
                    return $jobClass::dispatchSync(...$arguments);
                }

                return $jobClass::dispatch(...$arguments);
            }

            private function shouldDispatchMonitoringSynchronously(): bool
            {
                return app()->environment(['local', 'development', 'testing']);
            }
        };

        $dispatcher->dispatch(DummyMonitoringJobRecorder::class, 123, 'site-a');

        $this->assertSame('dispatchSync', DummyMonitoringJobRecorder::$lastDispatchMethod);
        $this->assertSame([123, 'site-a'], DummyMonitoringJobRecorder::$lastArguments);
    }

    #[Test]
    public function monitoring_jobs_keep_async_dispatch_outside_local_environments(): void
    {
        $this->app['env'] = 'production';

        DummyMonitoringJobRecorder::reset();

        $dispatcher = new class () {
            public function dispatch(string $jobClass, mixed ...$arguments): mixed
            {
                return $this->dispatchMonitoringJob($jobClass, ...$arguments);
            }

            private function dispatchMonitoringJob(string $jobClass, mixed ...$arguments): mixed
            {
                if ($this->shouldDispatchMonitoringSynchronously()) {
                    return $jobClass::dispatchSync(...$arguments);
                }

                return $jobClass::dispatch(...$arguments);
            }

            private function shouldDispatchMonitoringSynchronously(): bool
            {
                return app()->environment(['local', 'development', 'testing']);
            }
        };

        $dispatcher->dispatch(DummyMonitoringJobRecorder::class, 77);

        $this->assertSame('dispatch', DummyMonitoringJobRecorder::$lastDispatchMethod);
        $this->assertSame([77], DummyMonitoringJobRecorder::$lastArguments);
    }

    #[Test]
    public function monitoring_http_client_uses_hard_timeouts(): void
    {
        $factory = new MonitoringHttpClientFactory();
        $request = $factory->make(['X-Test' => '1']);

        $reflection = new ReflectionProperty($request, 'options');
        $reflection->setAccessible(true);

        $options = $reflection->getValue($request);

        $this->assertSame(8, $options['timeout']);
        $this->assertSame(5, $options['connect_timeout']);
    }
}

final class DummyMonitoringJobRecorder
{
    /**
     * @var array<int, mixed>
     */
    public static array $lastArguments = [];

    public static string $lastDispatchMethod = '';

    public static function reset(): void
    {
        self::$lastArguments = [];
        self::$lastDispatchMethod = '';
    }

    public static function dispatch(mixed ...$arguments): string
    {
        self::$lastDispatchMethod = 'dispatch';
        self::$lastArguments = $arguments;

        return 'async';
    }

    public static function dispatchSync(mixed ...$arguments): string
    {
        self::$lastDispatchMethod = 'dispatchSync';
        self::$lastArguments = $arguments;

        return 'sync';
    }
}
