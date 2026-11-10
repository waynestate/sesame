<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Metrics;

use Waynestate\Sesame\Exception\InvalidConfig;

/**
 * The counter the Laravel adapter hands Sesame when metrics are turned on
 * but cannot work here (promphp is not installed).
 *
 * Every count throws InvalidConfig with the reason. Sesame catches it, so
 * the sign-in goes through, and logs it as `sesame.metrics_failed` with the
 * reason, so the setting that is on for nothing shows up in the log instead
 * of failing quietly.
 *
 * @internal
 */
final class UnavailableLoginMetrics implements LoginMetrics
{
    public function __construct(private readonly string $reason) {}

    public function login(string $driver, string $outcome): void
    {
        throw new InvalidConfig($this->reason);
    }
}
