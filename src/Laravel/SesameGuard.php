<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Laravel;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Waynestate\Sesame\Sesame;

/**
 * The sesame guard driver, registered under auth.driver = "sesame".
 *
 * It is a session guard that also reads the SESAME session. A user Laravel's
 * own guard already knows (a login() call, onceUsingId(), setUser() or
 * actingAs()) wins for the request. When there is none, the answer comes
 * from the SESAME session, resolved from the container here and nowhere
 * else, and it is cached with setUser() so the rest of the request sees one
 * user. logout() clears both sides: the guard's copy and SESAME's local
 * session. It never points at the identity provider; the logout route owns
 * that redirect.
 */
class SesameGuard extends SessionGuard
{
    /**
     * The user for the current request.
     *
     * A user Laravel's own guard already knows wins; when there is none, the
     * answer comes from the SESAME session and is cached with setUser() so
     * the rest of the request sees one user. A SESAME user that is not
     * Authenticatable cannot take part in Laravel's auth, so it reads as
     * null here even though the middleware still sees it through Sesame.
     */
    public function user(): ?Authenticatable
    {
        if (! is_null($user = parent::user())) {
            return $user;
        }

        $user = app(Sesame::class)->user();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $this->setUser($user);

        return $user;
    }

    /**
     * Sign the user out of the app.
     *
     * It clears the guard's copy through the parent's logout and signs
     * SESAME's local session out. It never points at the identity provider;
     * the logout route owns that redirect.
     */
    public function logout(): void
    {
        parent::logout();

        app(Sesame::class)->signOutLocal();
    }
}
