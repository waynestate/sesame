<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

/**
 * A resolver that can look the signed-in user up again.
 *
 * The core stores the user the resolver returned at sign-in and would keep
 * handing that copy out until the session times out. A resolver that also
 * implements this interface tells the core where to read the user again, so
 * a change made after the sign-in (a removed role, a deleted account) shows
 * up from the next request on.
 */
interface RefreshesUser
{
    /**
     * Look the stored user up again.
     *
     * @param  mixed  $user  The user the session holds.
     * @return mixed The current user, or null when it no longer exists.
     */
    public function refreshUser(mixed $user): mixed;
}
