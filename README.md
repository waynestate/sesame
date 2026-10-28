# SESAME

SESAME is Wayne State's protocol-neutral auth library. It returns a verified
identity and leaves the session to the app: every driver ends in the same
place, the app's `findOrImportUser(Identity)`, and the protocol never holds
the session. Switching driver is a config change with no code change. Open
sesame.

This repository is published from Wayne State University's internal GitLab and
mirrored here. Changes land there first; Wayne State developers open issues
and merge requests on the internal project.

## Install

```
composer require waynestate/sesame
```

## Raw PHP

```php
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\UserResolver;

session_start();

$config = Config::fromArray([
    'driver' => 'fake',
    'environment' => 'local',
    'fake' => [
        'username' => 'jdoe',
        'attributes' => ['cn' => ['John Doe']],
    ],
]);

$sesame = new Sesame(
    DriverFactory::make($config),
    new class implements UserResolver {
        public function findOrImportUser(Identity $identity): mixed
        {
            return $identity->username;
        }
    },
    new NativeSession(),
    $config,
);

sesame($sesame);

// When the login has not completed, sends the redirect to the identity
// provider and stops the script. When a ticket completes it, sends the
// redirect to the driver's service URL and stops the script, so the ticket
// never stays in the address bar. When the user is already signed in and
// the request has no ticket, returns the user.
$user = sesame_login();
```

The app owns the session: start it with `session_start()`, and let HELMET set
the cookie flags. SESAME regenerates the session ID on login and stores the
user and identity in it, but it never starts a session and never touches a
cookie. `sesame_login()` and `sesame_logout()` stop the script after they send
their redirect, so nothing after them runs in that request. A protected page
reads the user with `sesame_user()`:

```php
$user = sesame_user();
```

On logout, both paths send `Cache-Control: no-store` and
`Clear-Site-Data: "cache", "storage"` so a back button or a shared browser
cannot show a signed-in page; `"cookies"` is left out on purpose, because it
would clear every cookie on the host and sign the user out of every other app
on wayne.edu, not just this one.

## Drivers

| Driver | Status |
|---|---|
| `fake` | Now. Local development and tests only. Refuses to boot in production. |
| `cas` | Now. CAS 2.0 against Academica by default. |
| `gateway` | Now. A signed JWT assertion from the auth gateway (on Citadel, that is `auth.apps.wayne.edu`, the first planned one), never a bare header. |
| `saml` | Later. Direct SP. |
| `oauth2` | Later. JWT bearer tokens. |

## The cas driver

The cas driver logs in against a CAS server, CAS 2.0 by default. The base
URL is required config with no default; for Academica it is
`https://academica.aws.wayne.edu/cas`.

```php
<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;

$config = Config::fromArray([
    'driver' => 'cas',
    'environment' => 'production',
    'cas' => [
        'base_url' => 'https://academica.aws.wayne.edu/cas',
        'service_url' => 'https://app.apps.wayne.edu/login',
    ],
]);

// Any PSR-18 client works, for example Guzzle.
$http = new \GuzzleHttp\Client();

$driver = DriverFactory::make($config, $http, new Psr17Factory());
```

Then build the Sesame facade with the driver, as in the Raw PHP section
above.

Config keys:

| Key | Required | Default | Meaning |
|---|---|---|---|
| `cas.base_url` | Yes | none | The CAS server, an absolute https:// URL. |
| `cas.service_url` | Yes | none | Where the server sends the user back to. |
| `cas.protocol` | No | `2.0` | `3.0` or `2.0`. |
| `cas.renew` | No | `false` | Send `renew` on login and validation, so the CAS server requires a fresh, direct login. |

The service URL is the configured value only: the driver never reads the
request's Host or X-Forwarded-* headers to build it.

Protocol 2.0 is the default because Academica is believed to redirect
cookieless `/p3/serviceValidate` calls to the login page. Set `cas.protocol`
to `3.0` when a CAS server releases attributes on `/p3/serviceValidate`.

Set `cas.renew` to `true` when the app needs a fresh, direct login rather
than an existing SSO session. The driver then sends `renew` on the login
redirect and on the validation request, and the CAS server refuses a ticket
that came from an SSO session with `INVALID_TICKET`. Leave it off against
Academica: it does not issue a ticket for `renew=true` and sends the user
back to the login page, so a `renew` login loops instead of signing in.

The sign-in time on the identity comes from the CAS `authenticationDate`
attribute when the server sends one. When it is missing, unparseable, or more
than five minutes ahead of the driver's clock, the driver uses its own clock
instead, so a bad value can never push the sign-in time into the future.

## The gateway driver

The gateway driver signs the user in from a signed JWT assertion that the auth
gateway (DRAWBRIDGE) puts in one header. It verifies the assertion's signature
against the gateway's public key, then checks the issuer, audience and time
claims. It never reads a plain identity header — `Remote-User`,
`X-Remote-User`, or any spelling of them — because a plain header can come from
anywhere on the shared network. And the request must come from a trusted
proxy: the direct peer (`REMOTE_ADDR`) must match one of the configured CIDR
ranges. A valid assertion from an untrusted peer is still rejected.

The algorithm is pinned from config, so a token that claims a different
algorithm (or `none`) is refused before its signature is even looked at.

```php
<?php

declare(strict_types=1);

use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;

$config = Config::fromArray([
    'driver' => 'gateway',
    'environment' => 'production',
    'gateway' => [
        'public_key' => file_get_contents('/etc/sesame/gateway.pub'),
        'issuer' => 'https://drawbridge.wayne.edu',
        'audience' => 'sesame',
        'trusted_proxies' => ['10.0.0.0/8'],
    ],
]);

// The gateway driver needs no HTTP client: it only reads the assertion from
// the request.
$driver = DriverFactory::make($config);
```

Then build the Sesame facade with the driver, as in the Raw PHP section above.

Config keys:

| Key | Required | Default | Meaning |
|---|---|---|---|
| `gateway.header` | No | `X-Sesame-Assertion` | The one header that holds the signed assertion. |
| `gateway.algorithm` | No | `ES256` | `ES256`, `RS256` or `EdDSA`. Pinned, so a token that claims another algorithm is refused. |
| `gateway.public_key` | Yes | none | The gateway's public key: a PEM string (or a path to one) for `ES256` and `RS256`, or a base64-encoded raw key (or a path to one) for `EdDSA`. |
| `gateway.issuer` | Yes | none | The `iss` claim the assertion must carry. |
| `gateway.audience` | Yes | none | The `aud` claim the assertion must carry. |
| `gateway.leeway_seconds` | No | `30` | Clock skew allowed on the `iat`, `nbf` and `exp` checks, in seconds. |
| `gateway.trusted_proxies` | Yes | none | A non-empty array of CIDR ranges (or single IPs) the gateway proxy sits behind. |

The assertion must carry `sub`, `iat` and `exp`; the `sub` claim becomes the
username. The optional `email`, `name` and `affiliations` claims become
read-only identity attributes. The sign-in time on the identity comes from the
driver's clock.

## Session timeouts

Open sesame opens the door for a live session; a stale one finds it locked.
Every signed-in session has two clocks. The idle timeout is the quiet time
after which the session is dropped, and the absolute lifetime is the longest
a session may live, no matter how active it is. Both are in minutes, and both
are checked on every `user()` call, so a stale session is signed out on the
spot the first time anything asks who is signed in.

| Key | Default | Meaning |
|---|---|---|
| `session.idle_minutes` | `120` | Drop the session after this much quiet time. |
| `session.absolute_minutes` | `720` | End the session this long after sign-in, even if it is still active. |

On login the session stores the sign-in time and the last activity time, and
`user()` refreshes the last activity time on every call that finds a live
session. A session made before the timeouts existed (a user, no timestamps)
counts as expired.

In Laravel the keys are `SESAME_SESSION_IDLE_MINUTES` and
`SESAME_SESSION_ABSOLUTE_MINUTES`.

## Laravel

The Laravel adapter is a service provider that binds the core into the
container, registers the `auth.sesame` and `wayne.cas` middleware aliases,
and, unless the app has taken the routes over, registers the login and logout
routes. It holds no rules of its own: every rule lives in the core.

The provider is discovered automatically. Publish the config with:

```
php artisan vendor:publish --tag=sesame-config
```

Config keys (in `config/sesame.php`):

| Key | Env | Default | Meaning |
|---|---|---|---|
| `driver` | `SESAME_DRIVER` | `cas` | `cas`, `gateway` or `fake`. |
| `cas.base_url` | `SESAME_CAS_BASE_URL` | none | The CAS server, an absolute https:// URL. |
| `cas.service_url` | `SESAME_CAS_SERVICE_URL` | the app's `/login` route | Where the server sends the user back to. |
| `cas.protocol` | `SESAME_CAS_PROTOCOL` | `2.0` | `3.0` or `2.0`. |
| `fake.username` | `SESAME_FAKE_USERNAME` | none | The fake driver's username. |
| `gateway.header` | `SESAME_GATEWAY_HEADER` | `X-Sesame-Assertion` | The one header that holds the signed assertion. |
| `gateway.algorithm` | `SESAME_GATEWAY_ALGORITHM` | `ES256` | `ES256`, `RS256` or `EdDSA`. |
| `gateway.public_key` | `SESAME_GATEWAY_PUBLIC_KEY` | none | The gateway's public key. Required for the gateway driver. |
| `gateway.issuer` | `SESAME_GATEWAY_ISSUER` | none | The `iss` claim the assertion must carry. Required for the gateway driver. |
| `gateway.audience` | `SESAME_GATEWAY_AUDIENCE` | none | The `aud` claim the assertion must carry. Required for the gateway driver. |
| `gateway.leeway_seconds` | `SESAME_GATEWAY_LEEWAY_SECONDS` | `30` | Clock skew allowed on the time-claim checks, in seconds. |
| `gateway.trusted_proxies` | `SESAME_GATEWAY_TRUSTED_PROXIES` | none | Comma-separated CIDR ranges (or single IPs) the gateway proxy sits behind. Required for the gateway driver. |
| `session.idle_minutes` | `SESAME_SESSION_IDLE_MINUTES` | `120` | Drop the session after this much quiet time, in minutes. |
| `session.absolute_minutes` | `SESAME_SESSION_ABSOLUTE_MINUTES` | `720` | End the session this long after sign-in, in minutes. |
| `routes` | `SESAME_ROUTES` | `true` | Register the login and logout routes. |
| `user_resolver` | `SESAME_USER_RESOLVER` | none | The class that resolves a verified identity to the app's user. Required. |

The routes, when `routes` is true:

| Method | Path | Name |
|---|---|---|
| `GET` | `/login` | `sesame.login` |
| `POST` | `/logout` | `sesame.logout` |

Set `SESAME_ROUTES=false` when the app defines its own login and logout
routes. The `auth.sesame` middleware still points at the `sesame.login` route
name, so the app's login route must keep that name.

Protect a route with the `auth.sesame` middleware:

```php
Route::get('/dashboard', fn () => view('dashboard'))->middleware('auth.sesame');
```

A guest is sent to the login route; a JSON guest gets a 401.

The app's user is available through the facade:

```php
$user = app(\Waynestate\Sesame\Sesame::class)->user();
```

The cas driver needs a PSR-18 client. The adapter uses the app's
`Psr\Http\Client\ClientInterface` binding when there is one, Guzzle when it is
installed, and nothing when there is neither. Bind a client when the app has
neither:

```php
app()->singleton(
    Psr\Http\Client\ClientInterface::class,
    fn () => new \Symfony\Component\HttpClient\Psr18Client(),
);
```

## Running off Citadel

Open sesame opens the door on any host, not just Citadel. SESAME takes its
config from the app (env or `config/sesame.php`), keeps no files of its own,
and has no platform address in code, so a move to OpenShift, Coolify or raw
Docker is a config change, not a code change. What differs per host:

- The `cas` driver needs outbound HTTPS from the app to the CAS server, and
  `service_url` must be the public URL users see, behind any proxy.
- The `gateway` driver trusts only a signed assertion, never a bare header.
  A source address is not enough on its own: on shared ingress (OpenShift's
  router, Coolify's Traefik) the same proxy carries routes with and without
  the auth step. The header name and the list of trusted auth hops are config.
- The session store must be shared: Redis or a database, never local files.
  Every host overlaps old and new containers during a deploy, and most can run
  more than one replica, so a file session would drop users mid-login. In
  Laravel, set `SESSION_DRIVER` to `redis` or `database` (Laravel 13 already
  defaults to `database`); in raw PHP, set `session.save_handler` to a shared
  handler, for example Redis.

## Moving from wayne-cas

Apps that use wayne-cas can move to SESAME without rewriting their auth. The
shim keeps the wayne-cas names and behavior, and it says once (in the log)
that it is a deprecated bridge.

The mapping:

| wayne-cas | SESAME |
|---|---|
| `wayne-cas.root` | `sesame.cas.base_url` |
| `auth.providers.users.model` | `auth.providers.users.model` (unchanged) |
| `WayneCasable` trait | `implements WayneCasable` + `use ReadOnlyAttributes` |
| `WayneAuthenticate` middleware | the `wayne.cas` middleware alias |
| `auth()->login($user)` | `app(Sesame::class)->user()` |
| `use2FA` | not supported; the shim logs a warning when it is on |

The model. wayne-cas found the user through the model's static
`findOrImportUser`, with the verified username as the access ID. The shim
keeps that, and the model keeps its wayne-cas shape:

```php
class User extends Model implements WayneCasable
{
    use Notifiable, ReadOnlyAttributes;

    public static function findOrImportUser($accessId)
    {
        return static::firstOrCreate(['username' => $accessId]);
    }

    public function getReadOnlyAttributes($persistedAttributes = []): array
    {
        return [
            'cn' => $persistedAttributes['cn'] ?? null,
        ];
    }
}
```

The `WayneCasable` interface and the `ReadOnlyAttributes` trait are in the
`Waynestate\Sesame\WayneCas` namespace.

The middleware. wayne-cas's `WayneAuthenticate` middleware is kept as the
`wayne.cas` alias. It checks the SESAME session instead of the Laravel auth
guard, and it sends unauthenticated users to the `sesame.login` route. The
AJAX 403 is kept, because wayne-cas returned it.

The service URL. wayne-cas built the service URL from the current request.
SESAME takes `sesame.cas.service_url` from config; the Laravel adapter
defaults it to the app's `/login` route when it is not set.

`use2FA`. wayne-cas's `use2FA` runs a Duo challenge the shim does not carry.
When the app still has `wayne-cas.use2FA` switched on, the shim logs a warning
once and leaves the 2FA to the app.

## Tests

No PHP on the machine? Fine. Docker runs the suite:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

## Strongly recommended: a git.wayne.edu instance runner

git.wayne.edu has no runners today, so CI for this repo runs on the test
GitLab instance. Every product in the stack strongly recommends a
git.wayne.edu instance runner.
