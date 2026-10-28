<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;
use Waynestate\Sesame\Clock\SystemClock;

/**
 * A ReplayGuard over a PSR-16 cache.
 *
 * The jti is stored under a prefixed, hashed key (the raw jti never touches
 * the cache), and the entry expires when the assertion's expiry plus the
 * guard's leeway has passed: after that, the assertion can no longer be
 * accepted, so its jti may be forgotten.
 *
 * PSR-16 has no atomic "add if absent" operation, so seen() checks has() and
 * then set(). That leaves a small window in which two requests carrying the
 * same jti could both pass has() before either set() lands. A replay is a
 * resend of a captured assertion, which is sequential, so the window does not
 * weaken the protection that matters; a burst of identical concurrent
 * requests could let a few through, which is still far safer than no replay
 * protection at all.
 *
 * The leeway should match the driver's clock-skew leeway: it is the window
 * after the nominal expiry in which the driver would still accept the
 * assertion, and the record must outlive that window.
 */
final class Psr16ReplayGuard implements ReplayGuard
{
    /**
     * The cache key prefix, so the guard's entries are easy to spot (and to
     * clear) in a shared cache.
     */
    private const KEY_PREFIX = 'sesame.jti.';

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $leewaySeconds = 30,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function seen(string $jti, DateTimeImmutable $expiresAt): bool
    {
        $ttl = $expiresAt->getTimestamp() + $this->leewaySeconds - $this->clock->now()->getTimestamp();

        if ($ttl <= 0) {
            // The assertion is already past its expiry (plus leeway): the
            // driver's own time checks will have refused it, and there is
            // nothing left to protect.
            return false;
        }

        $key = self::KEY_PREFIX.hash('sha256', $jti);

        if ($this->cache->has($key)) {
            return true;
        }

        $this->cache->set($key, true, new DateInterval('PT'.$ttl.'S'));

        return false;
    }
}
