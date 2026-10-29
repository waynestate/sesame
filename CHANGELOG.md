# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
