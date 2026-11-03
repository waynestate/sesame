---
title: "The oidc driver (Entra sign-in) | Sesame docs"
description: "Browser sign-in with OpenID Connect (code flow with PKCE), Microsoft Entra ID first, for apps off Citadel and APIs."
url: https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# The oidc driver (Entra sign-in)[#](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html#the-oidc-driver-entra-sign-in)

The goal: sign a person into your app through the browser, straight at Microsoft Entra ID, with OpenID Connect. This page walks through how a sign-in runs, every config key, and the one-time Entra app registration; then set the driver up on the [Raw PHP](https://sesame.apps.wayne.edu/docs/guides/raw-php.html) or [Laravel](https://sesame.apps.wayne.edu/docs/guides/laravel.html) page.

> \[!NOTE\] This is the exception, not the default. On Citadel prefer the `gateway` driver behind Drawbridge, which needs no Entra registration per app. This driver needs its own registration (see "The Entra app registration" below).

## What you need[#](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html#what-you-need)

Open sesame, said straight to Entra. The oidc driver signs a person in through the browser with OpenID Connect: the authorization code flow with PKCE (S256), the ID token checked against the issuer's key set. It is the Entra-direct path beside `cas` and `gateway` (Drawbridge), and it needs `league/oauth2-client`, which SESAME suggests but does not require:

```
composer require league/oauth2-client
```

Without it, building the driver fails with `The oidc driver needs league/oauth2-client. Install it with: composer require league/oauth2-client`. Why that library, and what else was weighed: [docs/oidc-library-choice.md](https://sesame.apps.wayne.edu/docs/oidc-library-choice.html).

## How a sign-in runs[#](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html#how-a-sign-in-runs)

1. A request to the login URL without a `code` starts it. The driver makes a fresh `state` and `nonce` and a PKCE verifier, keeps all three in the app's session (bound to this browser, single use, gone after ten minutes), and redirects to the authorization endpoint with `response_mode=query`.
2. Entra sends the browser back to the redirect URI with `code` and `state`. The driver takes the pending sign-in for that state out of the session first (a replayed callback finds nothing), exchanges the code with the verifier and the client secret, and validates the ID token: signature against the JWKS (RS256 by default, never `none` or HMAC), `iss` exactly, `aud` is the client id, `azp` when present, `exp`, `nbf` and `iat` with a 60-second leeway, and the `nonce`. No request to the provider (token, discovery, key set) follows a redirect: a 3xx answer fails the sign-in, so the code, the verifier and the secret are never resent elsewhere.
3. The identity goes to the app's `findOrImportUser(Identity)`, and the core regenerates the session ID, as for every driver. The access token and the ID token are never stored.

## Silent SSO and step-up[#](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html#silent-sso-and-step-up)

**SSO stays silent.** No `prompt` and no `max_age` are sent by default, so a person already signed in to Entra (Outlook, Teams, Canvas) is signed in without a click.

**Step-up.** A login request with `step_up=1` adds the app's configured step-up parameters: `prompt=login` by default, and optionally `max_age`, `acr_values` and `claims`. The request can ask for a step-up but cannot choose what it is; only config feeds those values. The callback then requires a fresh `auth_time`: not in the future (beyond the leeway), not before the sign-in started when `prompt=login` was sent, and within `max_age`, which is 300 seconds when `oidc.step_up.max_age` is not set, so a step-up is never unbounded. In Laravel, `sesame.mfa` with `mfa.on_failure=reauth` sends the user to `/login?step_up=1` for you.

## Identity, roles and claims[#](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html#identity-roles-and-claims)

The username comes from `oidc.username_claim` (default `accessid`, the UPN's prefix). When the token has no such claim, the sign-in fails with `oidc.missing_claim` and the error names the settings to change. `oidc.username_fallback` (off by default) uses the part of `preferred_username` before the `@` instead.

> \[!WARNING\] `preferred_username` can be changed by the user or an admin (a renamed UPN, a guest account), so a fallback username is no stable account key. Turn the fallback on only when the app keys its users on something else (`iss` plus `sub`, or `tid` plus `oid`).

The roles claim (`oidc.roles_claim`, default `wsu_roles`) becomes the `roles` attribute; a mapped claim that arrives under a custom-claim namespace (`http://schemas.example.test/claims/wsu_roles` or `extn.wsu_roles`) is found by its short name. The exact name and the namespaced forms are counted together: two or more candidates (an exact `wsu_roles` beside a namespaced one, say) count as none. `oidc.claims` (default `wsu_roles,accessid`) lists the claims passed to the resolver under their own names, so `$identity->attributes['wsu_roles']` works. Set `roles_claim` (and `claims`) to `wsuRoles` to keep the old name. `iss`, `sub`, `oid`, `tid`, `email`, `name` and `preferred_username` are attributes too; key the app's users on `iss` plus `sub`, or `tid` plus `oid` (S24), never on the username alone.

## MFA[#](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html#mfa)

`auth_time` becomes the identity's MFA time and `amr` its methods, with the cas driver's rules: the driver never invents a sign-in time, and without an MFA method in `amr` the MFA check fails closed. Entra's v2 ID tokens often carry no `amr`; when Conditional Access enforces MFA for this app, `oidc.assume_mfa` adds `mfa` to the methods, exactly like `cas.assume_mfa`. `acrs` (authentication contexts `c1` to `c99`) feeds `hasAuthContext()`.

## Return-to and logout[#](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html#return-to-and-logout)

A login request may carry `return_to`. It is kept only when it passes the ReturnTo rule (an absolute https URL with the redirect URI's scheme, host and port, and no user name or password); anything else is dropped, so it can never be an open redirect. After the callback, `sesame_login()` and the Laravel route send the user there.

The app session is cleared first, as for every driver. With `oidc.post_logout_redirect_uri` set, the user then goes through Entra's `end_session_endpoint` with that `post_logout_redirect_uri`, which must also be registered on the app registration. Without it, logout stays local and the Entra session lives on (the next sign-in is silent).

## Raw PHP[#](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html#raw-php)

```php
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\Support\Setting;

ini_set('session.gc_maxlifetime', '7200'); // at least SESAME's idle timeout; see Raw PHP
session_start();

$config = Config::fromArray([
    'driver' => 'oidc',
    'environment' => 'production',
    'oidc' => [
        'issuer' => Setting::get('SESAME_OIDC_ISSUER') ?? Setting::get('SESAME_OAUTH2_ISSUER'),
        'client_id' => Setting::get('SESAME_OIDC_CLIENT_ID'),
        // client_secret is left out: it is read from SESAME_OIDC_CLIENT_SECRET
        // in the environment, never from the playbook.
        'redirect_uri' => 'https://app.example.test/login.php',
        'post_logout_redirect_uri' => 'https://app.example.test/',
    ],
]);

$session = new NativeSession();
$driver = DriverFactory::make(
    $config,
    new \GuzzleHttp\Client(['allow_redirects' => false, 'timeout' => 10]),
    new Psr17Factory(),
    null,
    null,
    new Psr16Cache(new FilesystemAdapter('sesame')), // optional; without it, discovery and keys are fetched per request
    $session,
);

sesame(new Sesame($driver, new MyUserResolver(), $session, $config));

// login.php: starts the sign-in, finishes it on the callback, then sends the
// user to the checked return_to or back here.
$user = sesame_login();
```

A protected page sends a guest to `login.php?return_to=<its own https URL>`, and a page that needs fresh credentials to `login.php?step_up=1`.

## Laravel[#](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html#laravel)

```
SESAME_DRIVER=oidc
SESAME_OIDC_CLIENT_ID=<application (client) id>
SESAME_OIDC_CLIENT_SECRET=<client secret value>
# SESAME_OIDC_ISSUER falls back to SESAME_OAUTH2_ISSUER (and the playbook).
# SESAME_OIDC_REDIRECT_URI defaults to the app's /login/callback route.
SESAME_OIDC_POST_LOGOUT_REDIRECT_URI=https://app.example.test/
```

The adapter registers `GET /login/callback` (`sesame.callback`) beside `GET /login`, passes the Laravel session and cache to the driver, and after the callback redirects to the checked `return_to`, or to the page `auth.sesame` saved. Laravel's default `SameSite=Lax` session cookie is right: Entra's redirect back is a top-level GET, which Lax allows (S41); `Strict` would drop the cookie and fail every callback with `oidc.state`.

## Config keys[#](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html#config-keys)

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `oidc.issuer` | `SESAME_OIDC_ISSUER`, then `SESAME_OAUTH2_ISSUER` | none | The issuer, exactly as the discovery document's `issuer`. Required. |
| `oidc.client_id` | `SESAME_OIDC_CLIENT_ID` | none | The app registration's client id; the ID token's `aud`. Required. |
| `oidc.client_secret` | `SESAME_OIDC_CLIENT_SECRET` | none | Environment only, never the playbook, never logged. Held in a `SensitiveParameterValue`, so `var_export`, `json_encode` and dumps show nothing and `serialize` refuses. Keep PHP's production default `zend.exception_ignore_args = On` as well. Required. |
| `oidc.redirect_uri` | `SESAME_OIDC_REDIRECT_URI` | Laravel: `/login/callback` | Where Entra sends the browser back. https, or `http://localhost` for development. Required in raw PHP. |
| `oidc.scopes` | `SESAME_OIDC_SCOPES` | `openid profile email` | `openid` is always added. |
| `oidc.client_auth` | `SESAME_OIDC_CLIENT_AUTH` | `client_secret_post` | Or `client_secret_basic`. |
| `oidc.username_claim` | `SESAME_OIDC_USERNAME_CLAIM` | `accessid` | The claim the username comes from. |
| `oidc.username_fallback` | `SESAME_OIDC_USERNAME_FALLBACK` | `false` | Opt in to the `preferred_username` prefix when the claim is missing. `preferred_username` is user-changeable; see the warning above. |
| `oidc.roles_claim` | `SESAME_OIDC_ROLES_CLAIM` | `wsu_roles` | The claim that becomes the `roles` attribute; namespaced forms are found. |
| `oidc.claims` | `SESAME_OIDC_CLAIMS` | `wsu_roles,accessid` | Claims passed to the resolver as attributes under their own names. |
| `oidc.assume_mfa` | `SESAME_OIDC_ASSUME_MFA` | `false` | Count a sign-in as MFA when Conditional Access enforces it for the app. |
| `oidc.algorithms` | none | `['RS256']` | The ID token algorithms allowed: `RS256`, `RS384`, `RS512` only. |
| `oidc.leeway_seconds` | `SESAME_OIDC_LEEWAY_SECONDS` | `60` | Clock skew on `exp`, `nbf` and `iat`, at most 300. |
| `oidc.post_logout_redirect_uri` | `SESAME_OIDC_POST_LOGOUT_REDIRECT_URI` | none | Set it to log out of Entra too. |
| `oidc.authorization_endpoint`, `oidc.token_endpoint`, `oidc.jwks_uri`, `oidc.end_session_endpoint` | `SESAME_OIDC_AUTHORIZATION_ENDPOINT` and so on | discovered | Overrides; with the first three set, discovery is skipped. |
| `oidc.cache_ttl_seconds` | `SESAME_OIDC_CACHE_TTL_SECONDS` | `3600` | How long the discovery document and key set are kept, 1 to 3600 seconds (at most an hour, so a moved endpoint or revoked key does not live on). |
| `oidc.cache_store` | `SESAME_OIDC_CACHE_STORE` | the default store | Laravel only. |
| `oidc.state_ttl_seconds` | none | `600` | How long a started sign-in may take, 60 to 3600 seconds. |
| `oidc.step_up.prompt` | `SESAME_OIDC_STEP_UP_PROMPT` | `login` | Sent on a step-up only; `login` or empty. |
| `oidc.step_up.max_age` | `SESAME_OIDC_STEP_UP_MAX_AGE` | none (300 enforced) | Seconds; sent on a step-up only, and enforced on `auth_time`. Unset, it is not sent, but a step-up `auth_time` older than 300 seconds still fails. |
| `oidc.step_up.acr_values` | `SESAME_OIDC_STEP_UP_ACR_VALUES` | none | Sent on a step-up only. |
| `oidc.step_up.claims` | `SESAME_OIDC_STEP_UP_CLAIMS` | none | A JSON object (for example an `acrs` request for an Entra authentication context), sent on a step-up only. |

> \[!DANGER\] `SESAME_OIDC_CLIENT_SECRET` is environment only: never the playbook, never logged. It is held in a `SensitiveParameterValue`, so `var_export`, `json_encode` and dumps show nothing and `serialize` refuses. Keep PHP's production default `zend.exception_ignore_args = On` as well.

## The Entra app registration[#](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html#the-entra-app-registration)

What the developer (or the Entra admin) sets on the app registration:

- **Platform: Web**, with the redirect URI exactly as `oidc.redirect_uri` (for Laravel, `https://<app host>/login/callback`). Add `http://localhost:<port>/login/callback` only on a development registration.
- **Post-logout redirect**: when you set `oidc.post_logout_redirect_uri`, register that address as a redirect URI too.
- **Certificates and secrets**: a client secret, stored only in the app's environment (`SESAME_OIDC_CLIENT_SECRET`); rotate it before it expires.
- **Authentication**: leave the implicit grant's "ID tokens" and "Access tokens" boxes unchecked; the code flow does not need them.
- **Token configuration**: add the optional ID token claim `auth_time` (and `email` if the app wants it). Ask the Entra admin for the mapped `wsu_roles` and `accessid` claims (a claims-mapping policy); if they arrive under a namespace, SESAME finds them by their short names.
- **Enterprise application, Properties: Assignment required = Yes**, then assign the groups that may sign in. This is what limits an app to, for example, employees only: Entra refuses everyone else before SESAME sees them. Check roles in the app as well (S25).
- **API permissions**: `openid`, `profile` and `email` (Microsoft Graph, delegated) are enough.

Use the v2.0 issuer (`https://login.microsoftonline.com/<tenant>/v2.0`); the tenant id comes from the playbook or the environment, never from code.

## Related[#](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html#related)

- [Which driver for my app?](https://sesame.apps.wayne.edu/docs/guides/which-driver.html)
- [The gateway driver](https://sesame.apps.wayne.edu/docs/guides/driver-gateway.html)
- [The cas driver](https://sesame.apps.wayne.edu/docs/guides/driver-cas.html)
- [oauth2 with Entra](https://sesame.apps.wayne.edu/docs/guides/driver-oauth2-entra.html)
- [Raw PHP](https://sesame.apps.wayne.edu/docs/guides/raw-php.html)
- [Laravel](https://sesame.apps.wayne.edu/docs/guides/laravel.html)
- [Requiring MFA](https://sesame.apps.wayne.edu/docs/guides/requiring-mfa.html)
- [Step-up (authentication context)](https://sesame.apps.wayne.edu/docs/guides/step-up.html)
