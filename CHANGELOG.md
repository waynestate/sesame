# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - 2026-10-30

- Security: the signed-in user is looked up again on every request when the app's resolver implements the new `RefreshesUser` interface, and a user who no longer exists signs the session out. Before, `Sesame::user()` returned the copy saved at sign-in, so a user whose role was removed kept it until the session ended. The wayne-cas `UserResolver` implements it (a plain find by primary key, never an import).
- Added: a `sesame` Laravel guard driver. Set `auth.guards.web.driver` to `sesame` and the users provider to `eloquent`, and `auth()->user()` returns the SESAME user; a Laravel login, `onceUsingId()` or `actingAs()` still wins for the request.
- Fixed: the wayne-cas `WayneAuthenticate` middleware lets SESAME's login, callback and logout routes through, so it is safe in the global `web` group (it used to send `/login` to itself forever).
- Fixed: the wayne-cas `WayneCasable` interface and `ReadOnlyAttributes` trait agree on one signature, `public function getReadOnlyAttributes($persistedAttributes = []): array`. Models need that exact method (public, with the parameter).
- Fixed: resolving SESAME no longer builds the driver, so guests on http, artisan commands, queue workers and tests can ask who is signed in; the driver (and its https check) is built when someone signs in or out.
- Changed: the missing `sesame.user_resolver` error names the wayne-cas resolver class.
- Changed: the deploy layer's Squire kit is refreshed to 0.3.4 (the local .env.squire file is now git-ignored).

## [0.2.2] - 2026-10-29

- Changed: README leads with the gateway driver for Citadel apps and labels the oidc driver as the exception that needs its own Entra registration.
- Changed: the deploy layer's Squire kit is refreshed to 0.3.3 (release options can now live in ./.env.squire).
- Fixed: the oidc driver's default roles claim is wsu_roles, the name Entra sends; set roles_claim (and claims) to wsuRoles to keep the old name. Apps that relied on the old default must set it.

## [0.2.1] - 2026-10-29

- Added: agent-ready site (prompt blocks, Markdown twins, llms.txt), from Squire's kit.
- Fixed: the oauth2 peg on the site hangs as a cut key, since the driver ships.
- Changed: project names written as words except acronyms.
- Fixed: stale capability claims in the site and docs.
- Changed: the site shows the oidc driver.

## [0.2.0] - 2026-10-29

- Changed: the proof page moves to a private site that needs a Wayne State sign-in.

### Added

- The `oidc` driver: browser sign-in with OpenID Connect (authorization
  code flow with PKCE S256, `state` and `nonce` bound to the session, single
  use, ten-minute expiry), Microsoft Entra ID first. Discovery and the key set are
  cached through PSR-16 for `oidc.cache_ttl_seconds` (default and maximum
  3600) and each endpoint can be overridden; the ID token is
  checked for signature (RS256 by default, never `none` or HMAC), `iss`,
  `aud`, `azp`, `exp`, `nbf`, `iat` and `nonce`. No `prompt` or `max_age`
  by default (silent SSO); `step_up=1` adds the configured step-up
  parameters and requires a fresh `auth_time` (never in the future, and
  within `max_age`, 300 seconds when none is configured). Username from `accessid`
  (the `preferred_username` fallback is an opt-in, off by default, because
  users can change that claim), `iss`, `sub`, `tid` and `oid` passed to
  the resolver as stable account keys, roles from `wsuRoles`, MFA from
  `auth_time` and `amr` (or `oidc.assume_mfa`), and the claims in
  `SESAME_OIDC_CLAIMS` (default `wsuRoles,accessid`) passed to the resolver
  as attributes. Optional logout through Entra with
  `post_logout_redirect_uri`. Token, discovery and key-set requests never
  follow a redirect: a 3xx answer is an error (`oidc.token_redirect`,
  `oidc.discovery_unavailable`, `oidc.jwks_unavailable`), so the code,
  verifier and secret cannot be resent elsewhere. See "The oidc driver
  (Entra sign-in)".
- The oidc driver stands on `league/oauth2-client` (suggested, not
  required; a clear error names the install command when it is missing)
  for the OAuth mechanics, and on SESAME's own firebase/php-jwt key-set
  code for the ID token. Why: `docs/oidc-library-choice.md`.
- Laravel: `GET /login/callback` (`sesame.callback`) for the oidc driver,
  the redirect URI defaulting to it, and `sesame.mfa` reauth asking the
  provider for a step-up.
- `Sesame::returnTo()` and the `RemembersReturnTo` interface, so
  `sesame_login()` and the Laravel route send the user to the checked
  return-to address after a callback.
- `Setting::env()`, and `SESAME_OIDC_CLIENT_SECRET` read from the
  environment only, never from the playbook. The secret is held in a
  `SensitiveParameterValue` (hidden from `var_export`, `json_encode`, dumps
  and array casts; `serialize` refuses), and the parameters that carry it,
  the ID token, the code or the PKCE verifier are marked
  `#[\SensitiveParameter]`.
- README: a loud note that raw-PHP apps must raise
  `session.gc_maxlifetime` to at least SESAME's idle timeout.

### Changed

- Raw PHP: `sesame_logout()` is POST-only with a CSRF token. It runs only
  when the POST body's `sesame_logout_token` matches the session's token
  (new `sesame_logout_token()`, `Sesame::logoutToken()` and
  `Sesame::isLogoutToken()`); a GET answers 405 and a bad token 403, and
  neither touches the session. Apps must change a logout link to a POST
  form carrying the token. The Laravel `POST /logout` route stays in the
  `web` group and so under Laravel's CSRF check, now covered by a test.

- Laravel: the Guzzle client SESAME builds when the app binds none has
  redirects off.
- `ReturnTo` (every driver's login and logout return-to) now requires the
  service URL's scheme, host and effective port, and refuses a URL with a
  user name or password; before, the host alone was compared, so
  `https://app.example.test:8443/` passed for a service on 443. Plain http
  is accepted only for a localhost or 127.0.0.1 service URL.
- `DriverFactory::make()` takes the app session as an optional seventh
  argument (the oidc driver needs it).
- `JwksProvider` takes the algorithm per key lookup and a reason prefix,
  so the oauth2 and oidc drivers share it.
- `JwksProvider`: the first (cold) key-set fetch no longer starts the
  one-minute refetch cooldown, so a key rotated just after it is picked up
  by the next unknown key id (oauth2 and oidc).

## [0.1.3] - 2026-10-29

### Added

- The four shared WSU values (`SESAME_CAS_BASE_URL`, `SESAME_GATEWAY_ISSUER`,
  `SESAME_OAUTH2_ISSUER`, `SESAME_OAUTH2_JWKS_URL`) now resolve through
  `Waynestate\Sesame\Support\Setting::get()`: the environment wins, and when
  it is empty the private `waynestate/playbook` package supplies the value.
  `waynestate/playbook` is suggested, never required.
- A missing routed setting now fails with `Missing required setting
  SESAME_.... Set it in the environment, or install waynestate/playbook
  (see the README).` instead of naming the config key.

## [0.1.2] - 2026-10-29

### Fixed

- The CAS driver reads `serviceValidate` responses by namespace URI instead
  of prefix: a body that binds the `cas:` prefix to a different namespace
  can no longer be read as a success, while a body that binds another
  prefix (or none) to the real CAS namespace keeps working.

## [0.1.1] (2026-10-29)

### Added

- Step-up by authentication context: the gateway driver reads the signed
  assertion's `acrs` claim (authentication context ids `c1` through `c99`,
  anything else dropped) and carries the contexts on the `Identity`.
- `Identity::authContexts()` and `Identity::hasAuthContext(string $id)`:
  membership in the context list, never a prefix match.
- `Sesame::hasAuthContext(string $id)`: the context check against the current
  session, failing closed when there is no user or no identity.
- `sesame_require_context(string $id)` for the raw-PHP path: returns the
  app's user when the identity has the context, throws
  `AuthContextNotSatisfied` when it does not.
- The `sesame.context` Laravel middleware (`sesame.context:c1`): lets the
  request through when the identity has the context, answers 403 otherwise,
  and a JSON 403 with a `message` for JSON requests. A route without a
  context parameter fails closed.
- `AuthContextNotSatisfied`, the exception for a failed context check,
  carrying the context id.
- `cas.assume_mfa` (default `false`): when the CAS server enforces MFA for
  every sign-in, a successful validation then counts as an MFA sign-in. The
  identity's `amr` includes `mfa`, and its MFA time comes from the CAS
  `authenticationDate` attribute; without one, the MFA check fails with
  `NO_AUTH_TIME` instead of inventing a sign-in time.
- `DriverFactory::make()` with the gateway driver throws `InvalidConfig`
  naming the key when `gateway.public_key`, `gateway.issuer` or
  `gateway.audience` is null or empty, instead of passing an empty string
  into the driver.
- A truthy `slo` (or `single_logout`) config key is rejected with
  `InvalidConfig` at boot: the identity provider does not support single
  logout, so the setting is a mistake to fail on early rather than at the
  first logout.
- The `oauth2` driver: signs the user in from a JWT bearer token in the
  `Authorization` header, verified against the issuer's key set (a JWKS)
  fetched over HTTP. Only RS256 tokens are accepted; a token that claims
  `none` or an HMAC algorithm is refused before its signature is looked at.
  After the signature, the driver checks the issuer, audience, `exp` and
  `nbf` claims (with a leeway), then any required scopes (against the `scp`
  claim) and roles (against the `roles` claim). Microsoft Entra ID is the
  first planned issuer; any other RS256 issuer works with the same settings.
- `oauth2` config keys: `oauth2.issuer`, `oauth2.jwks_url` and
  `oauth2.audience` (all required), `oauth2.leeway_seconds` (default `60`),
  `oauth2.required_scopes` and `oauth2.required_roles` (default empty, which
  checks nothing), `oauth2.identity_claim` (`preferred_username` or
  `accessid`, default `preferred_username`), and
  `oauth2.jwks_cache_ttl_seconds` (default `3600`).
- `JwksProvider`: keeps the fetched key set in a PSR-16 cache for
  `oauth2.jwks_cache_ttl_seconds`, refetches at most once a minute when a
  token carries an unknown key id (so a rotated key is picked up without
  hammering the issuer), and fails closed on an unreachable issuer, a non-2xx
  answer, or an empty or malformed key set.
- The Laravel adapter wires the oauth2 driver: the PSR-18 client comes from
  the app's `ClientInterface` binding or Guzzle, and the key set cache comes
  from the app's cache, using the store named by `oauth2.jwks_store` (the
  default store when none is named).
- `firebase/php-jwt` as a dependency: JWKS parsing and RS256 verification
  come from the library, not from SESAME's own code.

### Changed

- Static analysis now includes Pinstripe's `phpstan/strict.neon` preset
  from the `waynestate/pinstripe` dev dependency instead of the hand-copied
  settings: PHPStan level 8, deprecation checks on, strict rules stay on.
  No behaviour changed.

## [0.1.0] (2026-10-27)

- First tagged release.
