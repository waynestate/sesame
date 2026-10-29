<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Oidc;

use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 cache that lives in process memory.
 *
 * The oidc driver's fallback when the app passes no cache: the discovery
 * document and the key set are then kept for the life of the process (one
 * request under PHP-FPM), so each sign-in costs two extra HTTP calls. Pass
 * a shared PSR-16 cache in production.
 *
 * @internal
 */
final class ProcessCache implements CacheInterface
{
    /** @var array<string, array{mixed, int|null}> */
    private array $items = [];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key) ? $this->items[$key][0] : $default;
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        $now = $this->clock->now();

        $expires = match (true) {
            $ttl === null => null,
            $ttl instanceof DateInterval => (new DateTimeImmutable('@' . $now->getTimestamp()))->add($ttl)->getTimestamp(),
            default => $now->getTimestamp() + $ttl,
        };

        $this->items[$key] = [$value, $expires];

        return true;
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
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<mixed> $values
     */
    public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
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
        if (! isset($this->items[$key])) {
            return false;
        }

        $expires = $this->items[$key][1];

        return $expires === null || $expires > $this->clock->now()->getTimestamp();
    }
}
