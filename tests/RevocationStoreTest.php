<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateInterval;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Waynestate\Sesame\Revocation\InMemoryRevocationStore;
use Waynestate\Sesame\Revocation\Psr16RevocationStore;
use Waynestate\Sesame\Tests\Support\ArrayCache;
use Waynestate\Sesame\Tests\Support\FakeClock;

/**
 * The two revocation stores: the cutoff rule (the later of two wins) and
 * the PSR-16 handling (a hashed key, a TTL, and a cache that throws).
 */
final class RevocationStoreTest extends TestCase
{
    public function testInMemoryKeepsTheLaterOfTwoCutoffs(): void
    {
        $store = new InMemoryRevocationStore();

        self::assertNull($store->revokedBefore('jdoe'));

        $store->revokeBefore('jdoe', 100);
        self::assertSame(100, $store->revokedBefore('jdoe'));

        $store->revokeBefore('jdoe', 90);
        self::assertSame(100, $store->revokedBefore('jdoe'), 'An earlier cutoff cannot un-revoke a later one.');

        $store->revokeBefore('jdoe', 200);
        self::assertSame(200, $store->revokedBefore('jdoe'));

        self::assertNull($store->revokedBefore('asmith'), 'One user\'s cutoff never leaks into another user\'s.');
    }

    public function testPsr16StoresAHashedKeyAndKeepsTheLaterOfTwoCutoffs(): void
    {
        $cache = new ArrayCache(new FakeClock());
        $store = new Psr16RevocationStore($cache, 3600);

        $store->revokeBefore('jdoe', 100);
        $store->revokeBefore('jdoe', 90);
        $store->revokeBefore('jdoe', 200);

        self::assertSame(200, $store->revokedBefore('jdoe'));
        self::assertSame(['sesame.revoked.' . hash('sha256', 'jdoe')], $cache->keys());

        foreach ($cache->keys() as $key) {
            self::assertStringNotContainsString('jdoe', $key, 'No plain username touches the cache.');
        }
    }

    public function testPsr16ForgetsACutoffAfterItsTtl(): void
    {
        $clock = new FakeClock();
        $cache = new ArrayCache($clock);
        $store = new Psr16RevocationStore($cache, 60);

        $store->revokeBefore('jdoe', 100);
        self::assertSame(100, $store->revokedBefore('jdoe'));

        $clock->advance(61);

        self::assertNull($store->revokedBefore('jdoe'), 'A forgotten cutoff dies with its TTL.');
    }

    public function testAPsr16CacheThatThrowsNeverBreaksReadOrWrite(): void
    {
        $cache = new class implements CacheInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                throw new RuntimeException('cache down: password=hunter2');
            }

            public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
            {
                throw new RuntimeException('cache down: password=hunter2');
            }

            public function delete(string $key): bool
            {
                return false;
            }

            public function clear(): bool
            {
                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            /**
             * @param iterable<mixed> $values
             */
            public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return false;
            }
        };
        $store = new Psr16RevocationStore($cache, 60);

        self::assertNull($store->revokedBefore('jdoe'), 'A failed read counts as no cutoff.');
        $store->revokeBefore('jdoe', 100);
        self::assertNull($store->revokedBefore('jdoe'), 'A failed write is dropped.');
    }
}
