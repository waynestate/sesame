<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

/**
 * The app's session, seen through a port.
 *
 * The app always owns its session, not the library. The core talks to it only
 * through this port, so the raw-PHP path and the Laravel path agree without
 * either one knowing about the other.
 */
interface SessionPort
{
    /**
     * Regenerate the session ID, discarding the old one.
     */
    public function regenerate(): void;

    /**
     * Read one key, or null when it is not set.
     */
    public function get(string $key): mixed;

    /**
     * Store one key.
     */
    public function put(string $key, mixed $value): void;

    /**
     * Remove one key.
     */
    public function forget(string $key): void;

    /**
     * Clear everything the session holds.
     */
    public function invalidate(): void;
}
