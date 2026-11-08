<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Waynestate\Sesame\Clock\SystemClock;

/**
 * A ReplayGuard that keeps its records in process memory.
 *
 * For tests and for single-process development. The records vanish when the
 * process ends, and they are not shared between processes or workers, so a
 * request that lands on a different worker would not see them. An app that
 * sits behind the gateway in production must use a shared cache (the
 * Psr16ReplayGuard); this guard is the one that makes the tests fast and
 * deterministic.
 */
final class InMemoryReplayGuard implements ReplayGuard
{
    /**
     * The recorded jtis, as a hashed jti => expiry timestamp.
     *
     * @var array<string, int>
     */
    private array $records = [];

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly int $leewaySeconds = 30,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function seen(string $jti, DateTimeImmutable $expiresAt): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $expires = $expiresAt->getTimestamp() + $this->leewaySeconds;

        foreach ($this->records as $key => $recordedUntil) {
            if ($recordedUntil <= $now) {
                unset($this->records[$key]);
            }
        }

        if ($expires <= $now) {
            // The record would be expired the moment it is written: there is
            // nothing left to protect, so do not write it.
            return false;
        }

        $key = hash('sha256', $jti);

        if (isset($this->records[$key])) {
            return true;
        }

        $this->records[$key] = $expires;

        return false;
    }
}
