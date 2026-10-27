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
 * cookie is HELMET's job. It reads and writes `$_SESSION` on a session that
 * is already active. `invalidate()` is the one operation that retires the
 * session: it clears the data, expires the cookie on the client, and destroys
 * the session, so a stolen ID is dead after logout.
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
     * Clear the data, expire the cookie, and destroy the session.
     *
     * The data is cleared, the session cookie is expired on the client, and
     * the session is destroyed, so a stolen ID is dead after logout and the
     * client stops sending the cookie.
     */
    public function invalidate(): void
    {
        $this->requireActive();

        $_SESSION = [];

        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ],
        );

        session_destroy();
    }

    private function requireActive(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new LogicException('No session is active. Start one before using NativeSession.');
        }
    }
}
