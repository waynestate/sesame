<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Drivers\InMemoryReplayGuard;
use Waynestate\Sesame\Tests\Support\FakeClock;

/**
 * The in-memory replay guard: the same rules as the PSR-16 guard, but the
 * records live in process memory (for tests and single-process development).
 */
final class InMemoryReplayGuardTest extends TestCase
{
    private FakeClock $clock;

    private InMemoryReplayGuard $guard;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->guard = new InMemoryReplayGuard(30, $this->clock);
    }

    public function testFirstSightIsNotSeen(): void
    {
        $this->assertFalse($this->guard->seen('jti-1', $this->at(240)));
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
    }

    public function testAnAlreadyExpiredAssertionIsNotRecorded(): void
    {
        // exp is 60 seconds in the past; with 30 seconds of leeway the record
        // would be expired the moment it is written, so nothing is recorded.
        $this->assertFalse($this->guard->seen('jti-1', $this->at(-60)));

        // A fresh, still-valid assertion with the same jti is accepted, which
        // proves the expired one was not remembered.
        $this->assertFalse($this->guard->seen('jti-1', $this->at(240)));
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

    private function at(int $offsetSeconds): DateTimeImmutable
    {
        return $this->clock->now()->modify('+' . $offsetSeconds . ' seconds');
    }
}
