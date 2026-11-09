---
title: Upgrading
description: How to move between SESAME versions.
---

# Upgrading

How to move between SESAME versions. Each section below is one released
version from the changelog that carries a break; sections without a break say
so in one sentence and give the upgrade command. Start at the version you are
on, work down toward the one you want, and run the checks after each hop.

## 0.7.0 (2026-11-09)

One new check that can need a step, and one that can stop an app that asked
for hard stops. Laravel apps are not affected: the Laravel adapter uses
Laravel's session, not `NativeSession`.

- **`NativeSession` (raw PHP, and the Wayne Auth bridge) checks the session
  cookie's flags.** When the active session's cookie lacks `HttpOnly`, lacks
  `Secure` on an https request (`HTTPS` set, or `X-Forwarded-Proto: https`),
  or has a `SameSite` other than `Lax` or `Strict`, the first use in a request
  raises one `E_USER_WARNING` that names the missing flags and the fix, and
  the session keeps working. SESAME still never sets a flag itself. PHP's own
  defaults trip it (`session.cookie_httponly` off, `session.cookie_samesite`
  empty), and so does Squire's kit `php.ini` before Squire 0.10.2, which set
  `HttpOnly` and `Secure` but left `SameSite` empty. An app that calls HELMET's
  `helmet_boot()` before `session_start()` already sets all three and sees
  nothing.
- **With `checkSessionSettings: true` it is a hard stop.** An app that passes
  `checkSessionSettings: true` to `new NativeSession()` and starts its session
  without those flags now gets a `LogicException` on every call, the same way
  it already did for strict mode. Set the flags before you deploy 0.7.0.

### Session cookie warning

The warning starts with `SESAME: the session cookie is sent without` and names
the missing flags. Which fix you need depends on how the app runs:

- **Squire kit 0.10.2 or later.** Run `vendor/bin/squire update` and commit
  the new `.squire/php.ini`; it sets `session.cookie_samesite = Lax` next to
  the `HttpOnly` and `Secure` lines it already had. Nothing else to do. The
  kit's `php.ini` covers local parity and the portable image; where the host
  supplies its own `php.ini`, read the third case.
- **Laravel.** Unaffected. The Laravel adapter hands SESAME Laravel's session
  (`LaravelSession`), never `NativeSession`, and Laravel writes its own
  session cookie from `config/session.php` (`http_only`, `secure`,
  `same_site`), so the check never runs.
- **Raw PHP on a host whose `php.ini` you do not control.** Call
  `helmet_boot()` before `session_start()` (HELMET sets strict mode and all
  three flags), or add these three lines before `session_start()`:

  ```php
  ini_set('session.cookie_httponly', '1');
  ini_set('session.cookie_secure', '1');
  ini_set('session.cookie_samesite', 'Lax');
  ```

  The one-line `session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Lax']);`
  that the warning prints does the same.

`Lax` keeps CAS, OIDC and SAML working: the CAS ticket and the OIDC code come
back on a top-level GET, which carries a `Lax` cookie, and the SAML driver
parks its cross-site POST and finishes the sign-in on the GET that follows.

**Steps.**

1. `composer require waynestate/sesame:^0.7` (SESAME is below 1.0, so `^0.6`
   does not take 0.7.0).
2. Set the cookie flags before `session_start()` as the case above for your
   app says, or set `session.cookie_httponly = 1`, `session.cookie_secure = 1`
   and `session.cookie_samesite = Lax` in a `php.ini` or FPM pool you control.
   Keep `Lax`, not `Strict`: the identity provider sends the browser back with
   a cross-site navigation, and a `Strict` cookie is not sent on it, so every
   sign-in would fail. SESAME lets `Strict` through, so it will not warn you.
3. To stay quiet without fixing the flags (not recommended), pass
   `checkSessionSettings: false`; it also turns off the strict mode settings
   and their warning.

**How to verify.** Sign in and open a protected page: the PHP error log has
no `SESAME: the session cookie is sent without` line. In the browser's
developer tools the session cookie shows `HttpOnly`, `Secure` (on https) and
`SameSite=Lax`. An app with `checkSessionSettings: true` signs in without a
`LogicException`.

## 0.6.3 (2026-11-09)

No breaks. One fix:

- **The wayne-cas shim works with JSON sessions.** When `config/session.php`
  sets `'serialization' => 'json'`, the session hands the stored user back as
  an array. The shim now reads the array's primary key and looks the user up
  through `query()->find()`, so a deleted account signs the session out. On
  0.6.2 it passed the array through, and the guard saw nobody while the
  middleware let the request in. An app that kept its own `refreshUser()` for
  JSON sessions can delete it and set `user_resolver` back to
  `Waynestate\Sesame\WayneCas\UserResolver`.

**Steps.** `composer update waynestate/sesame` (the `^0.6` constraint already
allows 0.6.3).

**How to verify.** On a wayne-cas app with JSON sessions, sign in and open a
second page: `Auth::user()` is the app's model, not `null`. Delete that
account's row and reload: the session is signed out.

## 0.6.2 (2026-11-09)

No breaks. Two fixes and one new warning:

- **The wayne-cas shim works with an Eloquent user model.** Its `refreshUser()`
  looked for a declared static `find` method, which an Eloquent model does not
  have, so every signed-in request failed with `InvalidConfig`. It now looks an
  Eloquent model up through `query()->find()`, and keeps the static `find`
  path for any other class. An app that wrote its own resolver subclass (or
  its own `refreshUser()`) only to get past that error can delete it and set
  `user_resolver` back to the shim's `Waynestate\Sesame\WayneCas\UserResolver`,
  unless its `config/session.php` sets `'serialization' => 'json'`: on 0.6.2
  the shim passes a JSON session's array user through unchanged, so that app
  keeps its own `refreshUser()` until 0.6.3, whose shim looks the array user
  up by its primary key.
- **`NativeSession` (raw PHP) turns on `session.use_strict_mode` and
  `session.use_only_cookies`** whenever no session is active. An app that
  starts its session with either one off gets one `E_USER_WARNING` per request
  that names the setting and the fix; the session keeps working. Put
  `ini_set('session.use_strict_mode', '1')` before `session_start()`, or call
  HELMET's `helmet_boot()`. Pass `checkSessionSettings: true` to
  `new NativeSession()` for a hard stop, or `false` to leave the settings
  alone. Laravel apps are not affected.
- **`NativeSession::regenerate()` keeps the ID it made** under strict mode, so
  the old session's marker names the new session again.

**Steps.** `composer update waynestate/sesame` (the `^0.6` constraint already
allows 0.6.2).

**How to verify.** A wayne-cas app signs in, then opens a second page while
signed in with no `InvalidConfig` in the log. A raw PHP app shows no
`E_USER_WARNING` about `session.use_strict_mode` in its PHP error log after a
sign-in.

## 0.6.1 (2026-11-08)

No breaks. SESAME's routes now point at `SesameController` methods instead of
closures, with the same names, URIs and middleware, so `php artisan route:cache`
and `php artisan optimize` work in an app that uses SESAME. A Citadel release
runs `optimize`, so an app that turned it off for SESAME (Squire's
`SQUIRE_ARTISAN_OPTIMIZE=0`) can turn it back on.

**Steps.** `composer update waynestate/sesame` (the `^0.6` constraint already
allows 0.6.1).

**How to verify.** `php artisan route:cache` exits 0, `php artisan route:list
--name=sesame` lists the routes as `SesameController` actions, and sign-in and
sign-out work as before. Then `php artisan route:clear` if you cache routes
only at release time.

## 0.6.0 (2026-11-08)

**Breaking.** Nothing for an app that keeps its driver: the `saml` driver, OAuth2
token introspection and the logged-out session marker are additions, each off
until it is configured. One install-time catch: `composer.json` now conflicts
with `onelogin/php-saml` below 4.3.2 and `robrichards/xmlseclibs` below 3.1.5
(the security floor for the SAML libraries), so an app that already has an older copy of either cannot take
0.6 until it updates them.

**Deprecated.** Nothing is deprecated in this release.

**Steps.**

1. Update the package (and, if Composer reports the conflict above, the SAML
   libraries with it):

   ```
   composer require waynestate/sesame:^0.6
   ```

2. Optional: turn on what is new. The `saml` driver is in the README driver
   table; introspection for opaque OAuth2 tokens is `oauth2.introspection_url`
   (env `SESAME_OAUTH2_INTROSPECTION_URL`).

**How to verify.** Sign in and out once with the driver the app uses; both work
as before.

## 0.5.0 (2026-11-07)

**Breaking.** The `cas` driver now binds each sign-in to the browser that started it: before the redirect to CAS it stores a pending-login marker in the app's session, and a returning ticket is accepted only against a live marker (single use, ten minutes). A ticket with no live marker is refused before any HTTP call to the CAS server, which blocks login CSRF. Two consequences for apps on the `cas` driver:

1. Apps that build the driver by hand must pass the app's `SessionPort` as the seventh argument to `DriverFactory::make()`; without it the driver refuses to start with `InvalidConfig` unless the binding is switched off. The Laravel adapter passes the app's session itself, so Laravel apps need no code change.
2. An app that accepts a ticket for a login it did not start (a link from the CAS login page) must set `cas.require_pending_login` (env `SESAME_CAS_REQUIRE_PENDING_LOGIN`) to `false`; that reopens login CSRF, so keep the opt-out deliberate.

**Deprecated.** Nothing is deprecated in this release.

**Added.** `gateway.jwks_file` (env `SESAME_GATEWAY_JWKS_FILE`) reads the gateway's public keys from the JWKS file Drawbridge publishes. Nothing changes for an app that keeps `gateway.public_key`. An app on a one-entry `gateway.public_keys` map now also accepts an assertion with no `kid`.

**Changed.** On Laravel 13, implement `RefreshesUser` in the app's resolver: Laravel 13 stores sessions as JSON, so the stored user comes back as an array, and the method must look the user up again; see [Reading the user](guides/laravel.md#reading-the-user).

**Steps.**

1. Move the constraint and update:

   ```
   composer require waynestate/sesame:^0.5
   ```

2. If the app builds the `cas` driver by hand (raw PHP), pass the app's session as the seventh argument to `DriverFactory::make()`:

   ```php
   $driver = DriverFactory::make($config, $http, new Psr17Factory(), null, null, null, new NativeSession());
   ```

3. Only if the app must accept a ticket for a login it did not start (a link from the CAS login page), set `cas.require_pending_login` to `false` (env `SESAME_CAS_REQUIRE_PENDING_LOGIN`).

**How to verify.** Sign in through CAS the normal way (the app sends the browser to CAS, the browser returns with the ticket); the sign-in succeeds and the marker is spent. Then deliver a just-issued ticket from a browser profile that never started a sign-in: it is refused with `This sign-in was not started in this browser, or it took too long. Start again from the app.`, and no HTTP call reaches the CAS server. `tests/CasDriverTest.php` covers all of it under the frozen names `testLoginBinding*`.

## 0.4.0 (2026-11-03)

**Breaking.** The `gateway` driver now refuses a token whose `typ` header is
`at+jwt` or `application/at+jwt`, so an OAuth2 access token cannot be replayed
as a gateway assertion. A missing `typ` or `JWT` still works. This is a
pre-1.0 minor: apps that pin `^0.3` stay on 0.3.x until they move their
constraint to `^0.4`.

**Deprecated.** Nothing is deprecated in this release.

**Steps.**

1. Move the constraint and update:

   ```
   composer require waynestate/sesame:^0.4
   ```

2. If your app sends OAuth2 access tokens to a gateway endpoint, stop: those
   tokens are refused now by design. Route API calls through the `oauth2`
   driver instead.
3. Optional new pieces you can adopt now: `session_ref`, a per sign-in
   correlation id that ties the auth events of one sign-in together in the
   log; `Identity::isFromNewLogin()` and `Identity::usedLongTermToken()` for
   CAS; optional PSR-3 logging of authentication events (`sesame.log`, env
   `SESAME_LOG`, default `true`); `oauth2.require_at_jwt` (env
   `SESAME_OAUTH2_REQUIRE_AT_JWT`), off by default because Microsoft Entra v2
   access tokens are typed `JWT`; and `gateway.public_keys`, a map of `kid`
   to public key for key rotation without a coordinated window.

**How to verify.** Run the gate:

```
composer ci
```

then sign in through the gateway with a normal assertion (missing `typ` or
`JWT`) and confirm a replayed `at+jwt` token is refused.

## 0.3.0 (2026-10-30)

**Breaking.** The signed-in user is looked up again on every request when the
app's resolver implements the new `RefreshesUser` interface, and a user who
no longer exists signs the session out. Before, `Sesame::user()` returned the
copy saved at sign-in, so a user whose role was removed kept it until the
session ended. The wayne-cas `UserResolver` implements it (a plain find by
primary key, never an import). Also fixed in this release: the wayne-cas
`WayneAuthenticate` middleware used to send `/login` to itself forever, and
resolving SESAME used to build the driver, which broke guests on http,
artisan commands, queue workers and tests.

**Deprecated.** Nothing is deprecated in this release.

**Steps.**

1. Update the package:

   ```
   composer require waynestate/sesame:^0.3
   ```

2. If the app reads the user through `auth()->user()`, point the guard at the
   new `sesame` driver: set `auth.guards.web.driver` to `sesame` and the users
   provider to `eloquent`. A Laravel login, `onceUsingId()` or `actingAs()`
   still wins for the request.
3. Check the user model: it needs the exact method
   `public function getReadOnlyAttributes($persistedAttributes = []): array`
   (public, with the parameter), since the wayne-cas `WayneCasable` interface
   and `ReadOnlyAttributes` trait now agree on one signature.

**How to verify.** Sign in, then remove a user's role (or the account) while
the session is live; the next request reflects the change, and a deleted user
is signed out. `tests/Laravel/SesameGuardTest.php` and
`tests/Laravel/ReadOnlyAttributesTest.php` cover the guard and the model
shape.

## 0.2.2 (2026-10-29)

**Breaking.** The oidc driver's default roles claim is `wsu_roles`, the name
Entra sends. Apps that relied on the old default must set `roles_claim` (and
`claims`) to `wsuRoles` to keep the old name.

**Deprecated.** Nothing is deprecated in this release.

**Steps.**

1. Update the package:

   ```
   composer require waynestate/sesame:^0.2
   ```

2. If the app reads roles from the oidc identity under the old name, add
   `oidc.roles_claim` (env `SESAME_OIDC_ROLES_CLAIM`) set to `wsuRoles`, and
   the same for `oidc.claims` (env `SESAME_OIDC_CLAIMS`).

**How to verify.** Sign in with the `oidc` driver and read
`$identity->attributes['wsu_roles']` (or the name you configured); the roles
arrive under the claim you named.

## 0.2.1 (2026-10-29)

No breaks in this release: it adds the agent-ready site from Squire's kit,
fixes the oauth2 peg on the site, writes project names as words except
acronyms, fixes stale capability claims, and shows the oidc driver on the
site. Upgrade with:

```
composer require waynestate/sesame:^0.2
```

## 0.2.0 (2026-10-29)

**Breaking.** Raw PHP: `sesame_logout()` is POST-only with a CSRF token. It
runs only when the POST body's `sesame_logout_token` matches the session's
token (new `sesame_logout_token()`, `Sesame::logoutToken()` and
`Sesame::isLogoutToken()`); a GET answers 405 and a bad token 403, and neither
touches the session. Apps must change a logout link to a POST form carrying
the token. The Laravel `POST /logout` route stays in the `web` group and so
under Laravel's CSRF check. Also changed: `ReturnTo` (every driver's login and
logout return-to) now requires the service URL's scheme, host and effective
port, and refuses a URL with a user name or password; before, the host alone
was compared, so `https://app.example.test:8443/` passed for a service on 443.
Plain http is accepted only for a localhost or 127.0.0.1 service URL. And
`DriverFactory::make()` takes the app session as an optional seventh argument
(the oidc driver needs it).

**Deprecated.** Nothing is deprecated in this release.

**Steps.**

1. Update the package:

   ```
   composer require waynestate/sesame:^0.2
   ```

2. In raw-PHP apps, replace the logout link with a POST form:

   ```php
   <form method="post" action="/logout.php">
     <input type="hidden" name="sesame_logout_token" value="<?= sesame_logout_token() ?>">
     <button>Sign out</button>
   </form>
   ```

3. If the app passes return-to addresses, make sure they match the service
   URL's scheme, host and port, and carry no user name or password.
4. If you use the new `oidc` driver, pass the app session as the seventh
   argument to `DriverFactory::make()`, and install
   `league/oauth2-client`.

**How to verify.** A GET to the logout endpoint answers 405 and leaves the
session alone; a POST without the token answers 403; a POST with the token
signs out. `tests/ReturnToTest.php` covers the tightened return-to rule.

## 0.1.3 (2026-10-29)

No breaks in this release: the four shared WSU values
(`SESAME_CAS_BASE_URL`, `SESAME_GATEWAY_ISSUER`, `SESAME_OAUTH2_ISSUER`,
`SESAME_OAUTH2_JWKS_URL`) now resolve through
`Waynestate\Sesame\Support\Setting::get()` (the environment wins, and when it
is empty the private `waynestate/playbook` package supplies the value,
suggested, never required), and a missing routed setting now fails with
`Missing required setting SESAME_.... Set it in the environment, or install
waynestate/playbook (see the README).` instead of naming the config key.
Upgrade with:

```
composer require waynestate/sesame:^0.1
```

## 0.1.2 (2026-10-29)

No breaks in this release: the CAS driver reads `serviceValidate` responses by
namespace URI instead of prefix, so a body that binds the `cas:` prefix to a
different namespace can no longer be read as a success, while a body that
binds another prefix (or none) to the real CAS namespace keeps working.
Upgrade with:

```
composer require waynestate/sesame:^0.1
```

## 0.1.1 (2026-10-29)

**Breaking.** A truthy `slo` (or `single_logout`) config key is now rejected
with `InvalidConfig` at boot: the identity provider does not support single
logout, so the setting is a mistake to fail on early rather than at the first
logout. An app that sets it must remove the key or it will not boot.

**Deprecated.** Nothing is deprecated in this release.

**Steps.**

1. Update the package:

   ```
   composer require waynestate/sesame:^0.1
   ```

2. Remove any `slo` (or `single_logout`) key from the config.
3. New in this release, if you want them: step-up by authentication context
   (`sesame_require_context(string $id)`, the `sesame.context` Laravel
   middleware, `Identity::hasAuthContext(string $id)`), `cas.assume_mfa`
   (default `false`), the `oauth2` driver with its config keys, and
   `InvalidConfig` naming the key when `gateway.public_key`,
   `gateway.issuer` or `gateway.audience` is null or empty.

**How to verify.** Boot the app; it starts without `InvalidConfig`, and a
context check fails closed when there is no user or no identity.
`tests/Laravel/RequireAuthContextTest.php` covers the context check.

## 0.1.0 (2026-10-27)

First tagged release. There is nothing to upgrade from. Install with:

```
composer require waynestate/sesame
```

## Related

- [Install](install.md): the requirements and the one composer line.
- [Quick start](guides/quick-start.md): a first sign-in after you upgrade.
- [Configuration](reference/configuration.md): every `SESAME_*` setting, including the generated environment reference.
- [Troubleshooting](troubleshooting.md): what to try when a sign-in fails.
