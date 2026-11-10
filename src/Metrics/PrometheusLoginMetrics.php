<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Metrics;

use Closure;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Adapter;
use Waynestate\Sesame\Exception\InvalidConfig;

/**
 * Counts sign-ins as `sesame_logins_total{driver, outcome}` in a promphp
 * store (ADR-0011: metrics speak Prometheus).
 *
 * SESAME writes the counter and never serves it. Give it the store HELMET's
 * request metrics use (APCu, Redis or SQLite) and the counter shows up on
 * HELMET's /metrics endpoint next to `helmet_http_request_duration_seconds`,
 * because HELMET renders every metric family in its store. SESAME needs no
 * endpoint, no token and no route of its own.
 *
 * The store may be given as a factory, built on the first sign-in and kept
 * from then on, so a request that signs nobody in never opens a SQLite file
 * or a Redis connection just in case.
 *
 * Both labels are bounded here, never by the caller: a driver name SESAME
 * does not ship and an outcome it does not know both become `other`, so a
 * bug can never mint a new time series. A lock that counts the turns of its
 * key, and nothing about whose hand it was.
 *
 * Needs promphp/prometheus_client_php (HELMET already requires it).
 */
final class PrometheusLoginMetrics implements LoginMetrics
{
    /**
     * The driver names that keep their own label value; anything else is
     * counted as `other`.
     *
     * @var list<string>
     */
    public const array DRIVERS = ['cas', 'fake', 'gateway', 'oauth2', 'oidc', 'saml'];

    /** @var Adapter|Closure(): (Adapter|null) */
    private Adapter|Closure $storage;

    private ?CollectorRegistry $registry = null;

    /**
     * @param Adapter|Closure(): (Adapter|null) $storage The promphp store, or
     *        a factory for it. A factory that returns null (HELMET's
     *        MetricsStorage::create() does when its store is off) is a
     *        config error, thrown on the first count.
     */
    public function __construct(Adapter|Closure $storage)
    {
        $this->storage = $storage;
    }

    public function login(string $driver, string $outcome): void
    {
        $this->registry()->getOrRegisterCounter(
            'sesame',
            'logins_total',
            'Sign-ins SESAME finished, by driver and outcome.',
            ['driver', 'outcome'],
        )->inc([self::driverLabel($driver), self::outcomeLabel($outcome)]);
    }

    /**
     * The driver label: a driver SESAME ships, or `other`.
     */
    public static function driverLabel(string $driver): string
    {
        return in_array($driver, self::DRIVERS, true) ? $driver : 'other';
    }

    /**
     * The outcome label: one of LoginMetrics::OUTCOMES, or `other`.
     */
    public static function outcomeLabel(string $outcome): string
    {
        return in_array($outcome, self::OUTCOMES, true) ? $outcome : 'other';
    }

    private function registry(): CollectorRegistry
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        $storage = $this->storage;

        if ($storage instanceof Closure) {
            $storage = $storage();

            if (! $storage instanceof Adapter) {
                throw new InvalidConfig('The login metrics store factory returned no store: turn on HELMET\'s request metrics (HELMET_METRICS_ENABLED and HELMET_METRICS_STORAGE) or give SESAME a Prometheus\Storage\Adapter.');
            }
        }

        // registerDefaultMetrics is false: promphp's php_info gauge belongs
        // to whoever serves the endpoint (HELMET reports PHP in helmet_info).
        return $this->registry = new CollectorRegistry($storage, false);
    }
}
