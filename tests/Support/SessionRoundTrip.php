<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Waynestate\Sesame\SessionPort;

/**
 * One browser's session across requests, kept by a real session store.
 *
 * next() ends the current request (the store writes the session through its
 * serializer) and starts the next one (the store reads it back), so whatever
 * SESAME put in the session comes back the way the store hands it back: an
 * object through PHP's serializer, an array through Laravel's JSON one.
 */
interface SessionRoundTrip
{
    /**
     * Write the current request's session, start the next request, and
     * return the port over the session as that request reads it.
     */
    public function next(): SessionPort;
}
