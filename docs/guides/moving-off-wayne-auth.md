---
title: Moving off Wayne Auth
description: Apps that sign people in through the old Wayne Auth SSO cookie can move to SESAME's CAS sign-in by changing one line, through a bridge that keeps the old WayneAuth class's calls.
---

# Moving off Wayne Auth

Older WSU PHP apps sign people in through **Wayne Auth**: a small PHP class reads the SSO cookie and checks it with the Wayne Auth service over a socket. Wayne Auth is deprecated. When the service is retired, every app still calling it stops letting anyone in, whatever PHP version it runs. SESAME's Wayne Auth bridge turns that move into a one-line change: the app keeps its `new WayneAuth(...)`, `authenticateSSO()` and `getAccessID()` calls, and they sign people in through CAS instead. Open sesame, say the magic words you already know.

> [!WARNING]
> The bridge is deprecated on arrival. It exists so the move off Wayne Auth is small and provable today; plan the move to SESAME's native calls next (the last section). It says so once per process, as a silenced `E_USER_DEPRECATED` that reaches your error log but never the page.

## Does this fit my app?

The bridge keeps the API of the classic `WayneAuth` class, the one apps copy into `lib/` or next to their pages:

```php
$wayne_auth = new WayneAuth($config['wayneauth']);
if ($wayne_auth->authenticateSSO() == true) {
    $accessid = $wayne_auth->getAccessID();
} else {
    header('Location: ' . $config['auth']['login_url'] . $config['general']['url_root']);
    exit;
}
```

If your app looks like that, read on. Other Wayne Auth shapes need a hand move to `sesame_login()` (see [Raw PHP](raw-php.md)):

- An app that reads the `validUser` cookie itself and posts it to a cookie validator, or to the Wayne Auth HTTP gateway.
- An app that asks Wayne Auth for the user's **password** (`_parseSSOCookie('d')`, or a client built with `'d'`). CAS never gives an app the password, so no bridge can; that app needs a different design (a service account, or the target system's own SSO).

SESAME needs PHP 8.3 or newer. An app on older PHP moves its PHP first ([Booster](https://github.com/waynestate/booster) does that part).

## The one-line swap

1. Require SESAME with Composer (`composer require waynestate/sesame guzzlehttp/guzzle`). Guzzle is the HTTP client the cas driver uses to check tickets; any PSR-18 client works if you pass it yourself.
2. Add the app's SESAME config file, for example `config/sesame.php`:

```php
<?php

declare(strict_types=1);

use Waynestate\Sesame\WayneAuth\WayneAuth;

require_once __DIR__ . '/../vendor/autoload.php';

WayneAuth::boot([
    'driver' => 'cas',
    'environment' => 'production',
    'cas' => [
        'base_url' => 'https://login.wayne.edu/cas',
        'service_url' => 'https://app.apps.wayne.edu/',
    ],
]);
```

3. Change the one line that loaded the old class:

```php
// Before
require_once('lib/WayneAuth/WayneAuth.php');

// After
require_once('config/sesame.php');
```

That is the whole code change. `boot()` builds SESAME, registers it with `sesame()`, and makes the old global name `WayneAuth` point at the bridge, so every `new WayneAuth($config['wayneauth'])` in the app gets the bridge. An app that refers to the class by a `use` line can import `Waynestate\Sesame\WayneAuth\WayneAuth` instead. Delete the old class file once nothing loads it: `boot()` refuses to start while a different `WayneAuth` class is loaded, so a half-done swap fails loudly instead of quietly keeping Wayne Auth.

The `cas.service_url` is where CAS sends people back: the page that calls `authenticateSSO()` (or one that every page passes through). After the sign-in, people land there, the way Wayne Auth's `forward_url` sent them to the app's root. With [Playbook](wsu-values.md) installed, or `SESAME_CAS_BASE_URL` set, `base_url` can be left out.

### The session

The app owns the session, as everywhere in SESAME: call `session_start()` before the first `authenticateSSO()`. Wayne Auth needed no session because the SSO cookie was the session; CAS needs one to remember who signed in. Most Wayne Auth apps already start one. Turn on strict mode first: without `session.use_strict_mode` PHP accepts any session ID a client sends, and SESAME raises one `E_USER_WARNING` per request until it is on. Call `ini_set('session.use_strict_mode', '1')` before `session_start()`, or let HELMET's `helmet_boot()` do it with the cookie flags ([Raw PHP](raw-php.md) has the settings). The cookie flags matter too: from 0.7.0 SESAME also raises one warning per request when the session cookie lacks `HttpOnly`, `Secure` (on https) or `SameSite=Lax`, which PHP's defaults leave off, so add `session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Lax'])` before `session_start()` if `helmet_boot()` is not there. Also set `session.gc_maxlifetime` to at least the idle timeout.

Legacy apps usually start the session before Composer's autoloader is loaded. That is fine: `boot()` re-reads SESAME's values in the session once the autoloader is there, so nobody looks signed out.

### Options

`boot()` takes a few more arguments, all optional:

| Argument | Default | What it does |
|---|---|---|
| `$http` | Guzzle, 10 second timeout, redirects off | The PSR-18 client for the ticket check |
| `$requests` | nyholm/psr7's factory | The PSR-17 request factory |
| `$resolver` | the verified username | Your `UserResolver`, when the app wants its own user object stored in the session |
| `$options['on_guest']` | `redirect` | `redirect` sends a visitor who is not signed in to CAS from inside `authenticateSSO()`; `deny` answers `false` the way Wayne Auth did, so the app's own else branch runs |
| `$options['accessid_case']` | `asis` | `upper` or `lower` when the app compares the AccessID against a list written in one case |
| `$logger` | none | A PSR-3 logger for SESAME's sign-in events |

With `on_guest` left at `redirect`, the app's old else branch (the redirect to the Wayne Auth login page) never runs again, so the old login URL in its config stops mattering. With `deny`, point that login URL at a page of your own that calls `sesame_login()`.

## What changes, call by call

| Wayne Auth | The bridge |
|---|---|
| `new WayneAuth($config)` | Same. The old keys (`sso_host`, `sso_port`, `ldap_host`, `timeout` and the rest) are accepted and ignored; `$wayne_auth->config` still holds them |
| `authenticateSSO()` true | Same: someone is signed in. SESAME's idle (120 minutes, 2 hours) and absolute (12 hours) timeouts apply |
| `authenticateSSO()` false for a visitor with no cookie | Sends the visitor to CAS and stops the script (`on_guest => redirect`), or `false` (`on_guest => deny`) |
| (nothing) | A visitor whose session timed out (2 hours quiet, or 12 hours since the sign-in) is a guest again: the old session is retired, a fresh one takes its place, and the visitor goes through CAS as above (before 0.7.1 this was a blank 500) |
| (nothing) | A guest's script request (an XHR save, or a `fetch` asking for JSON) gets a 401 with `{"signedIn":false}` and the script stops, instead of a redirect to CAS the script cannot follow |
| (nothing) | A visitor coming back from CAS with a ticket: the ticket is checked, the user is stored, and the browser is sent on to the service URL without the ticket |
| (nothing) | A ticket that does not check out throws `AuthenticationFailed`. Answering `false` would send the browser to CAS and back forever |
| (nothing) | CAS down, unreachable or slower than the 10 second timeout while it checks the ticket: throws `ProviderUnavailable`, a subclass of `AuthenticationFailed` (see the next section) |
| `false` for a refused visitor | Your resolver returned `null`: `false`, nothing stored |
| `getAccessID()` | The CAS username, the same AccessID. `$wayne_auth->accessid` is set too |
| `_parseSSOCookie('v')` | `['verified' => true, 'accessid' => ..., 'timeout' => seconds left]`, or `['verified' => false]`; never redirects |
| `_parseSSOCookie('d')` (the password) | Throws `LogicException`. CAS never gives an app the password |
| Signing out through the SSO logout page | A POST form to a page that calls `sesame_logout()` ([Raw PHP](raw-php.md#logout)); it ends the app's session and sends the browser to the CAS logout |

Wayne Auth told an app the AccessID and nothing else, so nothing an app read from it is lost. CAS can release more (name, email, groups) as attributes: those arrive in the `Identity` your resolver receives, once you move to the native calls.

## Catch the two sign-in exceptions

`authenticateSSO()` throws in two cases, and an uncaught one is a blank 500. Wrap the call where the page makes it, and catch the narrower class first:

```php
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\ProviderUnavailable;

try {
    $signedIn = $wayne_auth->authenticateSSO();
} catch (ProviderUnavailable $e) {
    // CAS is down, unreachable, or did not answer within the timeout.
    error_log('Sign-in: ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 60');
    echo 'The university sign-in is not answering. Try again in a minute.';
    exit;
} catch (AuthenticationFailed $e) {
    // The ticket was refused, or the sign-in was not started in this browser.
    error_log('Sign-in: ' . $e->getMessage());
    http_response_code(403);
    echo 'The sign-in did not go through. Open the app again to retry.';
    exit;
}
```

- `ProviderUnavailable` means nobody could be checked: no route to CAS, a TLS failure, the 10 second timeout ran out, or CAS answered 5xx. Nobody is signed in. The visitor can retry from the app; the ticket in the address bar is spent, so a reload of that exact URL gets the second case.
- `AuthenticationFailed` means CAS (or SESAME) refused the sign-in. Its message can carry the CAS failure code, so log it and show one generic line.

Since `ProviderUnavailable` extends `AuthenticationFailed`, a page that catches only `AuthenticationFailed` still catches both; the first catch is what turns "CAS is down" into a 503 instead of a refusal.

A session that timed out is not an exception: from 0.7.1 the visitor is simply sent through CAS again (or a script gets its 401). An app that added `catch (\LogicException $e)` around `authenticateSSO()` as a stopgap for 0.7.0 can drop it after the upgrade.

## Check it before you ship

1. On a laptop, boot with the `fake` driver (`'driver' => 'fake', 'environment' => 'local', 'fake' => ['username' => 'ab1234']]`): every page should say who is signed in without any CAS server.
2. On pre-production, with the real `cas` config: open a page in a private window. You should land on the CAS sign-in, come back to the service URL with no `ticket` in the address bar, and see the app signed in as you. Check the AccessID's case against any `authorized_ids` list the app keeps.
3. Leave the tab alone past the idle timeout (2 hours) and reload: you should go through CAS again (silently, if your CAS session is still alive), never a blank page. A save the page makes by XHR after the timeout should get a 401, not a 500.
4. Grep the app for `validUser`, `sso_host` and `_parseSSOCookie('d')`: none should be left doing work.

SESAME's own tests do the same end to end: a fixture page written the Wayne Auth way signs in through the bridge under PHP's built-in server, with a fake CAS answer (`tests/WayneAuthLegacyAppTest.php`).

## Going native later

The bridge is three thin calls over SESAME's raw-PHP functions. When you have a quiet afternoon:

| Bridge | Native |
|---|---|
| `require_once('config/sesame.php')` with `WayneAuth::boot()` | the same file, building `Sesame` as in [Raw PHP](raw-php.md) and calling `sesame($sesame)` |
| `if ($wayne_auth->authenticateSSO()) { ... } else { redirect }` | `$user = sesame_login();` (it sends guests to CAS and stops the script) |
| `$wayne_auth->getAccessID()` | the username your `UserResolver` received, or `sesame()->sessionStatus()['username']` |
| `authorized_ids` checks after the sign-in | your resolver returns `null` for anyone not allowed, so they are never signed in |

Then delete the config line that calls `boot()`, and the bridge is gone.

## Related

- [Raw PHP](raw-php.md)
- [The cas driver](driver-cas.md)
- [Moving from wayne-cas](moving-from-wayne-cas.md)
- [Session timeouts](session-timeouts.md)
