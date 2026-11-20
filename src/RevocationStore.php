<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

/**
 * A shared sign-out cutoff (Booster ADR-0024).
 *
 * Two apps on one domain each keep their own SESAME session, so no session
 * key can be shared between them; what can be shared is a time. When the
 * user logs out of one app, that app records a cutoff for the username here,
 * and every app that shares the store signs out any session signed in at or
 * before the cutoff on its next request.
 */
interface RevocationStore
{
    /** Every sign-in of $username at or before $at (Unix seconds) counts as signed out from now on. */
    public function revokeBefore(string $username, int $at): void;

    /** The latest cutoff for $username, or null when there is none. */
    public function revokedBefore(string $username): ?int;
}
