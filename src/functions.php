<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;
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
     * script, so no protected code runs after the redirect. When the login
     * completes it sends the 302 to $returnTo and stops the script, so the
     * ticket never stays in the address bar (it would otherwise end up in
     * browser history, bookmarks and Referer headers).
     *
     * $returnTo is the page the user wanted, or the app's root. It must be a
     * relative path on the app's host (for example '/' or '/dashboard'); an
     * absolute URL would turn the login into an open redirect.
     */
    function sesame_login(string $returnTo = '/'): void
    {
        $request = sesame_request();
        $result = sesame()->login($request);

        if ($result instanceof Redirect) {
            header('Location: '.$result->url, true, 302);
            exit;
        }

        // The login has completed (or the resolver refused the identity).
        // Either way the ticket must not stay in the address bar, so send the
        // user on to the page they wanted, or the app's root.
        header('Location: '.$returnTo, true, 302);
        exit;
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

if (! function_exists('sesame_logout')) {

    /**
     * Log the user out, then send them on.
     *
     * Invalidates the app session, redirects to the identity provider's
     * logout URL when the driver has one, otherwise to the safe return-to,
     * and stops the script, so no protected code runs after the redirect.
     *
     * The return-to must be an absolute https URL on the service host;
     * anything else sends the user to the service URL instead, so a logout
     * can never be turned into an open redirect.
     */
    function sesame_logout(string $returnTo): void
    {
        $url = sesame()->logout($returnTo);

        header('Location: '.($url ?? sesame()->safeReturnTo($returnTo)), true, 302);
        exit;
    }
}
