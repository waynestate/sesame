<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Waynestate\Sesame\Clock\SystemClock;

/**
 * The facade an app talks to.
 *
 * It holds a driver, the app's user resolver, the app's session and the
 * config, and it runs the one rule the code holds: every driver ends in
 * `findOrImportUser(Identity)`, and the app owns the session. Time is read
 * through a PSR-20 clock, so the session timeouts are testable.
 */
final class Sesame
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Driver $driver,
        private readonly UserResolver $resolver,
        private readonly SessionPort $session,
        private readonly Config $config,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
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
        $step = $this->driver->login($request);

        if (! $step instanceof Authenticated) {
            return $step;
        }

        $user = $this->resolver->findOrImportUser($step->identity);

        if ($user === null) {
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
        return $this->driver instanceof RemembersReturnTo ? $this->driver->returnTo() : null;
    }

    /**
     * The driver's configured service URL, or null when the driver has none.
     */
    public function serviceUrl(): ?string
    {
        return $this->driver->serviceUrl();
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
     * and fail another.
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
            $this->signOut();

            return null;
        }

        $now = $this->clock->now()->getTimestamp();

        if ($now - $lastSeen > $this->config->sessionIdleMinutes * 60
            || $now - $signedInAt > $this->config->sessionAbsoluteMinutes * 60) {
            $this->signOut();

            return null;
        }

        $this->session->put('sesame.last_seen', $now);

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
     * failed redirect can never leave a live session behind.
     */
    public function logout(string $returnTo): ?string
    {
        $this->signOut();

        return $this->driver->logoutUrl($returnTo);
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
        return ReturnTo::safe($returnTo, $this->driver->serviceUrl());
    }

    /**
     * Clear SESAME's session keys and invalidate the session.
     *
     * The one sign-out the logout route and the timeout check both use, so
     * they can never disagree about whether the session is dead.
     */
    private function signOut(): void
    {
        $this->session->forget('sesame.user');
        $this->session->forget('sesame.identity');
        $this->session->invalidate();
    }
}
