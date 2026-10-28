<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Laravel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Waynestate\Sesame\Sesame;

/**
 * The sesame.mfa middleware.
 *
 * It lets the request through when the signed-in user's MFA is still good,
 * and otherwise stops it. The allowed age comes from the route parameter
 * when there is one, and from sesame.mfa.max_age when there is not. When the
 * check fails, the app chooses the outcome: "deny" (the default) answers
 * 403, and "reauth" sends the user back to the login route. JSON requests
 * get a 403 either way, because a redirect is useless to them.
 */
class RequireMfa
{
    public function __construct(
        private readonly Sesame $sesame,
    ) {
    }

    public function handle(Request $request, Closure $next, ?int $seconds = null): Response
    {
        $maxAge = $seconds ?? (int) config('sesame.mfa.max_age', 28800);

        $result = $this->sesame->requireMfa($maxAge);

        if ($result->satisfied) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response(['message' => $result->message()], 403);
        }

        if (config('sesame.mfa.on_failure', 'deny') === 'reauth') {
            return redirect()->guest(route('sesame.login'));
        }

        return response($result->message(), 403);
    }
}
