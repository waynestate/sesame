---
title: "Laravel | SESAME docs"
description: "The Laravel adapter binds the core into the container, registers the middleware aliases and the login and logout routes, and holds no rules of its own."
url: https://sesame.apps.wayne.edu/docs/guides/laravel.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Laravel [#](https://sesame.apps.wayne.edu/docs/guides/laravel.html#laravel)

The Laravel adapter is a service provider that binds the core into the container, registers the `auth.sesame` and `wayne.cas` middleware aliases, and, unless the app has taken the routes over, registers the login and logout routes. It holds no rules of its own: every rule lives in the core. This page is how to wire it into an app and read the signed-in user back; next, see [Requiring MFA](https://sesame.apps.wayne.edu/docs/guides/requiring-mfa.html) and [Step-up (authentication context)](https://sesame.apps.wayne.edu/docs/guides/step-up.html) for the checks the middleware runs.

## Install and publish [#](https://sesame.apps.wayne.edu/docs/guides/laravel.html#install-and-publish)

The provider is discovered automatically. Publish the config with:

```
php artisan vendor:publish --tag=sesame-config
```

The app's environment becomes the core's `environment`, the `cas.service_url` falls back to the app's `/login` route when the app has not set one, and the oidc driver's `redirect_uri` falls back to the app's `/login/callback` route. For the saml driver, `saml.service_url`, `saml.sp.acs_url` and `saml.sp.entity_id` fall back to the app's `/login`, `/login/saml/acs` and `/login/saml/metadata` routes.

## Config [#](https://sesame.apps.wayne.edu/docs/guides/laravel.html#config)

Config keys (in `config/sesame.php`):

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `driver` | `SESAME_DRIVER` | `cas` | `cas`, `gateway`, `oauth2`, `oidc`, `saml` or `fake`. The oidc keys are in [The oidc driver](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html), the saml keys in [The saml driver](https://sesame.apps.wayne.edu/docs/guides/driver-saml.html). |
| `cas.base_url` | `SESAME_CAS_BASE_URL` | none | The CAS server, an absolute https:// URL (`http://localhost` or `http://127.0.0.1` only in a local environment). |
| `cas.service_url` | `SESAME_CAS_SERVICE_URL` | the app's `/login` route | Where the server sends the user back to: an absolute https:// URL, or `http://localhost` or `http://127.0.0.1` in a local environment. |
| `cas.protocol` | `SESAME_CAS_PROTOCOL` | `2.0` | `3.0` or `2.0`. |
| `cas.assume_mfa` | `SESAME_CAS_ASSUME_MFA` | `false` | A successful validation counts as an MFA sign-in. On only when the CAS server enforces MFA for every sign-in. |
| `fake.username` | `SESAME_FAKE_USERNAME` | none | The fake driver's username. |
| `gateway.header` | `SESAME_GATEWAY_HEADER` | `X-Sesame-Assertion` | The one header that holds the signed assertion. |
| `gateway.algorithm` | `SESAME_GATEWAY_ALGORITHM` | `ES256` | `ES256`, `RS256` or `EdDSA`. |
| `gateway.public_key` | `SESAME_GATEWAY_PUBLIC_KEY` | none | The gateway's public key. Required for the gateway driver. |
| `gateway.issuer` | `SESAME_GATEWAY_ISSUER` | none | The `iss` claim the assertion must carry. Required for the gateway driver. |
| `gateway.audience` | `SESAME_GATEWAY_AUDIENCE` | none | The `aud` claim the assertion must carry. Required for the gateway driver. |
| `gateway.leeway_seconds` | `SESAME_GATEWAY_LEEWAY_SECONDS` | `30` | Clock skew allowed on the time-claim checks, in seconds. |
| `gateway.trusted_proxies` | `SESAME_GATEWAY_TRUSTED_PROXIES` | none | Comma-separated CIDR ranges (or single IPs) the gateway proxy sits behind. Required for the gateway driver. |
| `gateway.max_lifetime` | `SESAME_GATEWAY_MAX_LIFETIME` | `300` | The longest an assertion may live, in seconds. |
| `gateway.replay_store` | `SESAME_GATEWAY_REPLAY_STORE` | the app's default cache store | The cache store the used `jti`s are kept in (Laravel only). |
| `gateway.allow_without_replay_guard` | `SESAME_GATEWAY_ALLOW_WITHOUT_REPLAY_GUARD` | `false` | Skip the replay guard. Local development only. |
| `oauth2.issuer` | `SESAME_OAUTH2_ISSUER` | none | The `iss` claim the token must carry. Required for the oauth2 driver. |
| `oauth2.jwks_url` | `SESAME_OAUTH2_JWKS_URL` | none | The URL of the issuer's key set. Required for the oauth2 driver. |
| `oauth2.audience` | `SESAME_OAUTH2_AUDIENCE` | none | The `aud` claim the token must carry. Required for the oauth2 driver. |
| `oauth2.leeway_seconds` | `SESAME_OAUTH2_LEEWAY_SECONDS` | `60` | Clock skew allowed on the time-claim checks, in seconds. |
| `oauth2.identity_claim` | `SESAME_OAUTH2_IDENTITY_CLAIM` | `preferred_username` | The claim the username comes from: `preferred_username` or `accessid`. |
| `oauth2.jwks_cache_ttl_seconds` | `SESAME_OAUTH2_JWKS_CACHE_TTL_SECONDS` | `3600` | How long the fetched key set is kept, in seconds. |
| `oauth2.jwks_store` | `SESAME_OAUTH2_JWKS_STORE` | the app's default cache store | The cache store the key set is kept in (Laravel only). |
| `oauth2.require_at_jwt` | `SESAME_OAUTH2_REQUIRE_AT_JWT` | `false` | Refuse a token whose `typ` header is not `at+jwt` (RFC 9068). Leave it off for Entra. |
| `oauth2.introspection_url` | `SESAME_OAUTH2_INTROSPECTION_URL` | none | The RFC 7662 endpoint for opaque bearer tokens. Leave it unset to keep the driver JWT-only. |
| `oauth2.introspection_client_id` | `SESAME_OAUTH2_INTROSPECTION_CLIENT_ID` | none | The client id sent as HTTP Basic auth to the introspection endpoint. Required when the URL is set. |
| `oauth2.introspection_client_secret` | `SESAME_OAUTH2_INTROSPECTION_CLIENT_SECRET` | none | The client secret sent as HTTP Basic auth to the introspection endpoint. Required when the URL is set. |
| `oauth2.introspection_cache_ttl_seconds` | `SESAME_OAUTH2_INTROSPECTION_CACHE_TTL_SECONDS` | `60` | How long an accepted introspection answer is kept, in seconds, capped by the token's `exp`. Zero disables the cache. |
| `session.idle_minutes` | `SESAME_SESSION_IDLE_MINUTES` | `120` | Drop the session after this much quiet time, in minutes. |
| `session.absolute_minutes` | `SESAME_SESSION_ABSOLUTE_MINUTES` | `720` | End the session this long after sign-in, in minutes. |
| `mfa.max_age` | `SESAME_MFA_MAX_AGE` | `28800` | The longest a user's last MFA sign-in may be, in seconds. |
| `mfa.on_failure` | `SESAME_MFA_ON_FAILURE` | `deny` | `deny` answers a 403; `reauth` redirects to the login route. |
| `routes` | `SESAME_ROUTES` | `true` | Register the login and logout routes. |
| `log` | `SESAME_LOG` | `true` | Log the sign-in and sign-out events through the app's PSR-3 logger. Set to `false` to turn them off. |
| `user_resolver` | `SESAME_USER_RESOLVER` | none | The class that resolves a verified identity to the app's user. Required. |

A missing `user_resolver` fails with an error that names the wayne-cas resolver class. The driver is built lazily: resolving SESAME does not build it, so guests on http, artisan commands, queue workers and tests can ask who is signed in; the driver (and its https check) is built when someone signs in or out, and an app with a broken config still boots and answers signed-out requests.

## Routes [#](https://sesame.apps.wayne.edu/docs/guides/laravel.html#routes)

The routes, when `routes` is true:

| Method | Path | Name |
| --- | --- | --- |
| `GET` | `/login` | `sesame.login` |
| `POST` | `/logout` | `sesame.logout` (in the `web` group, so Laravel's CSRF check applies; a form needs `@csrf`) |
| `GET` | `/login/callback` | `sesame.callback` (oidc driver only) |
| `POST` | `/login/saml/acs` | `sesame.saml.acs` (saml driver only; outside the `web` group, so no session and no CSRF token: the IdP's POST cannot carry either) |
| `GET` | `/login/saml/metadata` | `sesame.saml.metadata` (saml driver only; the SP metadata) |

Set `SESAME_ROUTES=false` when the app defines its own login and logout routes. The `auth.sesame` middleware still points at the `sesame.login` route name, so the app's login route must keep that name.

## Middleware [#](https://sesame.apps.wayne.edu/docs/guides/laravel.html#middleware)

Protect a route with the `auth.sesame` middleware:

```php
Route::get('/dashboard', fn () => view('dashboard'))->middleware('auth.sesame');
```

A guest is sent to the login route; a JSON guest gets a 401.

Require MFA on a route with the `sesame.mfa` middleware:

```php
Route::get('/vault', fn () => view('vault'))->middleware(['auth.sesame', 'sesame.mfa']);
```

It lets the request through when the signed-in user's MFA is still good, and otherwise stops it: a 403 by default, a JSON 403 for JSON requests, and a redirect to the login route when `mfa.on_failure` is `reauth`. The allowed age comes from the route parameter when there is one, and from `mfa.max_age` when there is not:

```php
Route::get('/vault', fn () => view('vault'))->middleware(['auth.sesame', 'sesame.mfa:3600']);
```

The `sesame.context` middleware does the step-up context check on a route:

```php
Route::get('/vault', [VaultController::class, 'index'])
    ->middleware(['web', 'auth.sesame', 'sesame.context:c1']);
```

A request without the context gets a 403, or a JSON 403 with a `message` for JSON requests. A route that names no context fails closed. See [Step-up (authentication context)](https://sesame.apps.wayne.edu/docs/guides/step-up.html).

The `wayne.cas` alias is registered against SESAME's `WayneAuthenticate`, for apps moving off wayne-cas; see [Moving from wayne-cas](https://sesame.apps.wayne.edu/docs/guides/moving-from-wayne-cas.html).

## Reading the user [#](https://sesame.apps.wayne.edu/docs/guides/laravel.html#reading-the-user)

The app's user is available through the facade:

```php
$user = app(\Waynestate\Sesame\Sesame::class)->user();
```

SESAME stores the user your resolver returned at sign-in and hands back that same copy until the session ends. When the app wants the signed-in user read back from its own store on every request, so a change made after the sign-in (a removed role, a deleted account) shows up from the next request on, the resolver also implements `Waynestate\Sesame\RefreshesUser`: its `refreshUser($user)` runs on every request, and a `null` answer signs the session out. The wayne-cas shim already implements it, looking the user up through `auth.providers.users.model` (an Eloquent model works as it is from 0.6.2 on, and with JSON session serialization from 0.6.3 on, so a wayne-cas app needs no resolver of its own); a resolver that does not implement it keeps handing back the stored copy, as it always has.

On Laravel 13, a resolver of your own must implement it. Laravel 13 stores sessions as JSON, so the user SESAME saved at sign-in comes back on the next request as a plain array instead of the app's User model, and a resolver that hands the array back leaves the guard seeing nobody. The wayne-cas shim looks the array up through the model itself, so a wayne-cas app needs no change. `refreshUser()` receives that array (or the model itself when the session is still PHP-serialized) and must return the app's User: look it up by a stable key such as the username or the primary key, and return `null` when the user is gone, which signs the session out. Sourdough's Laravel templates already ship this:

```php
public function refreshUser(mixed $user): mixed
{
    $username = match (true) {
        $user instanceof User => $user->username,
        is_array($user) => $user['username'] ?? null,
        default => null,
    };

    if (! is_string($username) || $username === '') {
        return null;
    }

    return User::where('username', $username)->first();
}
```

The same user is available through Laravel's own auth, because the provider registers a `sesame` guard driver. Point a guard in `config/auth.php` at it:

```php
'guards' => [
    'web' => [
        'driver' => 'sesame',
        'provider' => 'users',
    ],
],
```

and the usual calls work against the SESAME session: `auth()->user()`, `$request->user()`, `Auth::check()`, and the `auth` middleware. The note above applies here too: the guard hands back the stored copy, or the refreshed one when the resolver refreshes. A user that is not an `Illuminate\Contracts\Auth\Authenticatable` reads as `null` through the guard; the `auth.sesame` middleware still sees it, because it checks the SESAME session directly.

`auth()->logout()` signs the user out of both sides, the Laravel session and the SESAME session. The identity provider's single sign-on session is left alone.

## HTTP client and caches [#](https://sesame.apps.wayne.edu/docs/guides/laravel.html#http-client-and-caches)

The cas and oauth2 drivers need a PSR-18 client. The adapter uses the app's `Psr\Http\Client\ClientInterface` binding when there is one, Guzzle when it is installed (with redirects off), and nothing when there is neither. Bind a client when the app has neither:

```php
app()->singleton(
    Psr\Http\Client\ClientInterface::class,
    fn () => new \Symfony\Component\HttpClient\Psr18Client(),
);
```

The oauth2 driver also keeps its fetched key set in a PSR-16 cache. The adapter wires one up from the app's cache, using the store named by `oauth2.jwks_store`, or the default store when none is named. The gateway driver's replay guard is wired the same way, from the store named by `gateway.replay_store`, so a captured assertion cannot be played back a second time.

## Logging [#](https://sesame.apps.wayne.edu/docs/guides/laravel.html#logging)

The adapter passes the app's PSR-3 logger to the core, so a Laravel app gets the authentication events in its normal log channels with no extra wiring: the container's `Psr\Log\LoggerInterface` binding is used when there is one. Set `sesame.log` (env `SESAME_LOG`) to `false` to turn the events off; the default is `true`. The events, their context and what is never logged are described in [Raw PHP](https://sesame.apps.wayne.edu/docs/guides/raw-php.html), "Logging".

## Metrics [#](https://sesame.apps.wayne.edu/docs/guides/laravel.html#metrics)

Set `sesame.metrics.enabled` (env `SESAME_METRICS_ENABLED`) to `true` and the adapter counts every finished sign-in as `sesame_logins_total`, by driver and outcome. It writes to the app's `Prometheus\Storage\Adapter` binding when there is one, else to the store HELMET's request metrics use, so turn those on too (`HELMET_METRICS_ENABLED=true` and `HELMET_METRICS_STORAGE`) and the counter shows on HELMET's `/metrics`. The store is opened on the first sign-in of a request, never before. Off by default. See [Counting sign-ins](https://sesame.apps.wayne.edu/docs/guides/metrics.html).

## Check before a deploy [#](https://sesame.apps.wayne.edu/docs/guides/laravel.html#check-before-a-deploy)

In Laravel, SESAME rides on the app's own session, so `config/session.php` decides whether sign-in works at all. `php artisan sesame:check` reads those settings and SESAME's own, prints one line for each, and exits `1` when anything fails, so a deploy job can run it first:

```
$ php artisan sesame:check
[OK] Driver: cas is configured.
[OK] User resolver: set.
[OK] Session driver: file.
[OK] Session cookie: HttpOnly.
[FAIL] Session cookie: SameSite=Strict drops the cookie on the identity provider's return, so every sign-in fails; use lax.
[OK] Session cookie: Secure.
[OK] Session lifetime: 120 minutes, SESAME's idle timeout is 120.

6 ok, 0 warnings, 1 failure.
```

| Line | OK | WARN | FAIL |
| --- | --- | --- | --- |
| Driver | the driver builds from `config/sesame.php` |  | it does not (the line gives the reason; the fake driver outside local, development and testing is one) |
| User resolver | `sesame.user_resolver` names a class that implements `Waynestate\Sesame\UserResolver` |  | it is empty, or names something else |
| Session driver | `file`, `database`, `redis` or any other store | `cookie` (the whole session lives in the browser) | `array` (the session ends with the request, so a sign-in never sticks) |
| HttpOnly | `session.http_only` is `true` |  | anything else |
| SameSite | `lax` | `none`, or no value | `strict` (the identity provider's return is a cross-site `GET`, which drops a `Strict` cookie) |
| Secure | `true` | unset (it follows each request's scheme), or `false` outside production | `false` in production |
| Session lifetime | `session.lifetime` is at least `sesame.session.idle_minutes`, or the session ends when the browser closes | Laravel drops a quiet session before SESAME's idle timeout would |  |

Warnings exit `0`; `--strict` makes them fail too. The command only reads config and builds the driver: it never signs anyone in, calls the identity provider or prints a URL, key or secret (a URL an error quotes back reads `(a URL)`), so it is safe in CI logs. Run it with the deploy's real environment: on the command line there is no request, so the cas driver's return URL comes from `APP_URL`, and an `http://` (or missing) `APP_URL` fails the Driver line just as it would fail the first sign-in. A raw PHP app has no command; `NativeSession` checks HttpOnly, Secure and SameSite at run time and warns once per request, but it lets `Strict` through (see [Raw PHP](https://sesame.apps.wayne.edu/docs/guides/raw-php.html)).

## Related [#](https://sesame.apps.wayne.edu/docs/guides/laravel.html#related)

- [Quick start](https://sesame.apps.wayne.edu/docs/guides/quick-start.html)
- [Which driver](https://sesame.apps.wayne.edu/docs/guides/which-driver.html)
- [Requiring MFA](https://sesame.apps.wayne.edu/docs/guides/requiring-mfa.html)
- [Step-up (authentication context)](https://sesame.apps.wayne.edu/docs/guides/step-up.html)
- [Session timeouts](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html)
- [Moving from wayne-cas](https://sesame.apps.wayne.edu/docs/guides/moving-from-wayne-cas.html)
