---
title: "Raw PHP | Sesame docs"
description: "How a framework-free PHP app starts the session, builds the Sesame facade, signs the user in, and reads the user back."
url: https://sesame.apps.wayne.edu/docs/guides/raw-php.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Raw PHP[#](https://sesame.apps.wayne.edu/docs/guides/raw-php.html#raw-php)

This page shows how a framework-free PHP app boots SESAME, signs the user in, reads the user back, and signs the user out. Start with the boot example, then wire up your protected pages.

## Boot the app[#](https://sesame.apps.wayne.edu/docs/guides/raw-php.html#boot-the-app)

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

## Which key to store[#](https://sesame.apps.wayne.edu/docs/guides/raw-php.html#which-key-to-store)

Your `findOrImportUser` looks the person up in the app's own store, so key that store on the driver plus the identity provider's own stable identifier, never on an email or a display name, which can change or be shared between providers. For the cas driver that identifier is the `username` (the Access ID), so the key is `$identity->driver` plus `$identity->username`. Other drivers name their stable identifier differently: the oidc section keys on `iss` plus `sub`, or `tid` plus `oid`, never the username alone.

## Session ownership[#](https://sesame.apps.wayne.edu/docs/guides/raw-php.html#session-ownership)

The app owns the session: start it with `session_start()`, and let HELMET set the cookie flags. SESAME regenerates the session ID on login and stores the user and identity in it, but it never starts a session and never touches a cookie. `sesame_login()` and `sesame_logout()` stop the script after they send their redirect, so nothing after them runs in that request. A protected page reads the user with `sesame_user()`:

```php
$user = sesame_user();
```

> \[!WARNING\] **Set `session.gc_maxlifetime` to at least SESAME's idle timeout.** PHP's default is 1440 seconds (24 minutes), and PHP's garbage collector deletes session data older than that, so a raw-PHP app on the defaults signs people out after 24 quiet minutes, long before SESAME's 120-minute idle timeout. SESAME never starts the session (S2), so it cannot change this for you: set `session.gc_maxlifetime = 7200` (your `session.idle_minutes` times 60) in `php.ini` or the FPM pool, or call `ini_set('session.gc_maxlifetime', '7200')` before `session_start()`. SESAME's timeouts stay the authority; this only stops PHP from ending the session first. Laravel apps set `SESSION_LIFETIME` (minutes) instead.

## The session cookie (name and SameSite)[#](https://sesame.apps.wayne.edu/docs/guides/raw-php.html#the-session-cookie-name-and-samesite)

SESAME never sets a cookie, so two choices belong to the session setup. In raw PHP HELMET makes both for you (`SessionHardening::apply()`, called by `helmet_boot()`); in Laravel they are settings in `config/session.php`:

- **Name it with the `__Host-` prefix** (S4). The browser then accepts the cookie only if it is `Secure`, has `Path=/` and has no `Domain`, so a sibling subdomain under `*.apps.wayne.edu` cannot overwrite it. HELMET names the cookie `__Host-WSUSESSID` on HTTPS (it keeps a name your app chose). In Laravel, set `SESSION_COOKIE=__Host-wsu-session`, `SESSION_SECURE_COOKIE=true`, `SESSION_PATH=/` and leave `SESSION_DOMAIN` empty in `config/session.php`.
- **Keep it `SameSite=Lax`, never `Strict`** (S41). The identity provider sends the browser back to your app with a cross-site top-level `GET`, and a `Strict` cookie is not sent on that request: it would be missing, so the app would see no session on the return request and any login binding (S18) would fail for every user. `Lax` is what HELMET sets and what Laravel does by default. A cross-site `POST` carries no `Lax` cookie at all, which is the defense you want; a future `saml` driver would need its own short-lived cookie for that reason (S35).
- **Leave the session ID length alone** (S45). Do not set `session.sid_length` or `session.sid_bits_per_character`: PHP's default ID is 32 hexadecimal characters (128 bits), and on PHP 8.4 and later both settings are deprecated and raise notices. A low value weakens the ID. Neither SESAME nor HELMET sets or checks them.

On logout, both paths send `Cache-Control: no-store` and `Clear-Site-Data: "cache", "storage"` so a back button or a shared browser cannot show a signed-in page; `"cookies"` is left out on purpose, because it would clear every cookie on the host and sign the user out of every other app on wayne.edu, not just this one. Single logout is not supported, the identity provider cannot sign the user out of the app's other sessions, so SESAME rejects a truthy `slo` (or `single_logout`) config key at boot.

## Logout[#](https://sesame.apps.wayne.edu/docs/guides/raw-php.html#logout)

Logout is POST-only, with a CSRF token. `sesame_logout()` runs only for a POST whose body carries the session's `sesame_logout_token`; a GET answers 405 (`Allow: POST`) and a POST without the right token answers 403, and both leave the session alone. So another site cannot sign the user out with an image, a link or a forged form. Put the token in the sign-out form:

```php
<form method="post" action="/logout.php">
  <input type="hidden" name="sesame_logout_token" value="<?= sesame_logout_token() ?>">
  <button>Sign out</button>
</form>
```

```php
// logout.php
sesame_logout('https://app.example.test/');
```

The token lives in the session, is made on first use and dies with the session at logout. Laravel apps use Laravel's own CSRF token instead: the `POST /logout` route is in the `web` middleware group, so a form needs `@csrf`.

## Helper functions[#](https://sesame.apps.wayne.edu/docs/guides/raw-php.html#helper-functions)

The raw-PHP path is a set of plain functions in the global namespace behind a `sesame_` prefix, each guarded with `function_exists` so two copies of the file can never collide. The app owns the session: start it with `session_start()` before calling anything here, and let HELMET set the cookie flags.

| Function | What it does |
| --- | --- |
| `sesame(?Sesame $instance = null)` | Hold the one `Sesame` instance for this process: pass the instance to set it, pass nothing to read it. Throws `LogicException` when read before it is set. |
| `sesame_request()` | Build the PSR-7 request for the current request from the globals, using the `nyholm/psr7` factories the library already requires. |
| `sesame_login(): mixed` | Run a login against the current request. Sends the 302 and stops the script when the driver answers with a redirect; returns the app's user, or `null` when the resolver refused the identity. |
| `sesame_user(): mixed` | The app's user for the current session, or `null` when there is none. |
| `sesame_require_mfa(int $maxAgeSeconds = 28800): mixed` | Require that the signed-in user's MFA is still good. Returns the user when satisfied, throws `MfaNotSatisfied` otherwise. |
| `sesame_require_context(string $id): mixed` | Require that the signed-in identity has the given authentication context. Returns the user when it does, throws `AuthContextNotSatisfied` otherwise. |
| `sesame_logout_token(): string` | The logout CSRF token for this session, for the logout form. The token is hex, so it needs no escaping in HTML. |
| `sesame_logout(string $returnTo): void` | Log the user out, then send them on. Invalidates the app session, redirects to the identity provider's logout URL when the driver has one, otherwise to the safe return-to, and stops the script. |

## Logging[#](https://sesame.apps.wayne.edu/docs/guides/raw-php.html#logging)

The facade takes an optional sixth constructor argument: a PSR-3 logger (`Psr\Log\LoggerInterface`). When you pass one, SESAME writes one record per authentication event through it:

| Event | Level | When |
| --- | --- | --- |
| `sesame.login` | `info` | A verified identity resolved to a user and the session was stored. |
| `sesame.login_failed` | `warning` | The driver's login threw `AuthenticationFailed`. |
| `sesame.login_refused` | `warning` | The resolver refused the identity and returned null. |
| `sesame.logout` | `info` | The session was signed out (a logout, a timeout, or a deleted user). |
| `sesame.session_anomaly` | `warning` | The session state looks wrong or tampered with: `reason` is `missing_timestamps` or `bad_session_ref`. |

Each record carries `event`, `driver`, `username` and `at` (the time in UTC) in its context, and `sesame.logout` adds `reason` (`logout`, `idle_timeout`, `absolute_timeout`, `no_timestamps` or `user_gone`). `sesame.session_anomaly` adds `reason` (`missing_timestamps`, a user stored without the session's timestamps, or `bad_session_ref`, a stored reference that is not the 16-character lowercase hex value login created) and carries `session_ref` when the stored value is still a valid 16-character lowercase hex string; when you see one, look at the `session_ref` and the user around it in the log to see what happened to the session. `sesame.login` and `sesame.logout` also carry `session_ref`: an opaque 16-character hex reference SESAME creates on every login (stored in the session under `sesame.session_ref`) that ties the login, timeouts and logout of one sign-in together in the log. It is not the session ID and never derived from it, and a value read back from the session that is not a 16-character lowercase hex string is ignored, so a tampered session cannot inject log text. The logger stays optional: without one, nothing is logged and nothing else changes.

SESAME never logs tickets, session IDs, tokens or failure messages: a failed login records only the event, the driver and the time. Usernames have every control character replaced with `?` and are cut at 128 characters, so a username cannot forge an extra log line or flood the log. A logger that throws never changes what the facade returns or throws.

## Related[#](https://sesame.apps.wayne.edu/docs/guides/raw-php.html#related)

- [Which driver for my app?](https://sesame.apps.wayne.edu/docs/guides/which-driver.html)
- [WSU values (Playbook)](https://sesame.apps.wayne.edu/docs/guides/wsu-values.html)
