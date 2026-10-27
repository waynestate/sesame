# SESAME

SESAME is Wayne State's protocol-neutral auth library. It returns a verified
identity and leaves the session to the app: every driver ends in the same
place, the app's `findOrImportUser(Identity)`, and the protocol never holds
the session. Switching driver is a config change with no code change. Open
sesame.

## Install

```
composer require waynestate/sesame
```

## Raw PHP

```php
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Waynestate\Sesame\Drivers\FakeDriver;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\UserResolver;

session_start();

$sesame = new Sesame(
    new FakeDriver('local', 'jdoe', ['cn' => ['John Doe']]),
    new class implements UserResolver {
        public function findOrImportUser(Identity $identity): mixed
        {
            return $identity->username;
        }
    },
    new NativeSession(),
);

sesame($sesame);

sesame_login();

echo 'Signed in as '.sesame_user();
```

The app owns the session: start it with `session_start()`, and let HELMET set
the cookie flags. SESAME regenerates the session ID on login and stores the
user and identity in it, but it never starts a session and never touches a
cookie.

## Drivers

| Driver | Status |
|---|---|
| `fake` | Now. Local development and tests only. Refuses to boot in production. |
| `cas` | Now. CAS 3.0 against Academica. |
| `gateway` | Later. A signed assertion from the Citadel auth gateway, never a bare header. |
| `saml` | Later. Direct SP. |
| `oauth2` | Later. JWT bearer tokens. |

## The cas driver

The cas driver logs in against a CAS server, CAS 3.0 by default. The base
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
| `cas.protocol` | No | `3.0` | `3.0` or `2.0`. |

The service URL is the configured value only: the driver never reads the
request's Host or X-Forwarded-* headers to build it.

Protocol 3.0 is the default; whether Academica releases attributes on
`/p3/serviceValidate` is still to be verified, and `cas.protocol: 2.0` is
the fallback.

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
| `driver` | `SESAME_DRIVER` | `cas` | `cas` or `fake`. |
| `cas.base_url` | `SESAME_CAS_BASE_URL` | none | The CAS server, an absolute https:// URL. |
| `cas.service_url` | `SESAME_CAS_SERVICE_URL` | the app's `/login` route | Where the server sends the user back to. |
| `cas.protocol` | `SESAME_CAS_PROTOCOL` | `3.0` | `3.0` or `2.0`. |
| `fake.username` | `SESAME_FAKE_USERNAME` | none | The fake driver's username. |
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
docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
```

## Strongly recommended: a git.wayne.edu instance runner

git.wayne.edu has no runners today, so CI for this repo runs on GitLab. Every
product in the stack strongly recommends a git.wayne.edu instance runner. The
case for it is written up in `design/instance-runner-case.md` in the
coordination repo (`the stack`).
