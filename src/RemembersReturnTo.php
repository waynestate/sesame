<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

/**
 * A driver that remembers where the user was going when the sign-in began.
 *
 * Optional: a driver that implements it hands back, after a completed
 * sign-in, the page the user asked for. The value has already passed the
 * ReturnTo rule (an absolute https URL on the service host), so it can never
 * be an open redirect.
 */
interface RemembersReturnTo
{
    /**
     * The checked return-to of the sign-in that just completed, or null when
     * there was none.
     */
    public function returnTo(): ?string;
}
