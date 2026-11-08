<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Session;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Waynestate\Sesame\Clock\SystemClock;
use Waynestate\Sesame\Drivers\InMemoryReplayGuard;
use Waynestate\Sesame\Drivers\ReplayGuard;
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
 * `invalidate()` also remembers the ID it destroyed for a short window (60
 * seconds by default, the logout-reuse TTL). A request that presents that ID
 * within the window is talking to a session that no longer exists: the
 * anomaly is logged with the reason `logged_out_session_reused`, the session
 * is retired, and a clean one is started, so the caller sees an empty
 * session and the old ID stays dead. Past the window the ID is forgotten,
 * and a request carrying it gets an ordinary fresh session. The remembering
 * uses the same replay-guard pattern as the gateway driver: an in-memory
 * guard by default, or any other guard passed in, such as a PSR-16-backed
 * one, which is what makes the memory shared between workers in production.
 * Pass `logoutReuseTtlSeconds: 0` to turn the check off.
 */
final class NativeSession implements SessionPort
{
    private bool $checked = false;

    private readonly ReplayGuard $reuseGuard;

    public function __construct(
        private readonly int $graceSeconds = 60,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ClockInterface $clock = null,
        private readonly int $logoutReuseTtlSeconds = 60,
        ?ReplayGuard $reuseGuard = null,
    ) {
        if ($this->graceSeconds < 0) {
            throw new InvalidArgumentException('The grace period cannot be negative.');
        }

        if ($this->logoutReuseTtlSeconds < 0) {
            throw new InvalidArgumentException('The logout reuse window cannot be negative.');
        }

        // Leeway is zero on purpose: the record must live exactly as long as
        // the configured window, no more.
        $this->reuseGuard = $reuseGuard ?? new InMemoryReplayGuard(0, $this->clock ?? new SystemClock());
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
     * Clear the data, expire the cookie, and destroy the session.
     *
     * The data is cleared, the session cookie is expired on the client, and
     * the session is destroyed, so a stolen ID is dead after logout and the
     * client stops sending the cookie. The destroyed ID is also remembered
     * for the logout-reuse window, so a request that presents it soon after
     * is logged as an anomaly (see the class documentation).
     */
    public function invalidate(): void
    {
        $this->requireActive();

        // Captured before the destroy: once the session is gone, PHP no
        // longer vouches for the ID.
        $retiredId = session_id();

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

        session_destroy();

        if ($retiredId !== false) {
            $this->rememberRetiredId($retiredId);
        }
    }

    /**
     * Remember the ID that invalidate() just destroyed, for the reuse window.
     *
     * The record is best effort, like Sesame's logging: a broken guard must
     * not change what the caller sees after logout, so an error is dropped.
     * With a zero window there is nothing to remember, and seen() reports
     * whether the ID was already remembered (it should not be), which the
     * call ignores on purpose.
     */
    private function rememberRetiredId(string $id): void
    {
        if ($this->logoutReuseTtlSeconds === 0 || $id === '') {
            return;
        }

        try {
            $this->reuseGuard->seen(
                $id,
                $this->now()->add(new DateInterval('PT' . $this->logoutReuseTtlSeconds . 'S')),
            );
        } catch (\Throwable) {
            // Best effort: a broken guard must not break logout.
        }
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
        $this->rejectLoggedOutSessionReuse();
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
     * Retire a request that presents a recently invalidated session ID.
     *
     * invalidate() remembers the ID it destroyed for the logout-reuse
     * window. While the window lasts, a request carrying that ID is talking
     * to a session that no longer exists: the anomaly is logged, the
     * session is retired, and a clean one is started, so the caller sees an
     * empty session and the old ID stays dead. Past the window the ID is
     * forgotten, and the request gets an ordinary fresh session.
     */
    private function rejectLoggedOutSessionReuse(): void
    {
        if ($this->logoutReuseTtlSeconds === 0) {
            return;
        }

        $id = session_id();

        if ($id === false || $id === '' || ! $this->isRetiredId($id)) {
            return;
        }

        $this->logAnomaly('logged_out_session_reused');

        $_SESSION = [];
        session_destroy();
        session_id($this->createId());
        session_start();
    }

    /**
     * Whether the guard still remembers the given ID as recently invalidated.
     *
     * The lookup is best effort, like Sesame's logging: a broken cache must
     * not change what the caller sees, so an error reads as "not
     * remembered".
     */
    private function isRetiredId(string $id): bool
    {
        try {
            return $this->reuseGuard->has($id);
        } catch (\Throwable) {
            return false;
        }
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
