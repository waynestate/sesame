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

// Sends the redirect to the identity provider and stops the script, so the
// line below runs only when the login has completed.
sesame_login();

echo 'Signed in as '.sesame_user();
```

The app owns the session: start it with `session_start()`, and let HELMET set
the cookie flags. SESAME regenerates the session ID on login and stores the
user and identity in it, but it never starts a session and never touches a
cookie. `sesame_login()` and `sesame_logout()` stop the script after they send
their redirect, so nothing after them runs in that request.

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
