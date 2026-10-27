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

        if ($step instanceof Redirect) {
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
        // raw IdP attributes (S6).
        $this->session->put('sesame.identity', new Identity(
            $step->identity->username,
            [],
            $step->identity->driver,
            $step->identity->authenticatedAt,
        ));
        $this->session->put('sesame.signed_in_at', $now);
        $this->session->put('sesame.last_seen', $now);

        return $user;
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
     * The return-to must be an absolute https URL on the service host;
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
