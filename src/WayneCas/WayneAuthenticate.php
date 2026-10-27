<?php

declare(strict_types=1);

namespace Waynestate\Sesame\WayneCas;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Waynestate\Sesame\Sesame;

/**
 * The wayne.cas middleware: the wayne-cas WayneAuthenticate, kept for apps
 * that still reference it by name.
 *
 * It checks the SESAME session instead of the Laravel auth guard, and it
 * sends unauthenticated users to the sesame.login route instead of running
 * the CAS dance itself. The AJAX 403 is kept, because wayne-cas returned it.
 */
class WayneAuthenticate
{
    public function __construct(
        private readonly Sesame $sesame,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        Deprecation::logOnce();

        if ($this->sesame->user() !== null) {
            return $next($request);
        }

        if ($request->ajax()) {
            return response('Unauthorized.', 403);
        }

        if ($request->expectsJson()) {
            return response('Unauthorized.', 401);
        }

        return redirect()->guest(route('sesame.login'));
    }
}
