<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;
use Waynestate\Sesame\Exception\AuthContextNotSatisfied;
use Waynestate\Sesame\Exception\MfaNotSatisfied;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Sesame;

/**
 * SESAME, the raw-PHP path.
 *
 * Plain functions in the global namespace behind a `sesame_` prefix, the same
 * shape as WayneUI's standalone path, because that is what a framework-free
 * application can consume. Each one is guarded with `function_exists` so two
 * copies of this file can never collide.
 *
 * The app owns the session: start it with `session_start()` before calling
 * anything here, and let HELMET set the cookie flags.
 */

if (! function_exists('sesame')) {

    /**
     * Hold the one Sesame instance for this process.
     *
     * Set it once at boot, read it after:
     *
     *     sesame($sesame);   // boot
     *     sesame();          // read
     *
     * @param  Sesame|null  $instance  Pass the instance to set it; pass nothing to read it.
     */
    function sesame(?Sesame $instance = null): Sesame
    {
        static $held = null;

        if ($instance !== null) {
            $held = $instance;
        }

        if ($held === null) {
            throw new LogicException('No Sesame instance has been set. Call sesame($instance) at boot.');
        }

        return $held;
    }
}

if (! function_exists('sesame_request')) {

    /**
     * Build the PSR-7 request for the current request from the globals.
     *
     * The PSR-17 factories come from nyholm/psr7, which the library already
     * requires, so the raw-PHP path needs nothing but the autoloader.
     */
    function sesame_request(): ServerRequestInterface
    {
        $f = new Psr17Factory();

        return (new ServerRequestCreator($f, $f, $f, $f))->fromGlobals();
    }
}

if (! function_exists('sesame_login')) {

    /**
     * Run a login against the current request.
     *
     * Builds the PSR-7 request from the globals, hands it to the driver, and
     * when the driver answers with a Redirect sends the 302 and stops the
     * script, so no protected code runs after the redirect.
     *
     * A request that already has a user and no ticket returns that user
     * without calling the driver, so a page that calls sesame_login() on
     * every request cannot bounce the signed-in user back to the identity
     * provider in a loop.
     *
     * When a ticket completes the login, the user is sent to the driver's
     * service URL, which has no ticket, and the script stops, so the ticket
     * never stays in the address bar (it would otherwise end up in browser
     * history, bookmarks and Referer headers). When the driver has no
     * service URL, the user is returned without a redirect.
     *
     * When the resolver refuses the identity, null is returned and no
     * redirect is sent: the ticket is single-use, so leaving it in that one
     * refused URL is harmless.
     *
     * The oidc driver's callback (a request with state and a code or an
     * error) counts as a ticket here: it always goes to the driver, and a
     * completed sign-in is sent on to the checked return-to the sign-in
     * began with, or to the service URL. A request with step_up=1 always
     * goes to the driver too, so a signed-in user can be asked for a fresh
     * sign-in.
     *
     * @return mixed The app's user, or null when the resolver refused the
     *               identity.
     */
    function sesame_login(): mixed
    {
        $request = sesame_request();
        $query = $request->getQueryParams();
        $ticket = $query['ticket'] ?? null;

        if ($ticket === null && isset($query['state']) && (isset($query['code']) || isset($query['error']))) {
            $ticket = $query['state'];
        }

        if ($ticket === null && ! isset($query['step_up'])) {
            $user = sesame()->user();

            if ($user !== null) {
                return $user;
            }
        }

        $result = sesame()->login($request);

        if ($result instanceof Redirect) {
            header('Location: ' . $result->url, true, 302);
            exit;
        }

        if ($ticket !== null && $result !== null) {
            $serviceUrl = sesame()->returnTo() ?? sesame()->serviceUrl();

            if ($serviceUrl !== null) {
                header('Location: ' . $serviceUrl, true, 302);
                exit;
            }
        }

        return $result;
    }
}

if (! function_exists('sesame_user')) {

    /**
     * The app's user for the current session, or null when there is none.
     *
     * @return mixed
     */
    function sesame_user(): mixed
    {
        return sesame()->user();
    }
}

if (! function_exists('sesame_require_mfa')) {

    /**
     * Require that the signed-in user's MFA is still good.
     *
     * Runs the MFA check against the current session. When it is satisfied,
     * the app's user is returned, so a protected page can read the user and
     * the MFA proof in one call. When it is not, MfaNotSatisfied is thrown,
     * carrying the result with the stable reason and the human message.
     *
     * @return mixed The app's user, when the MFA check is satisfied.
     *
     * @throws MfaNotSatisfied When the MFA check is not satisfied.
     */
    function sesame_require_mfa(int $maxAgeSeconds = 28800): mixed
    {
        $result = sesame()->requireMfa($maxAgeSeconds);

        if (! $result->satisfied) {
            throw new MfaNotSatisfied($result);
        }

        return sesame_user();
    }
}

if (! function_exists('sesame_require_context')) {

    /**
     * Require that the signed-in identity has the given authentication
     * context.
     *
     * Runs the context check against the current session. When the identity
     * has the context, the app's user is returned, so a protected page can
     * read the user and the context proof in one call. When it does not,
     * AuthContextNotSatisfied is thrown, carrying the context id.
     *
     * @return mixed The app's user, when the identity has the context.
     *
     * @throws AuthContextNotSatisfied When the identity lacks the context.
     */
    function sesame_require_context(string $id): mixed
    {
        if (! sesame()->hasAuthContext($id)) {
            throw new AuthContextNotSatisfied($id);
        }

        return sesame_user();
    }
}

if (! function_exists('sesame_logout')) {

    /**
     * Log the user out, then send them on.
     *
     * Invalidates the app session, redirects to the identity provider's
     * logout URL when the driver has one, otherwise to the safe return-to,
     * and stops the script, so no protected code runs after the redirect.
     *
     * The return-to must be an absolute https URL on the service URL's scheme, host and port;
     * anything else sends the user to the service URL instead, so a logout
     * can never be turned into an open redirect.
     */
    function sesame_logout(string $returnTo): void
    {
        $url = sesame()->logout($returnTo);

        // Keep the logout response out of any cache and clear the site's
        // cache and storage. "cookies" is deliberately left out of
        // Clear-Site-Data: it would clear every cookie on the host, signing
        // the user out of every other app on wayne.edu, not just this one.
        header('Cache-Control: no-store', true);
        header('Clear-Site-Data: "cache", "storage"', true);
        header('Location: ' . ($url ?? sesame()->safeReturnTo($returnTo)), true, 302);
        exit;
    }
}
