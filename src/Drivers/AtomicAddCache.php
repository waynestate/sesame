<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

/**
 * A cache that can store a value only when the key is absent, atomically.
 *
 * PSR-16 has no such operation: a caller that checks has() and then set()
 * leaves a window in which two requests can both see the key as absent. A
 * cache that implements this interface closes that window, because the
 * decision and the write happen together in the backing store.
 */
interface AtomicAddCache
{
    /**
     * Store $value under $key only when the key is absent.
     *
     * Must be atomic in the backing store (Redis SET NX, Memcached add,
     * Laravel Cache::add). Returns true when the key was absent and is now
     * stored, false when it already existed.
     */
    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool;
}
