<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Illuminate\Foundation\Application;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Psr\Log\LoggerInterface;
use Waynestate\Helmet\Metrics\Metrics;
use Waynestate\Helmet\Metrics\MetricsStorage;
use Waynestate\Helmet\Metrics\RequestMetrics;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\ArrayLogger;

/**
 * The Laravel adapter counts sign-ins when sesame.metrics.enabled is on:
 * into the app's Prometheus\Storage\Adapter binding, else into HELMET's
 * request-metrics store, where HELMET's /metrics renders them (ADR-0011).
 */
final class LoginMetricsTest extends LaravelTestCase
{
    private ?string $sqlite = null;

    protected function tearDown(): void
    {
        if ($this->sqlite !== null) {
            foreach ([$this->sqlite, $this->sqlite . '-wal', $this->sqlite . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        parent::tearDown();
    }

    private function app(): Application
    {
        $app = $this->app;
        self::assertNotNull($app);

        return $app;
    }

    private function render(Adapter $storage): string
    {
        return (new RenderTextFormat())->render((new CollectorRegistry($storage, false))->getMetricFamilySamples());
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function metricsFailures(ArrayLogger $logger): array
    {
        return $logger->withMessage('sesame.metrics_failed');
    }

    public function testMetricsAreOffByDefault(): void
    {
        $storage = new InMemory();
        $this->app()->instance(Adapter::class, $storage);

        $this->get('/login')->assertRedirect('/');

        self::assertStringNotContainsString('sesame_logins_total', $this->render($storage));
    }

    public function testABoundStoreCountsTheSignIn(): void
    {
        $app = $this->app();
        $app['config']->set('sesame.metrics.enabled', true);
        $storage = new InMemory();
        $app->instance(Adapter::class, $storage);

        $this->get('/login')->assertRedirect('/');

        self::assertStringContainsString('sesame_logins_total{driver="fake",outcome="success"} 1', $this->render($storage));
    }

    public function testTheSettingIsReadAsABoolean(): void
    {
        $app = $this->app();
        $app['config']->set('sesame.metrics.enabled', 'false');
        $storage = new InMemory();
        $app->instance(Adapter::class, $storage);

        $this->get('/login')->assertRedirect('/');

        self::assertStringNotContainsString('sesame_logins_total', $this->render($storage));
    }

    public function testTheCountShowsUpOnHelmetsMetricsFromItsSqliteStore(): void
    {
        $this->sqlite = sys_get_temp_dir() . '/sesame-metrics-' . bin2hex(random_bytes(6)) . '.sqlite';
        $app = $this->app();
        $app['config']->set('sesame.metrics.enabled', true);
        $app['config']->set('helmet.metrics', [
            'enabled' => true,
            'storage' => 'sqlite',
            'redis_url' => null,
            'sqlite_path' => $this->sqlite,
        ]);

        $this->get('/login')->assertRedirect('/');

        // What HELMET's /metrics endpoint renders for this app: its own
        // gauges plus every family in the shared store.
        $storage = MetricsStorage::create('sqlite', null, $this->sqlite);
        self::assertNotNull($storage);
        $scrape = (new Metrics(null, null, null, '1.4.1', new RequestMetrics($storage), []))->render();

        self::assertStringContainsString('helmet_info{', $scrape);
        self::assertStringContainsString('# TYPE sesame_logins_total counter', $scrape);
        self::assertStringContainsString('sesame_logins_total{driver="fake",outcome="success"} 1', $scrape);
    }

    public function testNoStoreLogsTheReasonAndStillSignsIn(): void
    {
        $app = $this->app();
        $app['config']->set('sesame.metrics.enabled', true);
        $logger = new ArrayLogger();
        $app->instance(LoggerInterface::class, $logger);

        $this->get('/login')->assertRedirect('/');

        self::assertSame('the-user', $app->make(Sesame::class)->user());
        $failures = $this->metricsFailures($logger);
        self::assertCount(1, $failures);
        self::assertSame('fake', $failures[0]['context']['driver']);
        self::assertStringContainsString('HELMET_METRICS_STORAGE', (string) $failures[0]['context']['reason']);
    }

    public function testHelmetsStoreIsNotUsedWhenHelmetsMetricsAreOff(): void
    {
        $this->sqlite = sys_get_temp_dir() . '/sesame-metrics-' . bin2hex(random_bytes(6)) . '.sqlite';
        $app = $this->app();
        $app['config']->set('sesame.metrics.enabled', true);
        $app['config']->set('helmet.metrics', [
            'enabled' => false,
            'storage' => 'sqlite',
            'sqlite_path' => $this->sqlite,
        ]);
        $logger = new ArrayLogger();
        $app->instance(LoggerInterface::class, $logger);

        $this->get('/login')->assertRedirect('/');

        self::assertCount(1, $this->metricsFailures($logger));
    }

    public function testABrokenHelmetStoreNeverBreaksTheSignIn(): void
    {
        $app = $this->app();
        $app['config']->set('sesame.metrics.enabled', true);
        $app['config']->set('helmet.metrics', [
            'enabled' => true,
            'storage' => 'redis',
            'redis_url' => 'not a redis url, with a password in it: hunter2',
        ]);
        $logger = new ArrayLogger();
        $app->instance(LoggerInterface::class, $logger);

        $this->get('/login')->assertRedirect('/');

        self::assertSame('the-user', $app->make(Sesame::class)->user());
        $failures = $this->metricsFailures($logger);
        self::assertCount(1, $failures);
        self::assertArrayNotHasKey('reason', $failures[0]['context']);
        self::assertStringNotContainsString('hunter2', (string) json_encode($logger->records));
    }
}
