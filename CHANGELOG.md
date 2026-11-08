# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

- Changed: the GitLab pipeline pins the Preflight components to 0.2.0 (was 0.1.4).
- Docs: the Docker commands in the README and docs mount the shared `stack-composer-cache` volume, so Composer downloads are cached between runs.
- Fixed: the front page stamp reads 0.5 (it said 0.2), and its stack ring says Sourdough starts all five app shapes, not raw PHP only.
- Fixed: the README and the moving-from-wayne-cas guide name the constraint Booster's `cas-to-sesame` hop writes today, `waynestate/sesame:^0.4` (they said `^0.3`), and point at the 0.5.0 upgrade steps to take after the hop.
- Added: the `saml` driver (SESAME-5), a SAML 2.0 service provider for a Shibboleth-style IdP, opt-in. It stands on `onelogin/php-saml` (suggested, not required; the choice is in `docs/saml-library-choice.md`) in strict mode, requires a signed assertion, refuses SHA-1, IdP-initiated responses and replayed assertion IDs, binds each Response to the AuthnRequest this browser's session holds, checks the IssueInstant against the injected clock, and sends and honors RelayState only through the `ReturnTo` rule. The IdP's cross-site POST is parked in the PSR-16 cache under a single-use handle and checked on the 303's GET, where the `SameSite=Lax` session cookie is back. IdP metadata comes from an https URL or a file, certificates and the SP key from file paths, and no default IdP is assumed. Step-up sends `ForceAuthn`; logout is local.
- Added: `config/sesame.php`'s `saml` block and the `SESAME_SAML_*` settings; the Laravel adapter's `POST /login/saml/acs` (`sesame.saml.acs`) and `GET /login/saml/metadata` (`sesame.saml.metadata`) routes, outside the `web` group; the raw-PHP `sesame_saml_acs()` and `sesame_saml_metadata()`; `sesame_login()` treats `saml_handle` as a ticket.
- Security: SESAME-22: `composer.json` conflicts with `onelogin/php-saml` below 4.3.2 and `robrichards/xmlseclibs` below 3.1.5, the saml driver refuses them at boot, and a test fails when the installed versions are below that floor.
- Tests: a fixture IdP signs responses at runtime with a committed TEST-ONLY key pair (`tests/fixtures/saml`), with negative tests for unsigned, wrongly signed, tampered, wrong audience, issuer, destination and recipient, expired, replayed, unsolicited, SHA-1, DOCTYPE, wrapping and second-signature responses.
- Docs: "The saml driver (SAML 2.0 sign-in)" guide and "The saml driver's library choice"; the drivers, configuration, API, install, troubleshooting, Laravel and raw PHP pages cover the driver; BEST_PRACTICES S34, S35 and S47 move to `Enforced`.
- Changed: CI caches Composer downloads per lock file (`.composer-cache`, keyed on `composer.lock` and `deploy/composer.lock`), so a pipeline stops fetching every package from Packagist on every run. Images already come through the Dependency Proxy.

## [0.5.0] - 2026-11-07

- Added: `gateway.jwks_file` (env `SESAME_GATEWAY_JWKS_FILE`), a path to a JWKS file of the gateway's public keys by `kid`, the file Drawbridge's `keys:jwks` publishes (its current key and its next). It is read at boot through firebase/php-jwt's JWK parser, and a missing file, private key material, a repeated `kid`, more than 8 keys or a key of the wrong type for `gateway.algorithm` stops the app with `InvalidConfig`. Set one of `public_key`, `public_keys` and `jwks_file`.
- Changed: with exactly one key listed (`public_keys` or `jwks_file`), an assertion with no `kid` is verified with that key instead of refused; a `kid` that names another key is still refused, and with two or more keys a missing `kid` is still refused. `gateway.public_key` still ignores `kid`.
- Docs: the gateway driver guide covers the key set file and the rotation from the app's side.
- Added: `vue/`: the Vue adapter for single-page apps (`useSesame`, `createSesame`), not yet published to npm; a contract test keeps it in step with the status answer.
- Added: BEST_PRACTICES S38: the written rule for Composer audit ignores (who adds one, the reason and review date it carries, and when it must go).
- Changed: The canonical repository is git.wayne.edu (ADR-0021); docs, links and package metadata no longer point at the archived nougat copy.
- Added: the Proof page records its last run.
- Changed: BEST_PRACTICES re-check: S18 moves to `Enforced` (the cas login binding is built), S36 moves to `Partly enforced` (the oauth2 driver enforces the JWT checks; the RFC 7662 introspection path is still open) and S40 moves to `Enforced` (the wayne-cas shim is built as a thin adapter over the core).
- Security: the front-page deploy layer moves to Symfony 7.4.23 (symfony/http-foundation and symfony/yaml advisories, including CVE-2026-118514).
- Added: the Wayne Auth bridge, `Waynestate\Sesame\WayneAuth\WayneAuth`, deprecated on arrival. Apps on the old Wayne Auth SSO cookie keep `new WayneAuth($config)`, `authenticateSSO()`, `getAccessID()` and `_parseSSOCookie('v')`, and sign people in through SESAME's cas driver instead; the one code change is the line that loaded the old class, which now requires the app's SESAME config file calling `WayneAuth::boot()`. Guests go to CAS from inside `authenticateSSO()` (or get `false` with `on_guest => deny`), a ticket that does not validate throws `AuthenticationFailed` rather than looping, the password mode (`'d'`) throws because CAS never releases the password, and `accessid_case` fixes the AccessID's case for apps that compare it. `boot()` refuses to start while the old class is still loaded, and re-reads SESAME's session values for apps that start the session before Composer's autoloader.
- Docs: a "Moving off Wayne Auth" guide (the one-line swap, the options, the call-by-call differences, the checks before shipping and the native next step), linked from "Moving from wayne-cas", the API reference and the README.
- Tests: the bridge is covered against the real cas driver on the fake HTTP client, and a fixture page written the Wayne Auth way signs in end to end under PHP's built-in server.
- Changed: The Laravel guide says Laravel 13 apps must implement `RefreshesUser`, because JSON sessions hand back the stored user as an array.

- Docs: the docs pages sit closer to the front page. The light out of the open door now falls behind each page's title (opening once when motion is allowed), the Overview's picture of the shop hangs in a slate frame, and the badges sit in a row. The after-hours dark mode moved off the green-black floor to a warm dark bench: green stays where the front page puts it (the counter footer, the Open sign, the mark), and inline code and callout colors follow the theme in dark.
- Docs: the docs pages wear the front page's key shop. The same shop sign and main menu (Docs marked current, the Open sign hung on its strings), Bungee titles with a brass key under each, kicker labels, slate code slabs with the gold Copy button, notes and callouts as paper key tags, job-ticket pager and the green counter footer. A derived after-hours dark mode, and motion only when the system allows it. The project name reads SESAME in page titles.
- Changed: NativeSession keeps the old session for a 60 second grace after regenerate (marked with a destroyed-at time), then rejects its reuse and logs sesame.session_anomaly; pass graceSeconds: 0 for the old immediate delete (S43). Laravel is unchanged.
- Changed: the cas driver binds each sign-in to the browser that started it: it stores a pending-login marker in the app's session before the redirect to CAS, and a returning ticket is accepted only against a live marker (single use, ten minutes). A ticket with no live marker is refused before any HTTP call, which blocks login CSRF. Apps that build the driver by hand must pass the app's SessionPort to `DriverFactory::make()`, and an app that must accept a ticket for a login it did not start (a link from the CAS login page) must set `cas.require_pending_login` to false.
- Added: `cas.require_pending_login` (env `SESAME_CAS_REQUIRE_PENDING_LOGIN`, default `true`) switches the cas login binding off.
- Tests: the cas login binding is covered end to end: a ticket with no marker, an expired marker, a reused marker, the natural start-and-return flow, the five-marker cap, the switch off, and the missing-session refusal.
- Added: `Sesame::sessionStatus()`, the session facts a single-page app needs (who, driver, sign-in time, idle and absolute expiry, the logout token), with an optional closure that adds the app's own user fields. The raw PHP function, the Laravel route and the Vue package follow.
- Added: `sesame_status()` (raw PHP) and `GET /sesame/status` (Laravel), the JSON answer a single-page app reads to see who is signed in (200 with the session facts, 401 when signed out, never cacheable); `authenticatedAt` and `authTime` in `sessionStatus()` are now always UTC.
- Docs: the docs site is built by the shared builder and checked in `composer ci`.
- Docs: the docs site now exists. Squire's docs builder renders `docs/` into `site/docs/`: an Overview page from the README's opening, the oidc library choice note, and a generated environment reference table covering all 55 `SESAME_*` settings. `composer docs` builds it, and `composer docs:check` gates it in CI.
- Docs: the README session cookie section also says to leave `session.sid_length` and `session.sid_bits_per_character` at PHP's defaults; BEST_PRACTICES S45 is `Documented`.
- Added: `sesame.session_anomaly`, a warning event for sessions whose state looks wrong or tampered with, apart from the normal timeout logouts. `Sesame::user()` logs it with `reason` `missing_timestamps` (a user stored without the session's timestamps; the session is signed out right after, so the anomaly precedes the `sesame.logout`) or `bad_session_ref` (a stored `sesame.session_ref` that is not a 16-character lowercase hex string; the user stays signed in and the bad value is never echoed into the log). A session without a reference at all is not an anomaly.
- Changed: the front-page deploy layer pins HELMET ^0.3, PROCTOR ^0.4 and CREED ^0.1 (lock: HELMET 0.3.0, PROCTOR 0.4.0, CREED 0.1.6).
- Changed: CI pins Preflight 0.1.4 (GitLab component includes and the GitHub reusable workflow at its tag commit); the deploy layer sets minimum-stability to stable.
- Docs: a "session cookie (name and SameSite)" paragraph in the raw PHP section tells apps to use a `__Host-` cookie name and `SameSite=Lax` (never `Strict`, which drops the cookie on the return from the identity provider), says HELMET sets both for raw PHP, and gives the Laravel settings; BEST_PRACTICES S4 and S41 are now `Documented`.
- Docs: SESAME fails closed on a redirect and on a client exception during the CAS validation call, and tests were added to prove it (S42).
- Docs: a "Refusing a sign-in" section says what a resolver does on a deny, and the CERT Polska CVE-2026-15243 check is recorded (Java clients only; no PHP client named).
- Added: `scripts/a11y.sh` (Linux, macOS) and `scripts/a11y.ps1` (Windows), a site accessibility gate that runs axe-core with the WCAG 2.2 AA tag set against the committed front page and every docs page, in light and dark at two widths, inside Docker so no host browser is needed.
- Fixed: code blocks on the docs pages are keyboard focusable (`tabindex="0"`), so a block that scrolls sideways on a narrow screen can be reached and scrolled from the keyboard (axe `scrollable-region-focusable`). The docs builder adds the attribute; the pages were rebuilt.
- Fixed: "On this page" sub-items on the docs pages carry the base item classes again, so the kit's block link styles apply and the links meet the minimum target size (axe `target-size`). The docs builder sets both classes; the pages were rebuilt.

## [0.4.0] - 2026-11-03

- This is a pre-1.0 minor: apps that pin `^0.3` stay on 0.3.x until they move their constraint to `^0.4`. The `gateway` driver now refuses a token whose `typ` header is `at+jwt`, so read the Security line first.
- Docs: the cas quick start's Guzzle client now sets `allow_redirects` to false and `verify` to true, with a note that PSR-18 has no standard option for either (S42).
- Added: `session_ref`, a per sign-in correlation id that ties the auth events of one sign-in together in the log. `Sesame::login()` creates a fresh 16-character lowercase hex reference and stores it in the session under `sesame.session_ref`, and `sesame.login` and `sesame.logout` carry it in their context. It is not the session ID and never derived from it; a value read back from the session that is not a 16-character lowercase hex string is ignored, so a tampered session cannot inject log text.
- Added: `Identity::isFromNewLogin()` and `Identity::usedLongTermToken()` read the CAS `isFromNewLogin` and `longTermAuthenticationRequestTokenUsed` attributes (`true`, `false`, or `null` when the server did not say or sent anything else), so an app can tell a fresh login from a remembered one.
- Added: optional PSR-3 logging of authentication events. `Sesame` takes an optional sixth constructor argument, and the Laravel adapter passes the app's logger when the container has one; `sesame.log` (env `SESAME_LOG`, default `true`) turns the events off. The events are `sesame.login`, `sesame.login_failed`, `sesame.login_refused` and `sesame.logout`, each carrying `event`, `driver`, `username` and `at` in its context (`reason` on logout); tickets, session IDs, tokens and failure messages are never logged.
- Added: `oauth2.require_at_jwt` (env `SESAME_OAUTH2_REQUIRE_AT_JWT`), an opt-in check that the access token's `typ` header is `at+jwt` or `application/at+jwt`. It is off by default because Microsoft Entra v2 access tokens are typed `JWT`, so requiring it would refuse every Entra token.
- Security: the `gateway` driver refuses a token whose `typ` header is `at+jwt` or `application/at+jwt`, so an OAuth2 access token cannot be replayed as a gateway assertion. A missing `typ` or `JWT` still works.
- Docs: BEST_PRACTICES tags now match the code: the entries whose fix is already in `src/` and `tests/` are re-tagged (`Enforced`, `Documented` or `Partly enforced`) with the code and test file:line named, and the gap list at the bottom drops the items that are closed.
- Docs: the "Moving from wayne-cas" section now leads with the easy way, Booster's `cas-to-sesame` hop (planned with `--sesame`, needs the plan to end on PHP 8.3 or newer), then the by-hand mapping, then what Booster leaves for you (Duo 2FA is not carried, verify login on the pre-production host).
- Docs: the cas quick start's Guzzle client now sets a `timeout` and `connect_timeout` (Guzzle has no total timeout by default), and the oauth2 quick start does the same.
- Docs: a "Which key to store" paragraph tells apps to key their users on the driver plus the identity provider's stable identifier, never an email or a display name.
- Docs: a note tells apps to log `AuthenticationFailed` messages and show the user one generic line, since the message can carry the CAS failure code.
- Added: `gateway.public_keys`, a map of `kid` to public key, so the gateway driver can verify against two keys at once (the assertion's `kid` header picks the key) for key rotation without a coordinated window.
- Changed: CI pulls its Docker Hub images through the GitLab Dependency Proxy (the group's CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX), so pipelines no longer hit Docker Hub's anonymous pull limit.
- Changed: docs and package metadata name nougat as the canonical repository, with git.wayne.edu and GitHub as read-only mirrors, until git.wayne.edu has CI runners.

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
