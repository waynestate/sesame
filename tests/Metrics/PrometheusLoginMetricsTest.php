<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Metrics\LoginMetrics;
use Waynestate\Sesame\Metrics\PrometheusLoginMetrics;

/**
 * sesame_logins_total is written to a promphp store with bounded labels,
 * and its store can be built on the first count.
 */
final class PrometheusLoginMetricsTest extends TestCase
{
    private function render(Adapter $storage): string
    {
        return (new RenderTextFormat())->render((new CollectorRegistry($storage, false))->getMetricFamilySamples());
    }

    public function testItCountsSignInsByDriverAndOutcome(): void
    {
        $storage = new InMemory();
        $metrics = new PrometheusLoginMetrics($storage);

        $metrics->login('cas', LoginMetrics::SUCCESS);
        $metrics->login('cas', LoginMetrics::SUCCESS);
        $metrics->login('cas', LoginMetrics::UNAVAILABLE);
        $metrics->login('oidc', LoginMetrics::REFUSED);

        $text = $this->render($storage);
        self::assertStringContainsString('# TYPE sesame_logins_total counter', $text);
        self::assertStringContainsString('sesame_logins_total{driver="cas",outcome="success"} 2', $text);
        self::assertStringContainsString('sesame_logins_total{driver="cas",outcome="unavailable"} 1', $text);
        self::assertStringContainsString('sesame_logins_total{driver="oidc",outcome="refused"} 1', $text);
    }

    public function testUnknownLabelsBecomeOther(): void
    {
        $storage = new InMemory();
        $metrics = new PrometheusLoginMetrics($storage);

        $metrics->login('jdoe@wayne.edu', 'whatever the caller said');

        $text = $this->render($storage);
        self::assertStringContainsString('sesame_logins_total{driver="other",outcome="other"} 1', $text);
        self::assertStringNotContainsString('jdoe', $text);
    }

    public function testEveryShippedDriverAndOutcomeKeepsItsLabel(): void
    {
        foreach (['cas', 'fake', 'gateway', 'oauth2', 'oidc', 'saml'] as $driver) {
            self::assertSame($driver, PrometheusLoginMetrics::driverLabel($driver));
        }
        foreach (LoginMetrics::OUTCOMES as $outcome) {
            self::assertSame($outcome, PrometheusLoginMetrics::outcomeLabel($outcome));
        }
        self::assertSame(['success', 'failed', 'refused', 'unavailable'], LoginMetrics::OUTCOMES);
    }

    public function testAFactoryIsBuiltOnTheFirstCountOnly(): void
    {
        $storage = new InMemory();
        $built = 0;
        $metrics = new PrometheusLoginMetrics(static function () use ($storage, &$built): Adapter {
            $built++;

            return $storage;
        });

        self::assertSame(0, $built);

        $metrics->login('saml', LoginMetrics::FAILED);
        $metrics->login('saml', LoginMetrics::FAILED);

        self::assertSame(1, $built);
        self::assertStringContainsString('sesame_logins_total{driver="saml",outcome="failed"} 2', $this->render($storage));
    }

    public function testAFactoryThatReturnsNoStoreIsAConfigError(): void
    {
        $metrics = new PrometheusLoginMetrics(static fn(): ?Adapter => null);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('HELMET_METRICS_STORAGE');

        $metrics->login('cas', LoginMetrics::SUCCESS);
    }

    public function testNoDefaultPhpInfoGaugeIsAdded(): void
    {
        $storage = new InMemory();
        (new PrometheusLoginMetrics($storage))->login('cas', LoginMetrics::SUCCESS);

        self::assertStringNotContainsString('php_info', $this->render($storage));
    }
}
