<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

use Closure;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Waynestate\Sesame\Clock\SystemClock;
use Waynestate\Sesame\Exception\AuthenticationFailed;

/**
 * The facade an app talks to.
 *
 * It holds a driver (or a factory for one, built on first use), the app's
 * user resolver, the app's session and the config, and it runs the one rule
 * the code holds: every driver ends in `findOrImportUser(Identity)`, and the
 * app owns the session. Time is read through a PSR-20 clock, so the session
 * timeouts are testable.
 */
final class Sesame
{
    private readonly ClockInterface $clock;

    /** @var Driver|Closure(): Driver */
    private readonly Driver|Closure $driverFactory;

    private ?Driver $driver = null;

    /**
     * @param Driver|Closure(): Driver $driver The driver, or a factory that
     *        builds it. With a factory the driver is built on first use, so
     *        checking a session never pays for the driver's construction.
     * @param LoggerInterface|null $logger Optional PSR-3 logger. When given,
     *        sign-in and sign-out events are logged through it; a logger
     *        that throws never changes what the facade returns or throws.
     */
    public function __construct(
        Driver|Closure $driver,
        private readonly UserResolver $resolver,
        private readonly SessionPort $session,
        private readonly Config $config,
        ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->driverFactory = $driver;
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * The driver, built on first use and kept from then on.
     */
    private function driver(): Driver
    {
        if ($this->driver === null) {
            $factory = $this->driverFactory;

            $this->driver = $factory instanceof Closure ? $factory() : $factory;
        }

        return $this->driver;
    }

    /**
     * Handle a login request.
     *
     * Returns the Redirect unchanged when the user must go to the identity
     * provider. Otherwise it resolves the user. When the resolver returns a
     * user it regenerates the session ID before it stores anything, and
     * returns the user. The identity it stores keeps the username, the
     * driver and the time, but not the raw IdP attributes, so the session
     * stays small and plain. The timestamps it stores start the session's
     * clock: the idle timeout counts from the last activity, the absolute
     * lifetime from the sign-in. When the resolver returns null the app has
     * refused the identity: the session is left untouched, nothing is
     * stored, and null is returned, so a refused identity never ends up
     * signed in.
     *
     * @return mixed A Redirect, the app's user, or null when the resolver
     *               refused the identity.
     */
    public function login(ServerRequestInterface $request): mixed
    {
        try {
            $step = $this->driver()->login($request);
        } catch (AuthenticationFailed $failure) {
            // The failure message can carry the ticket or other provider
            // details, so it is never logged: only the event, the driver
            // and the time. The exception is rethrown unchanged.
            $this->log('warning', 'sesame.login_failed', [
                'event' => 'login_failed',
                'driver' => $this->config->driver,
                'at' => $this->now(),
            ]);

            throw $failure;
        }

        if (! $step instanceof Authenticated) {
            return $step;
        }

        $user = $this->resolver->findOrImportUser($step->identity);

        if ($user === null) {
            $this->log('warning', 'sesame.login_refused', [
                'event' => 'login_refused',
                'driver' => $this->config->driver,
                'username' => self::safeUsername($step->identity->username),
                'at' => $this->now(),
            ]);

            return null;
        }

        $now = $this->clock->now()->getTimestamp();

        $this->session->regenerate();
        $this->session->put('sesame.user', $user);
        // Keep the session small and plain: store the identity without its
        // raw IdP attributes (S6), but keep the MFA facts (the sign-in time
        // and the methods) and the authentication contexts, because
        // requireMfa() and hasAuthContext() read them back from the session.
        $this->session->put('sesame.identity', new Identity(
            $step->identity->username,
            [],
            $step->identity->driver,
            $step->identity->authenticatedAt,
            $step->identity->authTime,
            $step->identity->amr,
            $step->identity->authContexts(),
        ));
        $this->session->put('sesame.signed_in_at', $now);
        $this->session->put('sesame.last_seen', $now);
        // The session reference ties every auth event of this sign-in
        // together in the log. It is fresh on every login and is never
        // derived from the session ID, so a stolen ID reveals nothing.
        $sessionRef = bin2hex(random_bytes(8));
        $this->session->put('sesame.session_ref', $sessionRef);

        $this->log('info', 'sesame.login', [
            'event' => 'login',
            'driver' => $this->config->driver,
            'username' => self::safeUsername($step->identity->username),
            'at' => $this->now(),
            'session_ref' => $sessionRef,
        ]);

        return $user;
    }

    /**
     * Where to send the user after the sign-in that just completed, or null
     * when the driver remembers none.
     *
     * Only drivers that implement RemembersReturnTo (the oidc driver) answer;
     * the value has already passed the ReturnTo rule.
     */
    public function returnTo(): ?string
    {
        $driver = $this->driver();

        return $driver instanceof RemembersReturnTo ? $driver->returnTo() : null;
    }

    /**
     * The driver's configured service URL, or null when the driver has none.
     */
    public function serviceUrl(): ?string
    {
        return $this->driver()->serviceUrl();
    }

    /**
     * The app's user for the current session, or null when there is none.
     *
     * The session is checked against the timeouts before it is trusted: a
     * session that has been quiet for longer than the idle timeout, or that
     * has lived for longer than the absolute lifetime, is signed out on the
     * spot, and a session with a user but no timestamps (one made before
     * the timeouts existed) counts as expired. Every "is someone signed in"
     * check goes through here, so a stale session can never pass one check
     * and fail another. When the resolver implements RefreshesUser, the
     * stored user is looked up again before the answer, and a null result
     * signs the session out, so a deleted account stops reading as signed
     * in from the next request on.
     *
     * @return mixed
     */
    public function user(): mixed
    {
        $user = $this->session->get('sesame.user');

        if ($user === null) {
            return null;
        }

        $signedInAt = $this->session->get('sesame.signed_in_at');
        $lastSeen = $this->session->get('sesame.last_seen');

        if (! is_int($signedInAt) || ! is_int($lastSeen)) {
            $this->signOut('no_timestamps');

            return null;
        }

        $now = $this->clock->now()->getTimestamp();

        if ($now - $lastSeen > $this->config->sessionIdleMinutes * 60
            || $now - $signedInAt > $this->config->sessionAbsoluteMinutes * 60) {
            $reason = $now - $lastSeen > $this->config->sessionIdleMinutes * 60
                ? 'idle_timeout'
                : 'absolute_timeout';
            $this->signOut($reason);

            return null;
        }

        $this->session->put('sesame.last_seen', $now);

        if ($this->resolver instanceof RefreshesUser) {
            $fresh = $this->resolver->refreshUser($user);

            if ($fresh === null) {
                $this->signOut('user_gone');

                return null;
            }

            if ($fresh !== $user) {
                $this->session->put('sesame.user', $fresh);
            }

            return $fresh;
        }

        return $user;
    }

    /**
     * Decide whether the signed-in user's MFA is still good.
     *
     * The session is checked first, through user(), so a stale session is
     * signed out before the MFA question is even asked. When there is no
     * user, or the session has no identity to read, the answer is
     * NO_AUTH_TIME: the check fails closed. Otherwise the identity's MFA
     * facts are evaluated against the allowed age, using the same clock the
     * session timeouts use.
     */
    public function requireMfa(int $maxAgeSeconds = 28800): MfaResult
    {
        if ($this->user() === null) {
            return new MfaResult(false, MfaResult::NO_AUTH_TIME);
        }

        $identity = $this->session->get('sesame.identity');

        if (! $identity instanceof Identity) {
            return new MfaResult(false, MfaResult::NO_AUTH_TIME);
        }

        return Mfa::satisfied($identity, $maxAgeSeconds, $this->clock->now());
    }

    /**
     * Decide whether the signed-in identity has the given authentication
     * context.
     *
     * The session is checked first, through user(), so a stale session is
     * signed out before the question is even asked. When there is no user,
     * or the session has no identity to read, the answer is false: the check
     * fails closed.
     */
    public function hasAuthContext(string $id): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $identity = $this->session->get('sesame.identity');

        if (! $identity instanceof Identity) {
            return false;
        }

        return $identity->hasAuthContext($id);
    }

    /**
     * Log the user out of the app, then point at the identity provider.
     *
     * The app session is invalidated before the provider's URL is built, so a
     * failed redirect can never leave a live session behind. The pointer goes
     * out even when the session holds no user of its own: the single sign-on
     * session at the provider may still be alive there.
     */
    public function logout(string $returnTo): ?string
    {
        $this->signOut('logout');

        return $this->driver()->logoutUrl($returnTo);
    }

    /**
     * Clear SESAME's session keys and invalidate the session, without
     * pointing at the identity provider.
     *
     * The guard's logout() uses it: the app signs the user out of its own
     * session, and the provider's single sign-on session is left alone. The
     * logout route keeps logout(), which does the same and then builds the
     * provider's URL.
     */
    public function signOutLocal(): void
    {
        $this->signOut('logout');
    }

    /**
     * The form field the raw-PHP logout reads its CSRF token from.
     */
    public const LOGOUT_TOKEN_FIELD = 'sesame_logout_token';

    /**
     * The session key that holds the logout CSRF token.
     */
    public const LOGOUT_TOKEN_KEY = 'sesame.logout_token';

    /**
     * The logout CSRF token for this session, made on first use.
     *
     * The raw-PHP logout (sesame_logout()) only runs for a POST that
     * carries this token, so another site cannot sign the user out with a
     * link or a hidden form. Laravel apps use Laravel's own CSRF token
     * instead (the logout route is in the web middleware group).
     */
    public function logoutToken(): string
    {
        $token = $this->session->get(self::LOGOUT_TOKEN_KEY);

        if (! is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->put(self::LOGOUT_TOKEN_KEY, $token);
        }

        return $token;
    }

    /**
     * Whether $token is this session's logout CSRF token. A session that
     * never made one accepts nothing.
     */
    public function isLogoutToken(#[\SensitiveParameter] mixed $token): bool
    {
        $expected = $this->session->get(self::LOGOUT_TOKEN_KEY);

        return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /**
     * The safe URL to send the user to after logout.
     *
     * The return-to must be an absolute https URL on the service URL's scheme, host and port;
     * anything else sends the user to the service URL instead, so a logout
     * can never be turned into an open redirect.
     */
    public function safeReturnTo(string $returnTo): string
    {
        return ReturnTo::safe($returnTo, $this->driver()->serviceUrl());
    }

    /**
     * Clear SESAME's session keys and invalidate the session.
     *
     * The one sign-out the logout route and the timeout check both use, so
     * they can never disagree about whether the session is dead. When a
     * user or an identity was actually stored, the sign-out is logged with
     * its reason: the driver and the username are read from the stored
     * identity before it is forgotten, and an empty session logs nothing.
     * When the session holds a valid session reference (see login()), the
     * sign-out carries it, so the events of one sign-in stay together in
     * the log; a reference that is not a 16-character lowercase hex string
     * is ignored, so a tampered session cannot inject log text.
     */
    private function signOut(string $reason): void
    {
        // The log wants the driver and the username from the stored
        // identity, so it is read before it is forgotten; without a
        // logger the session is never touched beyond the sign-out itself.
        $user = null;
        $identity = null;
        $sessionRef = null;

        if ($this->logger !== null) {
            $user = $this->session->get('sesame.user');
            $identity = $this->session->get('sesame.identity');
            // The ref is read before the session is cleared, and only a
            // 16-character lowercase hex value made by login() is trusted:
            // anything else in the session is ignored, so a tampered
            // session cannot inject text into the log.
            $storedRef = $this->session->get('sesame.session_ref');
            if (is_string($storedRef) && preg_match('/^[0-9a-f]{16}$/', $storedRef) === 1) {
                $sessionRef = $storedRef;
            }
        }

        $this->session->forget('sesame.user');
        $this->session->forget('sesame.identity');
        $this->session->forget('sesame.session_ref');
        $this->session->invalidate();

        if ($user === null && ! $identity instanceof Identity) {
            return;
        }

        $context = [
            'event' => 'logout',
            'reason' => $reason,
            'driver' => $identity instanceof Identity ? $identity->driver : $this->config->driver,
            'username' => $identity instanceof Identity ? self::safeUsername($identity->username) : null,
            'at' => $this->now(),
        ];

        if ($sessionRef !== null) {
            $context['session_ref'] = $sessionRef;
        }

        $this->log('info', 'sesame.logout', $context);
    }

    /**
     * Write one event to the optional logger.
     *
     * A broken logger must never change what the facade returns or throws,
     * so every call is swallowed. The context carries only the event, the
     * driver, the log-safe username and the time: no ticket, session ID,
     * token, assertion, cookie or header ever reaches the log.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger === null) {
            return;
        }

        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable) {
            // The logger is best effort: a broken one must not break the
            // sign-in, the user check or the sign-out.
        }
    }

    /**
     * The clock's current time as a UTC DATE_ATOM string, for the `at`
     * context of a logged event.
     */
    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    /**
     * Make a username safe to put in a log line.
     *
     * Every control character (0x00 to 0x1F and 0x7F) becomes `?`, so a
     * username cannot forge an extra log line, and the result is cut at
     * 128 characters, so it cannot flood the log either.
     */
    private static function safeUsername(string $username): string
    {
        $safe = preg_replace('/[\x00-\x1f\x7f]/', '?', $username) ?? '';

        return substr($safe, 0, 128);
    }
}
