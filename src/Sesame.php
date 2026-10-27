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
     * provider. Otherwise it resolves the user, regenerates the session ID
     * before it stores anything, and returns the user.
     *
     * @return mixed A Redirect, or the app's user.
     */
    public function login(ServerRequestInterface $request): mixed
    {
        $step = $this->driver->login($request);

        if ($step instanceof Redirect) {
            return $step;
        }

        $user = $this->resolver->findOrImportUser($step->identity);

        $this->session->regenerate();
        $this->session->put('sesame.user', $user);
        $this->session->put('sesame.identity', $step->identity);

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
}
