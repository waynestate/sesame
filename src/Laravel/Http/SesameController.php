<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Laravel\Http;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Drivers\SamlDriver;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Sesame;

/**
 * The actions behind SESAME's routes.
 *
 * Every route the provider registers points at a method here instead of a
 * closure: a closure route is serialized with everything it captures when
 * the app caches its routes, and the old closures captured the container,
 * which is what made `php artisan route:cache` run out of memory in an app
 * that runs `optimize` (which every Citadel release does). A method action
 * serializes as a class name and a method name.
 */
final class SesameController
{
    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * GET /login and, for the oidc driver, GET /login/callback.
     *
     * When the driver answers with a Redirect the user must go to the
     * identity provider, so the redirect goes back unchanged. Otherwise
     * the sign-in just completed: the oidc driver remembers a checked
     * return-to (same host as the redirect URI), and every other driver
     * uses the page auth.sesame saved before it sent the guest to sign
     * in.
     */
    public function login(Request $request): RedirectResponse
    {
        $sesame = $this->app->make(Sesame::class);
        $result = $sesame->login($this->toPsr7($request));

        if ($result instanceof Redirect) {
            return redirect($result->url);
        }

        $returnTo = $sesame->returnTo();

        return $returnTo !== null ? redirect($returnTo) : redirect()->intended('/');
    }

    /**
     * POST /logout: sign out and keep the answer out of any cache.
     */
    public function logout(): RedirectResponse
    {
        $url = $this->app->make(Sesame::class)->logout(url('/'));

        // Keep the logout response out of any cache and clear the
        // site's cache and storage. "cookies" is deliberately left
        // out of Clear-Site-Data: it would clear every cookie on the
        // host, signing the user out of every other app on
        // wayne.edu, not just this one.
        $response = $url !== null ? redirect($url) : redirect('/');

        return $response
            ->header('Cache-Control', 'no-store')
            ->header('Clear-Site-Data', '"cache", "storage"');
    }

    /**
     * GET /sesame/status: the single-page app's "who am I".
     *
     * The session facts as JSON, 200 when someone is signed in, 401 when
     * no one is, never cacheable. The app's own user fields come from a
     * closure it bound as sesame.describe_user; without one the answer's
     * user field is null. A binding of anything other than a closure
     * reads as no extra fields, not an error.
     */
    public function status(): JsonResponse
    {
        $describe = null;

        if ($this->app->bound('sesame.describe_user')) {
            $bound = $this->app->make('sesame.describe_user');

            if ($bound instanceof Closure) {
                $describe = $bound;
            }
        }

        $status = $this->app->make(Sesame::class)->sessionStatus($describe);

        return response()->json($status, $status['signedIn'] ? 200 : 401)
            ->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * POST /login/saml/acs: the IdP's cross-site POST.
     *
     * It carries no Lax session cookie and no CSRF token, so it runs
     * without the web group's middleware; the driver parks the response
     * and the 303 sends the browser to /login, where the session is back
     * and the response is checked.
     */
    public function samlAcs(Request $request): Response
    {
        $driver = $this->app->make(Driver::class);

        if (! $driver instanceof SamlDriver) {
            abort(404);
        }

        try {
            $redirect = $driver->receive($this->toPsr7($request));
        } catch (AuthenticationFailed) {
            return response('The sign-in response could not be used. Start the sign-in again.', 400)
                ->header('Content-Type', 'text/plain; charset=UTF-8')
                ->header('Cache-Control', 'no-store');
        }

        return redirect($redirect->url, 303)->header('Cache-Control', 'no-store');
    }

    /**
     * GET /login/saml/metadata: the SP metadata document.
     */
    public function samlMetadata(): Response
    {
        $driver = $this->app->make(Driver::class);

        if (! $driver instanceof SamlDriver) {
            abort(404);
        }

        return response($driver->metadata(), 200)
            ->header('Content-Type', 'application/samlmetadata+xml')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * The PSR-7 view of the incoming request, for the core.
     */
    private function toPsr7(Request $request): ServerRequestInterface
    {
        $factory = new Psr17Factory();

        return (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromArrays(
            $request->server->all(),
            $request->headers->all(),
            $request->cookies->all(),
            $request->query->all(),
            $request->request->all(),
            $request->files->all(),
        );
    }
}
