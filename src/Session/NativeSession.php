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
 * This class never starts the request's first session and never sets cookie
 * flags. Starting the session is the app's job (`session_start()`), and
 * hardening the session cookie is HELMET's job. It reads and writes
 * `$_SESSION` on a session that is already active, and it only ever starts
 * a session of its own under a new ID it made itself, to replace one it
 * just retired. `invalidate()` is the one operation that ends a session: it
 * clears the data, retires the session, so a stolen ID is dead after logout,
 * and starts a fresh empty one under a new ID, so the rest of the request
 * (a redirect to the sign-in after a timeout) still has a session to write.
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
 *
 * Safe session settings land without a code change. `session.use_strict_mode`
 * off lets PHP accept any ID a client sends, and `session.use_only_cookies`
 * off lets it take the ID from the URL. Whenever no session is active (at
 * construction, and before each session_start() this class makes itself),
 * both are turned on with ini_set(). When the app already started its
 * session with either one off, the first use in the request raises one
 * E_USER_WARNING naming the setting and the one-line fix, and the session
 * keeps working. `checkSessionSettings: true` turns that warning into a
 * `LogicException` on every call; `false` turns the settings and the
 * warning off. HELMET's `helmet_boot()` and Squire's php.ini set both.
 * The session cookie's HttpOnly, Secure (on https) and SameSite (Lax or
 * Strict) flags are checked the same way, warned once per request, never
 * set.
 */
final class NativeSession implements SessionPort
{
    /** The settings that stop a client choosing its own session ID. */
    private const SAFE_SETTINGS = ['session.use_strict_mode', 'session.use_only_cookies'];

    /** Whether this request has had its warning: one is plenty. */
    private static bool $warned = false;

    /** Whether this request has had its cookie-flag warning: one is plenty. */
    private static bool $warnedCookie = false;

    private bool $checked = false;

    /**
     * @param bool|null $checkSessionSettings null (the default) turns the
     *        safe settings on when it can and warns once when the app's
     *        session was started without them, or when its cookie lacks
     *        HttpOnly, Secure (on https) or a Lax or Strict SameSite (the
     *        cookie flags are only checked, never set); true also turns the
     *        safe settings on and throws instead of warning; false does
     *        neither.
     */
    public function __construct(
        private readonly int $graceSeconds = 60,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ClockInterface $clock = null,
        private readonly int $logoutReuseTtlSeconds = 60,
        private readonly ?bool $checkSessionSettings = null,
    ) {
        if ($this->graceSeconds < 0) {
            throw new InvalidArgumentException('The grace period cannot be negative.');
        }

        if ($this->logoutReuseTtlSeconds < 0) {
            throw new InvalidArgumentException('The logout reuse window cannot be negative.');
        }

        $this->hardenBeforeStart();
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
        unset($data['sesame.destroyed_at'], $data['sesame.new_session_id']);

        session_write_close();
        $this->startUnderNewId($newId, $data);
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
     * Clear the data, retire the session, and carry on in a fresh one.
     *
     * The data is cleared and the old session cookie is expired, so a
     * stolen ID is dead after logout. With the default window the store
     * keeps only a "logged out at" marker under the old ID, so a later
     * request that presents it can be logged as an anomaly (see the class
     * documentation). With a zero window the old session is destroyed
     * outright. Either way a new, empty session is then active under a new
     * ID (from 0.7.1), so the rest of the request can keep using the port:
     * a timed-out session followed by a redirect to the sign-in, which
     * writes the pending sign-in, no longer throws.
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
        } else {
            // The tombstone goes into the store itself, under the same ID:
            // the next request presenting this ID reads it back, no shared
            // cache needed. Writing closes the session, which is what makes
            // the marker durable.
            $_SESSION = ['sesame.logged_out_at' => (int) $this->now()->format('U')];
            session_write_close();
        }

        $this->startFreshSession();
    }

    /**
     * Carry the request on in a new, empty session after invalidate().
     *
     * A sign-out is often not the end of the request: a session that timed
     * out is signed out by user(), and the same request then sends the
     * visitor to the identity provider, which stores the pending sign-in in
     * the session (the cas driver's login binding), or the app keeps writing
     * its own keys. With the old session closed, every such write threw
     * "No session is active", a blank 500 instead of the sign-in page. So a
     * new session starts here, under an ID made by session_create_id(),
     * never one from the request, with the safe settings turned on first
     * and strict mode kept as the app set it (startUnderNewId()). The new
     * session holds nothing, and its cookie replaces the expired one, so the
     * dead ID is never sent again. When the response has already started
     * (headers sent), no cookie can go out, so the session stays closed, as
     * it did before 0.7.1.
     */
    private function startFreshSession(): void
    {
        if (headers_sent()) {
            return;
        }

        $this->startUnderNewId($this->createId(), []);
    }

    private function requireActive(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new LogicException('No session is active. Start one before using NativeSession.');
        }

        if ($this->checked) {
            return;
        }

        // Before the flag is set, so with checkSessionSettings: true a
        // refused session stays refused on every call, not only the first.
        $this->checkSafeSettings();
        $this->checkCookieFlags();

        $this->checked = true;

        $this->rejectExpiredOldSession();
        $this->rejectLoggedOutSession();
    }

    /**
     * Turn the safe settings on while no session is active.
     *
     * Session settings can only change while no session is active and no
     * header has gone out, so this runs at construction (an app that builds
     * NativeSession before session_start() gets them for free) and before
     * every session_start() this class makes itself. A setting that is
     * already on is left alone. Off with checkSessionSettings: false.
     */
    private function hardenBeforeStart(): void
    {
        if ($this->checkSessionSettings === false
            || session_status() !== PHP_SESSION_NONE
            || headers_sent()
        ) {
            return;
        }

        foreach (self::SAFE_SETTINGS as $setting) {
            if (! self::isOn((string) ini_get($setting))) {
                ini_set($setting, '1');
            }
        }
    }

    /**
     * Speak up about an active session started without the safe settings.
     *
     * Without session.use_strict_mode PHP adopts any ID a client sends, so
     * an attacker can plant a known ID before the sign-in; with
     * session.use_only_cookies off PHP also takes the ID from the URL, where
     * links, logs and Referer headers leak it. SESAME regenerates the ID at
     * login either way (S1), so by default the session keeps working and
     * the app hears about it once per request, with the fix. With
     * checkSessionSettings: true it is refused on every call instead. The
     * values are read from the active session's settings, so the check sees
     * what session_start() used.
     */
    private function checkSafeSettings(): void
    {
        if ($this->checkSessionSettings === false) {
            return;
        }

        $off = array_values(array_filter(
            self::SAFE_SETTINGS,
            static fn(string $setting): bool => ! self::isOn((string) ini_get($setting)),
        ));

        if ($off === []) {
            return;
        }

        $fixes = implode(' ', array_map(static fn(string $setting): string => "ini_set('" . $setting . "', '1');", $off));
        $message = sprintf(
            'SESAME: the session was started with %s off, so PHP lets a client choose its session ID. Add %s before session_start() (or call helmet_boot()).',
            implode(' and ', $off),
            $fixes,
        );

        if ($this->checkSessionSettings === true) {
            throw new LogicException($message);
        }

        if (self::$warned) {
            return;
        }

        self::$warned = true;
        trigger_error($message, E_USER_WARNING);
    }

    /**
     * Speak up about an active session whose cookie lacks its flags.
     *
     * A session cookie without HttpOnly is readable from JavaScript on the
     * page, one without Secure can travel over plain http, and one without
     * a Lax or Strict SameSite can be made to ride along on other sites'
     * requests. SESAME never sets the flags (S2: HELMET does), so it only
     * tells the app, in the style of checkSafeSettings(): once per request
     * by default, a LogicException on every call with
     * checkSessionSettings: true, silent with false. The values are read
     * from the active session's cookie params, so the check sees what
     * session_start() used. Secure is named only on an https request,
     * where a missing flag actually matters.
     */
    private function checkCookieFlags(): void
    {
        if ($this->checkSessionSettings === false) {
            return;
        }

        $params = session_get_cookie_params();

        $https = (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        $sameSite = strtolower($params['samesite']);
        $missingHttpOnly = ! $params['httponly'];
        $missingSecure = ! $params['secure'] && $https;
        $missingSameSite = $sameSite !== 'lax' && $sameSite !== 'strict';

        $names = array_values(array_filter(
            [
                $missingHttpOnly ? 'HttpOnly' : null,
                $missingSecure ? 'Secure' : null,
                $missingSameSite ? 'SameSite' : null,
            ],
            static fn(?string $name): bool => $name !== null,
        ));

        if ($names === []) {
            return;
        }

        $reason = match (true) {
            $missingHttpOnly && ! $missingSecure && ! $missingSameSite => 'scripts on the page can read it',
            $missingSecure && ! $missingHttpOnly && ! $missingSameSite => 'it can travel over plain http',
            $missingSameSite && ! $missingHttpOnly && ! $missingSecure => 'other sites can make the browser send it',
            default => 'it is easier to steal or misuse',
        };

        $last = array_pop($names);
        $flags = $names === []
            ? $last
            : implode(', ', $names) . ' and ' . $last;

        $message = sprintf(
            'SESAME: the session cookie is sent without %s, so %s. Set them before session_start(): session_set_cookie_params([\'httponly\' => true, \'secure\' => true, \'samesite\' => \'Lax\']); (or call helmet_boot()).',
            $flags,
            $reason,
        );

        if ($this->checkSessionSettings === true) {
            throw new LogicException($message);
        }

        if (self::$warnedCookie) {
            return;
        }

        self::$warnedCookie = true;
        trigger_error($message, E_USER_WARNING);
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
        $this->hardenBeforeStart();
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
        $this->hardenBeforeStart();
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
     * Start the session under the ID regenerate() or invalidate() just
     * made, holding $data.
     *
     * Strict mode (HELMET and Squire's php.ini turn it on) refuses an ID the
     * store has never seen, and the new ID is not in the store yet: PHP
     * would quietly swap in an ID of its own, and the old session's marker
     * would name an ID that never exists. The ID came from
     * session_create_id() a moment ago, never from the request, so the new
     * session is first stored with strict mode off (the PHP manual's own
     * regenerate recipe does the same), then reopened with the app's
     * setting back, so the rest of the request runs as the app set it up.
     * A strict bouncer, but it does let in the guest it just wrote on the
     * list.
     *
     * @param array<mixed> $data
     */
    private function startUnderNewId(string $id, array $data): void
    {
        // The old session is closed here, so this is a start of our own.
        $this->hardenBeforeStart();

        $strict = ini_get('session.use_strict_mode');

        if ($strict !== false && self::isOn($strict)) {
            ini_set('session.use_strict_mode', '0');
            session_id($id);
            session_start();
            $_SESSION = $data;
            session_write_close();
            ini_set('session.use_strict_mode', $strict);
        } else {
            session_id($id);
        }

        session_start();
        $_SESSION = $data;
    }

    /**
     * Whether a boolean ini value is on ("1", "On", "true", "yes").
     */
    private static function isOn(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
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
