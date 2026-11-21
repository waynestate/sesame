<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Psr\Clock\ClockInterface;
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\SessionPort;

/**
 * PHP's own session across requests: the session is written and closed,
 * $_SESSION is emptied, and the same ID is started again, so every value
 * is read back from the files handler through PHP's serializer.
 *
 * It needs a process of its own (session_start() sends a cookie), so the
 * tests run it in a child PHP process; the caller sets the save path, the
 * name and the cookie flags first.
 */
final class NativeRoundTrip implements SessionRoundTrip
{
    public function __construct(
        private readonly ?ClockInterface $clock = null,
    ) {}

    public function next(): SessionPort
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $id = session_id();
            session_write_close();
            $_SESSION = [];

            if (is_string($id) && $id !== '') {
                session_id($id);
            }
        }

        session_start();

        return new NativeSession(60, null, $this->clock);
    }
}
