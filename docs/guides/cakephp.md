---
title: CakePHP
description: The CakePHP 5 adapter plugs SESAME into cakephp/authentication 3.x: one session port, one authenticator, and the app's own login and logout actions.
---

# CakePHP

The CakePHP adapter is two thin classes over the core. `Waynestate\Sesame\Cake\CakeSession` is the session port: it forwards the five calls the core makes onto the app's `Cake\Http\Session`, so the core never sees a Cake type (dotted keys such as `sesame.user` nest under the top-level `sesame` key, which is where the core already keeps them). `Waynestate\Sesame\Cake\SesameAuthenticator` is an authenticator for cakephp/authentication 3.x: it asks the app's own Sesame whether someone is signed in, and sends a guest to the login route. Every rule lives in the core; this page is how to wire it into an app.

## Install

```
composer require waynestate/sesame cakephp/authentication guzzlehttp/guzzle
```

Guzzle is the PSR-18 client the cas driver uses to check tickets; any PSR-18 client works if you pass it yourself.

## Config

Add the app's SESAME config file, for example `config/sesame.php`:

```php
<?php

declare(strict_types=1);

use Waynestate\Sesame\Support\Setting;

return [
    'driver' => 'cas',
    'environment' => $_ENV['APP_ENV'] ?? 'production',
    'cas' => [
        'base_url' => Setting::get('SESAME_CAS_BASE_URL'),
        'service_url' => 'https://app.apps.wayne.edu/login',
    ],
];
```

The keys are the core's, as in [Raw PHP](raw-php.md): `fake.username` for the fake driver, the gateway and oidc keys in their driver guides. `environment` is the app's environment; the fake driver refuses to boot outside the local ones.

## Wiring

Build SESAME per request from the request's session, and hand the authenticator a closure that does:

```php
namespace App;

use Authentication\AuthenticationService;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Psr\Http\Message\ServerRequestInterface;
use Waynestate\Sesame\Cake\CakeSession;
use Waynestate\Sesame\Cake\SesameAuthenticator;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Sesame;

class Application extends BaseApplication
{
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue->add(new AuthenticationMiddleware($this->authenticationService()));
    }

    private function authenticationService(): AuthenticationService
    {
        $service = new AuthenticationService();
        $service->loadAuthenticator(SesameAuthenticator::class, [
            'sesame' => fn (ServerRequestInterface $request): Sesame => $this->sesameFor($request),
            'loginUrl' => '/login',
        ]);

        return $service;
    }

    public function sesameFor(ServerRequestInterface $request): Sesame
    {
        $config = Config::fromArray(require CONFIG . 'sesame.php');

        return new Sesame(
            DriverFactory::make($config),
            new AppUserResolver(),
            new CakeSession($request->getAttribute('session')),
            $config,
        );
    }
}
```

Load the authenticator first: if an old hand-written `SsoAuthenticator` is still registered, drop it, because two authenticators that both send guests to a login page fight over the redirect. `loginUrl` is the path of the login route below; leave it out (the default `null`) when the app prefers the plain cakephp/authentication shape, where the middleware's `unauthenticatedRedirect` sends guests to the login route instead.

## Login and logout actions

The login route's action starts the sign-in through SESAME. `Sesame::login()` answers with a `Redirect` while the browser still has to go to the identity provider, and with the user (or `null` when the resolver refused) once the ticket has checked out:

```php
public function login(): never
{
    $request = $this->getRequest();
    $step = $this->getApplication()->sesameFor($request)->login($request);

    if ($step instanceof \Waynestate\Sesame\Redirect) {
        throw new RedirectException($step->url, 302);
    }

    $target = $request->getQuery('redirect');
    if (! is_string($target) || ! str_starts_with($target, '/') || str_starts_with($target, '//')) {
        $target = '/';
    }

    throw new RedirectException($target, 302);
}
```

When `loginUrl` is set, the authenticator sends every guest to this route with `?redirect=<path>` for the page they asked for, and a guest already on the route gets the failure result instead of another redirect, so this action is the one place the sign-in starts. Catch `\Waynestate\Sesame\Exception\AuthenticationFailed` (and its subclass `ProviderUnavailable`) around the call the way [Raw PHP](raw-php.md) does, when the app wants a page instead of a stack trace.

The logout action ends the local sign-in and hands the browser to the identity provider's logout when the driver has one:

```php
public function logout(): never
{
    $request = $this->getRequest();
    $url = $this->getApplication()->sesameFor($request)->logout('/');

    throw new RedirectException($url ?? '/', 302);
}
```

The framework's own `logout()` works too: the authenticator implements the persistence interface, and its `clearIdentity()` calls `Sesame::signOutLocal()`, so the framework's sign-out clears what SESAME stored.

## The user resolver

Your `UserResolver` looks the verified identity up in the app's own store and returns the app's user, which is what the session holds and the authenticator hands back. Key the store on the driver plus the identity provider's stable identifier, never on an email or a display name; for the cas driver that is the username (the Access ID). Return an array when you can: the framework's identity carries arrays as they are, while any other user arrives wrapped as `['user' => <user>]`. When the app has no user table yet, an identity array is a fine user:

```php
final class AppUserResolver implements UserResolver
{
    public function findOrImportUser(Identity $identity): mixed
    {
        return [
            'username' => $identity->username,
            'name' => $identity->attributes['cn'][0] ?? null,
        ];
    }
}
```

Read the signed-in user back from the request attribute the middleware sets:

```php
$user = $this->getRequest()->getAttribute('identity');
```

## Old validUser cookie apps

An app that signs people in by reading the `validUser` SSO cookie itself moves off Wayne Auth as described in [Moving off Wayne Auth](moving-off-wayne-auth.md); the bridge keeps the old `WayneAuth` class working while the move happens, and this adapter is where a CakePHP app lands once it is on the native calls.

## Related

- [Raw PHP](raw-php.md)
- [Laravel](laravel.md)
- [Session timeouts](session-timeouts.md)
- [Moving off Wayne Auth](moving-off-wayne-auth.md)
