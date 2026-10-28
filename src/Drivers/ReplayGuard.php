<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

use DateTimeImmutable;

/**
 * Remembers which assertion IDs (jti) have already been used, so a signed
 * assertion cannot be replayed.
 *
 * The gateway signs every request with a fresh assertion, but nothing stops
 * a client from capturing one and sending it again. The guard is the memory
 * that says "we have seen this one": seen() returns true when the jti has
 * already been used, and records it (for the assertion's lifetime plus a
 * little leeway) when it has not.
 *
 * A guard must be safe to call from many requests at once: the first call
 * with a given jti wins, and every later call with the same jti reports it
 * as seen.
 */
interface ReplayGuard
{
    /**
     * Whether the given jti has already been used.
     *
     * On first sight the jti is recorded, with a lifetime that covers the
     * assertion's expiry plus the guard's leeway, and false is returned. A
     * jti that is already recorded is left as it is, and true is returned.
     */
    public function seen(string $jti, DateTimeImmutable $expiresAt): bool;
}
