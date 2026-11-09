---
title: Troubleshooting
description: What to try when a sign-in fails, and what the errors mean.
---

# Troubleshooting

Open sesame opens the door; this page is for when it does not. Each symptom
below names the cause the sources give, the fix, and a check you can run. If
your sign-in still fails after these, start at [Quick start](guides/quick-start.md)
with the `fake` driver to prove the wiring, then come back with the real
driver.

## Sign-in fails

### The oidc driver fails to build: `The oidc driver needs league/oauth2-client`

**Cause.** The `oidc` driver stands on `league/oauth2-client`, which SESAME
suggests but does not require. Without it, building the driver fails with
`The oidc driver needs league/oauth2-client. Install it with: composer require
league/oauth2-client`.

**Fix.** Install the library:

```
composer require league/oauth2-client
```

**Check.** Build the driver again; the error is gone and the sign-in starts.
The driver's behavior is covered by `tests/OidcDriverTest.php`.

### The saml driver fails to build: `The saml driver needs onelogin/php-saml`

**Cause.** The `saml` driver stands on `onelogin/php-saml`, which SESAME
suggests but does not require, and it refuses php-saml older than 4.3.2 or
xmlseclibs older than 3.1.5 (both had signature or encryption advisories).

**Fix.** Install or update the library:

```
composer require onelogin/php-saml
composer update onelogin/php-saml robrichards/xmlseclibs
```

**Check.** Build the driver again; the error is gone. The floor is covered
by `tests/SamlConfigTest.php`.

### A SAML sign-in fails with `saml.handle` or `saml.in_response_to`

**Cause.** The finish could not find what the start and the ACS left
behind. `saml.handle`: the parked response is gone (more than two minutes,
used twice, or a cache the web servers do not share). `saml.in_response_to`:
the session that started the sign-in is not the one finishing it (a
different browser, a session cookie set to `SameSite=Strict`, a session
started on the ACS page, or a sign-in older than ten minutes).

**Fix.** Use a cache store every web server shares
(`SESAME_SAML_CACHE_STORE`), keep the session cookie `SameSite=Lax`, and
never start the session on the ACS page (raw PHP) or put the ACS route in
the `web` group (Laravel).

**Check.** A fresh sign-in from the app's login URL completes. The flow is
covered by `tests/SamlDriverTest.php` and `tests/Laravel/SamlRouteTest.php`.

### A `cas` login loops back to the CAS login page

**Cause.** `cas.renew` is set to `true` against Academica. Academica does not
issue a ticket for `renew=true` and sends the user back to the login page, so
a `renew` login loops instead of signing in.

**Fix.** Leave `cas.renew` off (the default is `false`). Set it to `true` only
when the app needs a fresh, direct login rather than an existing SSO session,
and the CAS server issues tickets for it.

**Check.** Sign in with the default `cas.renew`; the ticket completes and the
user lands on the service URL. `tests/CasDriverTest.php` covers the cas
sign-in steps.

### CAS validation redirects to the login page, or attributes are missing

**Cause.** `cas.protocol` is `3.0` against Academica, which is believed to
redirect cookieless `/p3/serviceValidate` calls to the login page. Protocol
`2.0` is the default for that reason.

**Fix.** Keep `cas.protocol` at `2.0`. Set it to `3.0` only when a CAS server
releases attributes on `/p3/serviceValidate`.

**Check.** Validate a ticket with `cas.protocol` at `2.0`; the identity comes
back with its attributes. `tests/CasDriverTest.php` covers it.

### A sign-in fails on a hung or slow CAS server, or a client that is set up wrong

**Cause.** SESAME treats any non-200 answer (including a redirect) as a failed
login and lets a client exception through, so a PSR-18 client that is set up
wrong fails the sign-in and never signs anyone in. Guzzle has no total timeout
by default, so a hung CAS server would hold the request open forever, and it
follows up to five redirects by default, to any host or to plain http.

**Fix.** Hand SESAME a client with a timeout, redirects off for the
validation call, and TLS verification on:

```php
$http = new \GuzzleHttp\Client([
    'timeout' => 10,
    'connect_timeout' => 5,
    'allow_redirects' => false,
    'verify' => true,
]);
```

**Check.** Run the suite; the fail-closed behavior on a redirect and on a
client exception is proven by tests (S42):

```bash tab="docker"
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

```bash tab="local"
vendor/bin/phpunit
```

### A failed login shows the CAS failure code to the user

**Cause.** An `AuthenticationFailed` message can carry the CAS failure code
(for example `INVALID_TICKET`), which tells an attacker how the server refused
the ticket.

**Fix.** Log the message for operators and show the user one generic line
instead.

**Check.** Trigger a failed login with a bad ticket; the log carries the
message and the page shows the generic line. `tests/CasDriverTest.php` covers
the failure path.

### The gateway driver refuses every assertion from an untrusted peer

**Cause.** The request must come from a trusted proxy: the direct peer
(`REMOTE_ADDR`) must match one of the configured CIDR ranges in
`gateway.trusted_proxies`. A valid assertion from an untrusted peer is still
rejected, and a plain identity header (`Remote-User`, `X-Remote-User`, or any
spelling of them) is never read.

**Fix.** List the CIDR ranges (or single IPs) the gateway proxy sits behind in
`gateway.trusted_proxies`, a non-empty array.

**Check.** Send a request through the gateway proxy; the assertion verifies
and the user signs in. `tests/GatewayDriverTest.php` covers the trust checks.

### The gateway driver throws `InvalidConfig` naming a key at boot

**Cause.** With the gateway driver, `DriverFactory::make()` throws
`InvalidConfig` naming the key when `gateway.public_key`, `gateway.issuer` or
`gateway.audience` is null or empty, instead of passing an empty string into
the driver.

**Fix.** Set the named key. All three are required for the gateway driver,
along with a non-empty `gateway.trusted_proxies`.

**Check.** Boot the driver; it builds without `InvalidConfig`.

### A truthy `slo` (or `single_logout`) config key is rejected at boot

**Cause.** Single logout is not supported: the identity provider cannot sign
the user out of the app's other sessions, so SESAME rejects a truthy `slo`
(or `single_logout`) config key with `InvalidConfig` at boot, a mistake to
fail on early rather than at the first logout.

**Fix.** Remove the `slo` (or `single_logout`) key from the config.

**Check.** Boot the app; it starts, and a logout clears the app session and
sends `Cache-Control: no-store` and `Clear-Site-Data: "cache", "storage"`.

### The `fake` driver refuses to boot in production

**Cause.** The `fake` driver is for local development and tests only; it
refuses to boot in production.

**Fix.** Use a real driver in production: `cas` for a Citadel app today,
`gateway` once Drawbridge is live, or `oidc` or `oauth2` for apps off
Citadel. See [Which driver for my app?](guides/which-driver.md).

**Check.** In the local environment the `fake` driver signs in; in production
the configured driver does. `tests/FakeDriverTest.php` covers the refusal.

### The log has `SESAME: the session was started with session.use_strict_mode off`

**Cause.** The app started its session with `session.use_strict_mode` off
(PHP then accepts any session ID a client sends, so an attacker can plant a
known one) or `session.use_only_cookies` off (PHP takes the ID from the URL).
PHP's own default leaves strict mode off, and so does a shared `php.ini` that
nobody hardened. `NativeSession` turns both on itself whenever no session is
active yet, but a session the app already started keeps the settings it
started with, so SESAME raises one `E_USER_WARNING` per request and carries
on. With `checkSessionSettings: true` it throws `LogicException` instead.

**Fix.** Add `ini_set('session.use_strict_mode', '1');` before
`session_start()`, or call `helmet_boot()` there (HELMET sets both, with the
cookie flags), or set `session.use_strict_mode = 1` in `php.ini` or the FPM
pool. Squire's `php.ini` already does. Building `new NativeSession()` before
`session_start()` also works, since it turns the settings on while no session
is active.

**Check.** The warning stops. `php -r "echo ini_get('session.use_strict_mode');"`
prints `1` for the CLI; for the web, the same `ini_get()` on a page.
`NativeSessionTest` covers the settings, the warning and the hard stop.

## The session ends too soon, or looks wrong

### A raw-PHP app signs people out after 24 quiet minutes

**Cause.** PHP's default `session.gc_maxlifetime` is 1440 seconds (24
minutes), and PHP's garbage collector deletes session data older than that, so
on the defaults a raw-PHP app signs people out after 24 quiet minutes, long
before SESAME's 120-minute idle timeout. SESAME never starts the session, so
it cannot change this for you.

**Fix.** Set `session.gc_maxlifetime = 7200` (your `session.idle_minutes`
times 60) in `php.ini` or the FPM pool, or call
`ini_set('session.gc_maxlifetime', '7200')` before `session_start()`. Laravel
apps set `SESSION_LIFETIME` (minutes) instead.

**Check.** The exact call the sources use, in the oidc raw-PHP example:

```php
ini_set('session.gc_maxlifetime', '7200'); // at least SESAME's idle timeout; see Raw PHP
```

Then sit a signed-in session for more than 24 quiet minutes; it stays signed
in until the idle timeout (120 minutes by default) drops it.

### The log carries a `sesame.session_anomaly` warning

**Cause.** The session state looks wrong or tampered with: `reason` is
`missing_timestamps` (a user stored without the session's timestamps; the
session is signed out right after, so the anomaly precedes the
`sesame.logout`) or `bad_session_ref` (a stored `sesame.session_ref` that is
not a 16-character lowercase hex string; the user stays signed in and the bad
value is never echoed into the log). A session without a reference at all is
not an anomaly.

**Fix.** Look at the `session_ref` and the user around it in the log to see
what happened to the session. `sesame.login` and `sesame.logout` also carry
`session_ref`, the opaque 16-character hex reference that ties the login,
timeouts and logout of one sign-in together in the log.

**Check.** Grep the log for `sesame.session_anomaly` and follow the
`session_ref` value across the events of that sign-in.
`tests/SesameEventsTest.php` covers the events.

### The session is missing on the return from the identity provider

**Cause.** The session cookie is `SameSite=Strict`. The identity provider
sends the browser back to the app with a cross-site top-level `GET`, and a
`Strict` cookie is not sent on that request: it would be missing, so the app
would see no session on the return request and any login binding would fail
for every user.

**Fix.** Keep the cookie `SameSite=Lax`, never `Strict`. In raw PHP HELMET
makes both choices for you (`SessionHardening::apply()`, called by
`helmet_boot()`); in Laravel the default is `Lax`, and the settings are
`SESSION_COOKIE=__Host-wsu-session`, `SESSION_SECURE_COOKIE=true`,
`SESSION_PATH=/` with `SESSION_DOMAIN` empty in `config/session.php`.

**Check.** Sign in end to end; the return request carries the cookie and the
login completes. A cross-site `POST` carries no `Lax` cookie at all, which is
the defense you want.

### A stale session is signed out on the spot

**Cause.** Every signed-in session has two clocks: the idle timeout (default
`session.idle_minutes` `120`) and the absolute lifetime (default
`session.absolute_minutes` `720`). Both are checked on every `user()` call, so
a stale session is signed out the first time anything asks who is signed in.
A session made before the timeouts existed (a user, no timestamps) counts as
expired.

**Fix.** This is the expected behavior, not a fault. If the limits are wrong
for the app, set `session.idle_minutes` and `session.absolute_minutes` (env
`SESAME_SESSION_IDLE_MINUTES` and `SESAME_SESSION_ABSOLUTE_MINUTES` in
Laravel). See [Session timeouts](guides/session-timeouts.md).

**Check.** Read the user with `sesame_user()` after the quiet time; the
session is gone and the next `sesame_login()` starts fresh.

## Config and playbook

### Boot fails: `Missing required setting SESAME_....`

**Cause.** The setting is not in the environment and the private
`waynestate/playbook` package, which supplies the university's values when the
environment is empty, is not installed. A missing routed setting fails with
`Missing required setting SESAME_.... Set it in the environment, or install
waynestate/playbook (see the README).`

**Fix.** Set the variable in the environment, as with every other setting, or
install the playbook:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://git.wayne.edu/enterprise-apps/playbook.git" }
    ]
}
```

```
composer require waynestate/playbook
```

The environment always wins: a set variable beats the playbook, and an empty
one counts as unset.

**Check.** Boot the app with the environment empty; it reads the four shared
values from the playbook and starts. `tests/SettingTest.php` covers the
resolution order.

### Fetching the playbook answers 404

**Cause.** Playbook is private on git.wayne.edu.

**Fix.** Ask the Enterprise Applications team for read access. The token is
your own: `read_api` for curl, `read_repository` for clone and Composer.

**Check.** Refetch; the file arrives as plain `KEY=value` lines. Copy the ones
you need into your app's `.env` (or your Citadel environment); SESAME does not
read `.env.playbook` itself.

```bash tab="linux"
curl --fail -H "PRIVATE-TOKEN: $GITLAB_TOKEN" "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" -o .env.playbook
```

```powershell tab="windows"
Invoke-WebRequest -Headers @{ 'PRIVATE-TOKEN' = $env:GITLAB_TOKEN } -Uri "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" -OutFile .env.playbook
```

> [!WARNING]
> The token in those commands is a secret. Keep it in the environment, never
> in the repository, and remember Composer keeps the git.wayne.edu
> credentials it asks for in `auth.json` as an `http-basic` entry.

### The app still reads wayne-cas names and you expect SESAME behavior

**Cause.** The app moved to SESAME through the shim, which keeps the
wayne-cas names and behavior and says once (in the log) that it is a
deprecated bridge. For example, `use2FA` runs a Duo challenge the shim does
not carry: when the app still has `wayne-cas.use2FA` switched on, the shim
logs a warning once and leaves the 2FA to the app.

**Fix.** Follow the mapping in [Moving from wayne-cas](guides/moving-from-wayne-cas.md),
or take the easy way: Booster's `cas-to-sesame` hop, planned with `--sesame`.

**Check.** After the move, log in through the pre-production host before you
ship, and check the session timeout, the Access ID attribute and Duo there.
`tests/Laravel/WayneCasShimTest.php` covers the shim.

## Build, CI and docs

### `composer ci` fails

**Cause.** `composer ci` runs the whole gate: validate through tests, Pint
and PHPStan, plus the agent-ready and docs checks and the locked-dependency
audit. The same command runs on GitLab and GitHub, and both pipelines run the
same five checks (manifest, tests, Pint, PHPStan, audit), so a green build
means the same thing on both sides.

**Fix.** Read the failing step; static analysis is PHPStan at level 8 from
Pinstripe's strict preset, where a call to a deprecated method fails the
build.

**Check.** Rerun the gate locally:

```bash tab="docker"
docker run --rm -v "${PWD}:/app" -v stack-composer-cache:/tmp/cache -w /app composer:2 composer ci
```

```bash tab="local"
composer ci
```

### `composer docs:check` fails in CI

**Cause.** The built docs site is stale, or the generated environment
reference has drifted from the source.

**Fix.** Rebuild the docs site:

```
composer docs
```

**Check.** `composer docs:check` passes again, and the rebuilt pages under
`site/docs/` match what you committed.

### No PHP on the machine, and the suite will not run

**Cause.** The developer machine has no PHP, so `vendor/bin/phpunit` cannot
start.

**Fix.** Docker runs the suite:

```
docker run --rm -v "${PWD}:/app" -v stack-composer-cache:/tmp/cache -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

**Check.** All three PHP versions pass. How to build, test and release this
project is in MAINTAINING.md.

## Related

- [Quick start](guides/quick-start.md): prove the wiring with the `fake` driver.
- [Raw PHP](guides/raw-php.md): the session setup this page assumes.
- [Laravel](guides/laravel.md): the adapter's config keys and routes.
- [Session timeouts](guides/session-timeouts.md): the two clocks every session runs on.
- [Moving from wayne-cas](guides/moving-from-wayne-cas.md): the shim and the Booster hop.
- [Configuration](reference/configuration.md): every `SESAME_*` setting, including the generated environment reference.
- [Upgrading](upgrading.md): what changed between the versions.
