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
 *
 * It is safe in the global web middleware group: it lets the named routes
 * sesame.login, sesame.callback and sesame.logout straight through, so a
 * guest on /login is not redirected to /login forever. An app that took the
 * routes over (sesame.routes = false) must keep those route names, or
 * exclude its own routes from this middleware.
 */
class WayneAuthenticate
{
    public function __construct(
        private readonly Sesame $sesame,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('sesame.login', 'sesame.callback', 'sesame.logout')) {
            return $next($request);
        }

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
