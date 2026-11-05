---
title: "The cas driver | SESAME docs"
description: "Sign in against a CAS server, CAS 2.0 against Academica by default, with no per-app Entra registration."
url: https://sesame.apps.wayne.edu/docs/guides/driver-cas.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# The cas driver[#](https://sesame.apps.wayne.edu/docs/guides/driver-cas.html#the-cas-driver)

This page sets up the `cas` driver, which signs a user in against a CAS server, CAS 2.0 against Academica by default, with no per-app Entra registration. Start with the config below, then read the behavior notes for the options you plan to change.

![SESAME's key duplicator tracing the cas key: five notches, one per sign-in step, and logout at the tip](https://sesame.apps.wayne.edu/docs/readme/duplicator.svg)

The duplicator traces the cas key from the front page: five notches, one per sign-in step, and logout at the tip. The cut is the sign-in; the lock is still yours. With reduced motion on, you get the finished key.

## Configure the driver[#](https://sesame.apps.wayne.edu/docs/guides/driver-cas.html#configure-the-driver)

The cas driver logs in against a CAS server, CAS 2.0 by default. The base URL is required config with no default, and it must include the path the CAS server lives under. For Academica, which serves CAS under `/cas`, it is `https://login.wayne.edu/cas`.

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
        'base_url' => 'https://login.wayne.edu/cas',
        'service_url' => 'https://app.apps.wayne.edu/login',
    ],
]);

// Any PSR-18 client works, for example Guzzle. Set a timeout: Guzzle has no
// total timeout by default, so a hung CAS server would hold the request open
// forever. Turn redirects off for the validation call (Guzzle follows up to
// five by default, to any host or to plain http) and leave TLS verification
// on (`verify` is true by default; never set it to false). PSR-18 has no
// standard option for either, so the client you hand SESAME must carry them.
// SESAME treats any non-200 answer (including a redirect) as a failed login
// and lets a client exception through, so a client that is set up wrong fails
// the sign-in and never signs anyone in.
$http = new \GuzzleHttp\Client([
    'timeout' => 10,
    'connect_timeout' => 5,
    'allow_redirects' => false,
    'verify' => true,
]);

$driver = DriverFactory::make($config, $http, new Psr17Factory());
```

Then build the Sesame facade with the driver, as in the Raw PHP section of the README.

## Config keys[#](https://sesame.apps.wayne.edu/docs/guides/driver-cas.html#config-keys)

| Key | Required | Default | Meaning |
| --- | --- | --- | --- |
| `cas.base_url` | Yes | none | The CAS server, an absolute https:// URL, including the path the CAS server lives under (for Academica, `/cas`). |
| `cas.service_url` | Yes | none | Where the server sends the user back to. |
| `cas.protocol` | No | `2.0` | `3.0` or `2.0`. |
| `cas.renew` | No | `false` | Send `renew` on login and validation, so the CAS server requires a fresh, direct login. |
| `cas.assume_mfa` | No | `false` | A successful validation counts as an MFA sign-in: the identity's `amr` includes `mfa`. On only when the CAS server enforces MFA for every sign-in. |

The service URL is the configured value only: the driver never reads the request's Host or X-Forwarded-\* headers to build it.

## Protocol[#](https://sesame.apps.wayne.edu/docs/guides/driver-cas.html#protocol)

Protocol 2.0 is the default because Academica is believed to redirect cookieless `/p3/serviceValidate` calls to the login page. Set `cas.protocol` to `3.0` when a CAS server releases attributes on `/p3/serviceValidate`.

## Renew[#](https://sesame.apps.wayne.edu/docs/guides/driver-cas.html#renew)

Set `cas.renew` to `true` when the app needs a fresh, direct login rather than an existing SSO session. The driver then sends `renew` on the login redirect and on the validation request, and the CAS server refuses a ticket that came from an SSO session with `INVALID_TICKET`. Leave it off against Academica: it does not issue a ticket for `renew=true` and sends the user back to the login page, so a `renew` login loops instead of signing in.

## Sign-in time[#](https://sesame.apps.wayne.edu/docs/guides/driver-cas.html#sign-in-time)

The sign-in time on the identity comes from the CAS `authenticationDate` attribute when the server sends one. When it is missing, unparseable, or more than five minutes ahead of the driver's clock, the driver uses its own clock instead, so a bad value can never push the sign-in time into the future.

With `cas.protocol` set to `3.0`, a server may also release `isFromNewLogin` and `longTermAuthenticationRequestTokenUsed`. The identity reads them as `$identity->isFromNewLogin()` and `$identity->usedLongTermToken()`: `true` or `false` when the server said so (any letter case), and `null` when the attribute is missing or is anything but `true` or `false`. SESAME never guesses, so an app that wants a fresh login for a sensitive action can check `isFromNewLogin() === true` and send the user back through `cas.renew` when it is anything else.

## MFA[#](https://sesame.apps.wayne.edu/docs/guides/driver-cas.html#mfa)

`cas.assume_mfa` is off by default, and it should stay off unless the CAS server enforces MFA for every sign-in. A successful CAS validation then means MFA happened for the user's current SSO session, and the driver says so on the identity: its `amr` includes `mfa`. Ask your CAS server's owner before turning it on; a server that enforces MFA only sometimes, or only for some users, would make the MFA check pass for sign-ins that never did MFA.

The MFA age still needs a sign-in time, and the driver never invents one: it comes from the CAS `authenticationDate` attribute, and when the server does not send one, the MFA check fails because there is no sign-in time to age.

Warning

On a failed login, show a generic line, not the exception. An `AuthenticationFailed` message can carry the CAS failure code (for example `INVALID_TICKET`), which tells an attacker how the server refused the ticket. Log the message for operators and show the user one generic line instead.

## Related[#](https://sesame.apps.wayne.edu/docs/guides/driver-cas.html#related)

- [Running off Citadel](https://sesame.apps.wayne.edu/docs/guides/running-off-citadel.html)
- [The gateway driver](https://sesame.apps.wayne.edu/docs/guides/driver-gateway.html)
