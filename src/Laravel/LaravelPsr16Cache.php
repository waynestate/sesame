<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Laravel;

use DateInterval;
use Illuminate\Cache\Repository;
use Psr\SimpleCache\CacheInterface;
use Waynestate\Sesame\Drivers\AtomicAddCache;

/**
 * A PSR-16 cache backed by the app's Laravel cache.
 *
 * Laravel's cache repository already speaks PSR-16 (it has get, set, delete,
 * clear, has and the *Multiple variants), but it does not declare the PSR-16
 * interface, so it cannot be passed where a CacheInterface is expected. This
 * adapter is the thin bridge that lets the core's PSR-16 collaborators (the
 * Psr16ReplayGuard) use the app's cache: every call is passed straight
 * through to the repository.
 *
 * It also implements AtomicAddCache: the repository's add() stores a value
 * only when the key is absent, atomically in the store (Redis SET NX,
 * Memcached add), which is what the replay guard needs to close its
 * check-then-set window.
 */
final class LaravelPsr16Cache implements CacheInterface, AtomicAddCache
{
    public function __construct(
        private readonly Repository $repository,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->repository->get($key, $default);
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        return $this->repository->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->repository->delete($key);
    }

    public function clear(): bool
    {
        return $this->repository->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->repository->getMultiple($keys, $default);
    }

    /**
     * @param iterable<mixed> $values
     */
    public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
    {
        return $this->repository->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->repository->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->repository->has($key);
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->repository->add($key, $value, $ttl);
    }
}
