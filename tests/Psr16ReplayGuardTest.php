<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateInterval;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Waynestate\Sesame\Drivers\AtomicAddCache;
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
        self::assertFalse($this->guard->seen('jti-1', $this->at(240)));

        self::assertCount(1, $this->cache->keys());
    }

    public function testSecondSightIsSeen(): void
    {
        self::assertFalse($this->guard->seen('jti-1', $this->at(240)));

        self::assertTrue($this->guard->seen('jti-1', $this->at(240)));
    }

    public function testDifferentJtisAreIndependent(): void
    {
        self::assertFalse($this->guard->seen('jti-1', $this->at(240)));
        self::assertFalse($this->guard->seen('jti-2', $this->at(240)));

        self::assertCount(2, $this->cache->keys());
    }

    public function testAnAlreadyExpiredAssertionIsNotRecorded(): void
    {
        // exp is 60 seconds in the past; with 30 seconds of leeway the ttl is
        // negative, so the guard records nothing.
        self::assertFalse($this->guard->seen('jti-1', $this->at(-60)));

        self::assertSame([], $this->cache->keys());
    }

    public function testARecordExpiresAfterTheLeeway(): void
    {
        self::assertFalse($this->guard->seen('jti-1', $this->at(240)));

        // The record lives until exp plus leeway: 240 + 30 = 270 seconds.
        // Move past it, and a fresh, still-valid assertion with the same jti
        // is accepted again.
        $this->clock->advance(271);

        self::assertFalse($this->guard->seen('jti-1', $this->at(240)));
    }

    public function testTheCacheKeyIsPrefixedAndHashed(): void
    {
        $this->guard->seen('jti-1', $this->at(240));

        self::assertSame(['sesame.jti.' . hash('sha256', 'jti-1')], $this->cache->keys());
    }

    public function testUsesAtomicAddWhenCacheOffersIt(): void
    {
        // A cache that offers an atomic add: the guard must record through
        // add() alone, and has()/set() throw if the guard ever reaches for
        // them.
        $cache = new class implements CacheInterface, AtomicAddCache {
            /** @var array<string, mixed> */
            public array $items = [];

            public int $addCalls = 0;

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->items[$key] ?? $default;
            }

            public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
            {
                throw new LogicException('set() must not be used when the cache offers add().');
            }

            public function delete(string $key): bool
            {
                unset($this->items[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->items = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $result = [];

                foreach ($keys as $key) {
                    $result[$key] = $this->get($key, $default);
                }

                return $result;
            }

            /**
             * @param iterable<mixed> $values
             */
            public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set($key, $value, $ttl);
                }

                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete($key);
                }

                return true;
            }

            public function has(string $key): bool
            {
                throw new LogicException('has() must not be used when the cache offers add().');
            }

            public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
            {
                $this->addCalls++;

                if (array_key_exists($key, $this->items)) {
                    return false;
                }

                $this->items[$key] = $value;

                return true;
            }
        };

        $guard = new Psr16ReplayGuard($cache, 30, $this->clock);

        self::assertFalse($guard->seen('j', $this->at(240)));
        self::assertTrue($guard->seen('j', $this->at(240)));

        self::assertSame(2, $cache->addCalls);
    }

    private function at(int $offsetSeconds): DateTimeImmutable
    {
        return $this->clock->now()->modify('+' . $offsetSeconds . ' seconds');
    }
}
