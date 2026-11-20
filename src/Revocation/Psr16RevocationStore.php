<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Revocation;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use Waynestate\Sesame\RevocationStore;

/**
 * The PSR-16 revocation store.
 *
 * It keeps each cutoff under `sesame.revoked.` plus the SHA-256 of the
 * username, so no plain username touches the cache, and gives every cutoff
 * the TTL it was built with, so a forgotten cutoff dies on its own. A cache
 * that throws never breaks sign-in or sign-out: a failed read counts as no
 * cutoff, and a failed write is dropped.
 */
final class Psr16RevocationStore implements RevocationStore
{
    private const KEY_PREFIX = 'sesame.revoked.';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $ttlSeconds,
    ) {}

    public function revokeBefore(string $username, int $at): void
    {
        try {
            $current = $this->cache->get(self::KEY_PREFIX . hash('sha256', $username));

            if (is_int($current) && $current >= $at) {
                return;
            }

            $this->cache->set(self::KEY_PREFIX . hash('sha256', $username), $at, new DateInterval('PT' . $this->ttlSeconds . 'S'));
        } catch (Throwable) {
            // The cache is best effort: a broken one must not break the
            // sign-out, so the cutoff is simply not recorded. The facade
            // logs the failure.
        }
    }

    public function revokedBefore(string $username): ?int
    {
        try {
            $value = $this->cache->get(self::KEY_PREFIX . hash('sha256', $username));
        } catch (Throwable) {
            // A failed read counts as no cutoff, so a broken cache can
            // never sign the user in or out.
            return null;
        }

        return is_int($value) ? $value : null;
    }
}
