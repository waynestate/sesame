---
title: "Upgrading | Sesame docs"
description: "How to move between SESAME versions."
url: https://sesame.apps.wayne.edu/docs/upgrading.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Upgrading[#](https://sesame.apps.wayne.edu/docs/upgrading.html#upgrading)

How to move between SESAME versions. Each section below is one released version from the changelog that carries a break; sections without a break say so in one sentence and give the upgrade command. Start at the version you are on, work down toward the one you want, and run the checks after each hop.

## 0.4.0 (2026-11-03)[#](https://sesame.apps.wayne.edu/docs/upgrading.html#0-4-0-2026-11-03)

**Breaking.** The `gateway` driver now refuses a token whose `typ` header is `at+jwt` or `application/at+jwt`, so an OAuth2 access token cannot be replayed as a gateway assertion. A missing `typ` or `JWT` still works. This is a pre-1.0 minor: apps that pin `^0.3` stay on 0.3.x until they move their constraint to `^0.4`.

**Deprecated.** Nothing is deprecated in this release.

**Steps.**

1. Move the constraint and update:
   ```
   composer require waynestate/sesame:^0.4
   ```
2. If your app sends OAuth2 access tokens to a gateway endpoint, stop: those tokens are refused now by design. Route API calls through the `oauth2` driver instead.
3. Optional new pieces you can adopt now: `session_ref`, a per sign-in correlation id that ties the auth events of one sign-in together in the log; `Identity::isFromNewLogin()` and `Identity::usedLongTermToken()` for CAS; optional PSR-3 logging of authentication events (`sesame.log`, env `SESAME_LOG`, default `true`); `oauth2.require_at_jwt` (env `SESAME_OAUTH2_REQUIRE_AT_JWT`), off by default because Microsoft Entra v2 access tokens are typed `JWT`; and `gateway.public_keys`, a map of `kid` to public key for key rotation without a coordinated window.

**How to verify.** Run the gate:

```
composer ci
```

then sign in through the gateway with a normal assertion (missing `typ` or `JWT`) and confirm a replayed `at+jwt` token is refused.

## 0.3.0 (2026-10-30)[#](https://sesame.apps.wayne.edu/docs/upgrading.html#0-3-0-2026-10-30)

**Breaking.** The signed-in user is looked up again on every request when the app's resolver implements the new `RefreshesUser` interface, and a user who no longer exists signs the session out. Before, `Sesame::user()` returned the copy saved at sign-in, so a user whose role was removed kept it until the session ended. The wayne-cas `UserResolver` implements it (a plain find by primary key, never an import). Also fixed in this release: the wayne-cas `WayneAuthenticate` middleware used to send `/login` to itself forever, and resolving SESAME used to build the driver, which broke guests on http, artisan commands, queue workers and tests.

**Deprecated.** Nothing is deprecated in this release.

**Steps.**

1. Update the package:
   ```
   composer require waynestate/sesame:^0.3
   ```
2. If the app reads the user through `auth()->user()`, point the guard at the new `sesame` driver: set `auth.guards.web.driver` to `sesame` and the users provider to `eloquent`. A Laravel login, `onceUsingId()` or `actingAs()` still wins for the request.
3. Check the user model: it needs the exact method `public function getReadOnlyAttributes($persistedAttributes = []): array` (public, with the parameter), since the wayne-cas `WayneCasable` interface and `ReadOnlyAttributes` trait now agree on one signature.

**How to verify.** Sign in, then remove a user's role (or the account) while the session is live; the next request reflects the change, and a deleted user is signed out. `tests/Laravel/SesameGuardTest.php` and `tests/Laravel/ReadOnlyAttributesTest.php` cover the guard and the model shape.

## 0.2.2 (2026-10-29)[#](https://sesame.apps.wayne.edu/docs/upgrading.html#0-2-2-2026-10-29)

**Breaking.** The oidc driver's default roles claim is `wsu_roles`, the name Entra sends. Apps that relied on the old default must set `roles_claim` (and `claims`) to `wsuRoles` to keep the old name.

**Deprecated.** Nothing is deprecated in this release.

**Steps.**

1. Update the package:
   ```
   composer require waynestate/sesame:^0.2
   ```
2. If the app reads roles from the oidc identity under the old name, add `oidc.roles_claim` (env `SESAME_OIDC_ROLES_CLAIM`) set to `wsuRoles`, and the same for `oidc.claims` (env `SESAME_OIDC_CLAIMS`).

**How to verify.** Sign in with the `oidc` driver and read `$identity->attributes['wsu_roles']` (or the name you configured); the roles arrive under the claim you named.

## 0.2.1 (2026-10-29)[#](https://sesame.apps.wayne.edu/docs/upgrading.html#0-2-1-2026-10-29)

No breaks in this release: it adds the agent-ready site from Squire's kit, fixes the oauth2 peg on the site, writes project names as words except acronyms, fixes stale capability claims, and shows the oidc driver on the site. Upgrade with:

```
composer require waynestate/sesame:^0.2
```

## 0.2.0 (2026-10-29)[#](https://sesame.apps.wayne.edu/docs/upgrading.html#0-2-0-2026-10-29)

**Breaking.** Raw PHP: `sesame_logout()` is POST-only with a CSRF token. It runs only when the POST body's `sesame_logout_token` matches the session's token (new `sesame_logout_token()`, `Sesame::logoutToken()` and `Sesame::isLogoutToken()`); a GET answers 405 and a bad token 403, and neither touches the session. Apps must change a logout link to a POST form carrying the token. The Laravel `POST /logout` route stays in the `web` group and so under Laravel's CSRF check. Also changed: `ReturnTo` (every driver's login and logout return-to) now requires the service URL's scheme, host and effective port, and refuses a URL with a user name or password; before, the host alone was compared, so `https://app.example.test:8443/` passed for a service on 443. Plain http is accepted only for a localhost or 127.0.0.1 service URL. And `DriverFactory::make()` takes the app session as an optional seventh argument (the oidc driver needs it).

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
3. If the app passes return-to addresses, make sure they match the service URL's scheme, host and port, and carry no user name or password.
4. If you use the new `oidc` driver, pass the app session as the seventh argument to `DriverFactory::make()`, and install `league/oauth2-client`.

**How to verify.** A GET to the logout endpoint answers 405 and leaves the session alone; a POST without the token answers 403; a POST with the token signs out. `tests/ReturnToTest.php` covers the tightened return-to rule.

## 0.1.3 (2026-10-29)[#](https://sesame.apps.wayne.edu/docs/upgrading.html#0-1-3-2026-10-29)

No breaks in this release: the four shared WSU values (`SESAME_CAS_BASE_URL`, `SESAME_GATEWAY_ISSUER`, `SESAME_OAUTH2_ISSUER`, `SESAME_OAUTH2_JWKS_URL`) now resolve through `Waynestate\Sesame\Support\Setting::get()` (the environment wins, and when it is empty the private `waynestate/playbook` package supplies the value, suggested, never required), and a missing routed setting now fails with `Missing required setting SESAME_.... Set it in the environment, or install waynestate/playbook (see the README).` instead of naming the config key. Upgrade with:

```
composer require waynestate/sesame:^0.1
```

## 0.1.2 (2026-10-29)[#](https://sesame.apps.wayne.edu/docs/upgrading.html#0-1-2-2026-10-29)

No breaks in this release: the CAS driver reads `serviceValidate` responses by namespace URI instead of prefix, so a body that binds the `cas:` prefix to a different namespace can no longer be read as a success, while a body that binds another prefix (or none) to the real CAS namespace keeps working. Upgrade with:

```
composer require waynestate/sesame:^0.1
```

## 0.1.1 (2026-10-29)[#](https://sesame.apps.wayne.edu/docs/upgrading.html#0-1-1-2026-10-29)

**Breaking.** A truthy `slo` (or `single_logout`) config key is now rejected with `InvalidConfig` at boot: the identity provider does not support single logout, so the setting is a mistake to fail on early rather than at the first logout. An app that sets it must remove the key or it will not boot.

**Deprecated.** Nothing is deprecated in this release.

**Steps.**

1. Update the package:
   ```
   composer require waynestate/sesame:^0.1
   ```
2. Remove any `slo` (or `single_logout`) key from the config.
3. New in this release, if you want them: step-up by authentication context (`sesame_require_context(string $id)`, the `sesame.context` Laravel middleware, `Identity::hasAuthContext(string $id)`), `cas.assume_mfa` (default `false`), the `oauth2` driver with its config keys, and `InvalidConfig` naming the key when `gateway.public_key`, `gateway.issuer` or `gateway.audience` is null or empty.

**How to verify.** Boot the app; it starts without `InvalidConfig`, and a context check fails closed when there is no user or no identity. `tests/Laravel/RequireAuthContextTest.php` covers the context check.

## 0.1.0 (2026-10-27)[#](https://sesame.apps.wayne.edu/docs/upgrading.html#0-1-0-2026-10-27)

First tagged release. There is nothing to upgrade from. Install with:

```
composer require waynestate/sesame
```

## Related[#](https://sesame.apps.wayne.edu/docs/upgrading.html#related)

- [Install](https://sesame.apps.wayne.edu/docs/install.html): the requirements and the one composer line.
- [Quick start](https://sesame.apps.wayne.edu/docs/guides/quick-start.html): a first sign-in after you upgrade.
- [Configuration](https://sesame.apps.wayne.edu/docs/reference/configuration.html): every `SESAME_*` setting, including the generated environment reference.
- [Troubleshooting](https://sesame.apps.wayne.edu/docs/troubleshooting.html): what to try when a sign-in fails.
