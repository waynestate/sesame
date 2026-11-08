<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Session;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Waynestate\Sesame\Clock\SystemClock;
use Waynestate\Sesame\SessionPort;

/**
 * The app's native PHP session, seen through the port.
 *
 * This class never starts a session and never sets cookie flags. Starting the
 * session is the app's job (`session_start()`), and hardening the session
 * cookie is HELMET's job. It reads and writes `$_SESSION` on a session that
 * is already active. `invalidate()` is the one operation that ends the
 * session: it clears the data, expires the cookie on the client, and retires
 * the session, so a stolen ID is dead after logout.
 *
 * `regenerate()` does not delete the old session at once. It marks the old
 * session with a "destroyed at" time and the new ID, keeps it for a grace
 * period (60 seconds by default), and moves on to the new ID with the same
 * data, so a user whose browser is still sending the old ID after a slow
 * network does not lose the login. A use of the old ID after the grace is
 * treated as a possible hijack: the session is retired, a clean one is
 * started, and `sesame.session_anomaly` is logged as a warning when a logger
 * is set. Pass `graceSeconds: 0` to restore the old behavior, where the old
 * session is deleted at once.
 *
 * `invalidate()` also leaves a short-lived trace of the logout in the
 * session store itself. With the default window (60 seconds, the
 * logout-reuse TTL) the store keeps only a "logged out at" marker under the
 * same ID, so no extra cache is needed and every worker sees the same
 * marker. A later request that presents that ID reads the marker back:
 * within the window the reuse is logged with the reason
 * `logged_out_session_reused`; past the window (or with a marker that is
 * not an int) it is not. Either way the marked session is over: it is
 * cleared, destroyed, and a clean one is started, so the caller sees an
 * empty session and the old ID stays dead. Pass
 * `logoutReuseTtlSeconds: 0` to turn the check off: the session is
 * destroyed outright, the old behavior.
 */
final class NativeSession implements SessionPort
{
    private bool $checked = false;

    public function __construct(
        private readonly int $graceSeconds = 60,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ClockInterface $clock = null,
        private readonly int $logoutReuseTtlSeconds = 60,
    ) {
        if ($this->graceSeconds < 0) {
            throw new InvalidArgumentException('The grace period cannot be negative.');
        }

        if ($this->logoutReuseTtlSeconds < 0) {
            throw new InvalidArgumentException('The logout reuse window cannot be negative.');
        }
    }

    public function regenerate(): void
    {
        $this->requireActive();

        if ($this->graceSeconds === 0) {
            session_regenerate_id(true);

            return;
        }

        $newId = $this->createId();

        $_SESSION['sesame.destroyed_at'] = (int) $this->now()->format('U');
        $_SESSION['sesame.new_session_id'] = $newId;

        // The data is copied explicitly: starting the new session replaces
        // $_SESSION with the new session's (empty) contents.
        $data = $_SESSION;

        session_write_close();
        session_id($newId);
        session_start();
        $_SESSION = $data;
        unset($_SESSION['sesame.destroyed_at'], $_SESSION['sesame.new_session_id']);
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
     * Clear the data, expire the cookie, and retire the session.
     *
     * The data is cleared and the session cookie is expired on the client,
     * so a stolen ID is dead after logout and the client stops sending the
     * cookie. With the default window the store keeps only a "logged out
     * at" marker under the same ID, so a later request that presents it
     * can be logged as an anomaly (see the class documentation). With a
     * zero window the session is destroyed outright, the old behavior.
     */
    public function invalidate(): void
    {
        $this->requireActive();

        $_SESSION = [];

        $params = session_get_cookie_params();
        $name = session_name();

        if ($name === false) {
            throw new LogicException('The active session has no name.');
        }

        setcookie(
            $name,
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

        if ($this->logoutReuseTtlSeconds === 0) {
            session_destroy();

            return;
        }

        // The tombstone goes into the store itself, under the same ID: the
        // next request presenting this ID reads it back, no shared cache
        // needed. Writing closes the session, which is what makes the
        // marker durable.
        $_SESSION = ['sesame.logged_out_at' => (int) $this->now()->format('U')];
        session_write_close();
    }

    private function requireActive(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new LogicException('No session is active. Start one before using NativeSession.');
        }

        if ($this->checked) {
            return;
        }

        $this->checked = true;

        $this->rejectExpiredOldSession();
        $this->rejectLoggedOutSession();
    }

    /**
     * Retire a reused old session whose grace period has passed.
     *
     * A session marked with a "destroyed at" time is an old ID that
     * regenerate() kept for a short grace. While the grace lasts, the user
     * may still be on the old ID after a slow network, so the session keeps
     * working. After the grace (or with a marker that is not an int), the
     * reuse is treated as a possible hijack: the anomaly is logged, the
     * session is destroyed, and a clean one is started, so the caller sees
     * an empty session and the old ID is dead.
     */
    private function rejectExpiredOldSession(): void
    {
        $destroyedAt = $_SESSION['sesame.destroyed_at'] ?? null;

        if ($destroyedAt === null) {
            return;
        }

        $withinGrace = is_int($destroyedAt)
            && ((int) $this->now()->format('U') - $destroyedAt) <= $this->graceSeconds;

        if ($withinGrace) {
            return;
        }

        $this->logAnomaly('destroyed_session_reused');

        $_SESSION = [];
        session_destroy();
        session_id($this->createId());
        session_start();
    }

    /**
     * Retire a session that still carries the logout tombstone.
     *
     * invalidate() leaves a "logged out at" marker in the store, under the
     * same ID, for the logout-reuse window. A request presenting that ID
     * reads the marker back: within the window the reuse is logged as an
     * anomaly, past it (or with a marker that is not an int) it is not.
     * Either way the marker means the session is over: it is cleared,
     * destroyed, and a clean one is started, so the caller sees an empty
     * session and the old ID is dead.
     */
    private function rejectLoggedOutSession(): void
    {
        $loggedOutAt = $_SESSION['sesame.logged_out_at'] ?? null;

        if ($loggedOutAt === null) {
            return;
        }

        $withinWindow = is_int($loggedOutAt)
            && ((int) $this->now()->format('U') - $loggedOutAt) <= $this->logoutReuseTtlSeconds;

        if ($withinWindow) {
            $this->logAnomaly('logged_out_session_reused');
        }

        $_SESSION = [];
        session_destroy();
        session_id($this->createId());
        session_start();
    }

    /**
     * Write the anomaly warning to the optional logger.
     *
     * The context carries the event, the reason and the time only: no
     * session ID, no username, nothing from `$_SESSION`, so a tampered
     * session cannot inject text into the log. The write is best effort,
     * like Sesame's logging: a broken logger must not change what the
     * caller sees.
     */
    private function logAnomaly(string $reason): void
    {
        if ($this->logger === null) {
            return;
        }

        try {
            $this->logger->log(
                'warning',
                'sesame.session_anomaly',
                [
                    'event' => 'session_anomaly',
                    'reason' => $reason,
                    'at' => $this->now()->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
                ],
            );
        } catch (\Throwable) {
            // Best effort: a broken logger must not break the request.
        }
    }

    /**
     * A fresh session ID from PHP, or an error when PHP cannot make one.
     */
    private function createId(): string
    {
        $id = session_create_id();

        if ($id === false) {
            throw new LogicException('PHP could not create a new session ID.');
        }

        return $id;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock ?? new SystemClock())->now();
    }
}
