<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Session;

use LogicException;
use Waynestate\Sesame\SessionPort;

/**
 * The app's native PHP session, seen through the port.
 *
 * This class never starts a session and never sets cookie flags. Starting the
 * session is the app's job (`session_start()`), and hardening the session
 * cookie is HELMET's job. It only reads and writes `$_SESSION` on a session
 * that is already active.
 */
final class NativeSession implements SessionPort
{
    public function regenerate(): void
    {
        $this->requireActive();

        session_regenerate_id(true);
    }

    public function get(string $key): mixed
    {
        $this->requireActive();

        return $_SESSION[$key] ?? null;
    }

    public function put(string $key, mixed $value): void
    {
        $this->requireActive();

        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->requireActive();

        unset($_SESSION[$key]);
    }

    /**
     * Clear the data and retire the old session ID.
     *
     * The ID is regenerated with the old one deleted, so a stolen ID is dead
     * after logout.
     */
    public function invalidate(): void
    {
        $this->requireActive();

        $_SESSION = [];
        session_regenerate_id(true);
    }

    private function requireActive(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new LogicException('No session is active. Start one before using NativeSession.');
        }
    }
}
