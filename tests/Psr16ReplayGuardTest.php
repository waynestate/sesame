<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Drivers\Psr16ReplayGuard;
use Waynestate\Sesame\Tests\Support\ArrayCache;
use Waynestate\Sesame\Tests\Support\FakeClock;

/**
 * The PSR-16 replay guard: a jti is remembered in a cache until the
 * assertion's expiry plus the guard's leeway has passed.
 */
final class Psr16ReplayGuardTest extends TestCase
{
    private FakeClock $clock;

    private ArrayCache $cache;

    private Psr16ReplayGuard $guard;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->cache = new ArrayCache($this->clock);
        $this->guard = new Psr16ReplayGuard($this->cache, 30, $this->clock);
    }

    public function testFirstSightIsRecordedAndNotSeen(): void
    {
        $this->assertFalse($this->guard->seen('jti-1', $this->at(240)));

        $this->assertCount(1, $this->cache->keys());
    }

    public function testSecondSightIsSeen(): void
    {
        $this->assertFalse($this->guard->seen('jti-1', $this->at(240)));

        $this->assertTrue($this->guard->seen('jti-1', $this->at(240)));
    }

    public function testDifferentJtisAreIndependent(): void
    {
        $this->assertFalse($this->guard->seen('jti-1', $this->at(240)));
        $this->assertFalse($this->guard->seen('jti-2', $this->at(240)));

        $this->assertCount(2, $this->cache->keys());
    }

    public function testAnAlreadyExpiredAssertionIsNotRecorded(): void
    {
        // exp is 60 seconds in the past; with 30 seconds of leeway the ttl is
        // negative, so the guard records nothing.
        $this->assertFalse($this->guard->seen('jti-1', $this->at(-60)));

        $this->assertSame([], $this->cache->keys());
    }

    public function testARecordExpiresAfterTheLeeway(): void
    {
        $this->assertFalse($this->guard->seen('jti-1', $this->at(240)));

        // The record lives until exp plus leeway: 240 + 30 = 270 seconds.
        // Move past it, and a fresh, still-valid assertion with the same jti
        // is accepted again.
        $this->clock->advance(271);

        $this->assertFalse($this->guard->seen('jti-1', $this->at(240)));
    }

    public function testTheCacheKeyIsPrefixedAndHashed(): void
    {
        $this->guard->seen('jti-1', $this->at(240));

        $this->assertSame(['sesame.jti.' . hash('sha256', 'jti-1')], $this->cache->keys());
    }

    private function at(int $offsetSeconds): DateTimeImmutable
    {
        return $this->clock->now()->modify('+' . $offsetSeconds . ' seconds');
    }
}
