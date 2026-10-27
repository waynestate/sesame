<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Laravel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Waynestate\Sesame\Sesame;

/**
 * The auth.sesame middleware.
 *
 * It lets the request through when SESAME has a user in the session, and
 * otherwise sends the user to the login route. JSON requests get a 401
 * instead of a redirect, because a redirect is useless to them.
 */
class Authenticate
{
    public function __construct(
        private readonly Sesame $sesame,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->sesame->user() !== null) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response('Unauthorized.', 401);
        }

        return redirect()->guest(route('sesame.login'));
    }
}
