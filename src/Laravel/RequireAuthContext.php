<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Laravel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Waynestate\Sesame\Exception\AuthContextNotSatisfied;
use Waynestate\Sesame\Sesame;

/**
 * The sesame.context middleware.
 *
 * It lets the request through when the signed-in identity has the required
 * authentication context, and otherwise answers 403. The context id comes
 * from the route parameter ("sesame.context:c1"). JSON requests get a JSON
 * 403, because a plain body is useless to them.
 */
class RequireAuthContext
{
    public function __construct(
        private readonly Sesame $sesame,
    ) {}

    public function handle(Request $request, Closure $next, mixed $context = null): Response
    {
        // Laravel hands middleware parameters over as strings
        // ("sesame.context:c1" arrives as "c1"), so the value is cast, not
        // type-hinted. A missing parameter fails closed: no context named,
        // no context satisfied.
        $id = (string) $context;

        if ($this->sesame->hasAuthContext($id)) {
            return $next($request);
        }

        $message = AuthContextNotSatisfied::messageFor($id);

        if ($request->expectsJson()) {
            return response(['message' => $message], 403);
        }

        return response($message, 403);
    }
}
