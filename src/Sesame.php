<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The facade an app talks to.
 *
 * It holds a driver, the app's user resolver and the app's session, and it
 * runs the one rule the code holds: every driver ends in
 * `findOrImportUser(Identity)`, and the app owns the session.
 */
final class Sesame
{
    public function __construct(
        private readonly Driver $driver,
        private readonly UserResolver $resolver,
        private readonly SessionPort $session,
    ) {
    }

    /**
     * Handle a login request.
     *
     * Returns the Redirect unchanged when the user must go to the identity
     * provider. Otherwise it resolves the user. When the resolver returns a
     * user it regenerates the session ID before it stores anything, and
     * returns the user. The identity it stores keeps the username, the
     * driver and the time, but not the raw IdP attributes, so the session
     * stays small and plain. When the resolver returns null the app has
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

        return $user;
    }

    /**
     * The app's user for the current session, or null when there is none.
     *
     * @return mixed
     */
    public function user(): mixed
    {
        return $this->session->get('sesame.user');
    }

    /**
     * Log the user out of the app, then point at the identity provider.
     *
     * The app session is invalidated before the provider's URL is built, so a
     * failed redirect can never leave a live session behind.
     */
    public function logout(string $returnTo): ?string
    {
        $this->session->forget('sesame.user');
        $this->session->forget('sesame.identity');
        $this->session->invalidate();

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
}
