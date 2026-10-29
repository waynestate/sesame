<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;
use Waynestate\Sesame\Clock\SystemClock;

/**
 * A PSR-16 cache that lives in an array, for the replay-guard tests.
 *
 * It honours TTLs (int seconds or a DateInterval) against an injectable
 * clock, so a test can move time and watch a record expire. The extra
 * keys() accessor lets a test assert what the cache actually holds.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expiresAt: int|null}> */
    private array $items = [];

    private ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->isPresent($key)) {
            return $default;
        }

        return $this->items[$key]['value'];
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        $this->items[$key] = [
            'value' => $value,
            'expiresAt' => $this->expiry($ttl),
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        if (! array_key_exists($key, $this->items)) {
            return false;
        }

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
        return $this->isPresent($key);
    }

    /**
     * The keys that are present and not yet expired, for test assertions.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach (array_keys($this->items) as $key) {
            if ($this->isPresent($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function isPresent(string $key): bool
    {
        if (! array_key_exists($key, $this->items)) {
            return false;
        }

        $expiresAt = $this->items[$key]['expiresAt'];

        return $expiresAt === null || $expiresAt > $this->clock->now()->getTimestamp();
    }

    private function expiry(int|DateInterval|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        $now = $this->clock->now();

        if ($ttl instanceof DateInterval) {
            return (new DateTimeImmutable('@' . $now->getTimestamp()))
                ->add($ttl)
                ->getTimestamp();
        }

        return $now->getTimestamp() + $ttl;
    }
}
