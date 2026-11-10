# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

- Docs: the README's `gateway` row no longer names an old Drawbridge version.
- Changed: the deploy layer requires PROCTOR ^0.15 (locked 0.15.0, was ^0.12): 0.13 checks the release cooldown against the CREED policy and 0.14 adds `script-pair`; 0.15 adds `mysql56-ci`; all of these only warn, nothing breaks.
- Docs: three BEST_PRACTICES backlog lines (the PHP test matrix, the README Docker commands, the README client example) are marked Done.
- Changed: the deploy layer requires PROCTOR ^0.12 (locked 0.12.0, was ^0.11): two new warning-only checks, nothing breaks.
- Changed: the deploy kit is Squire 0.10.7 (was 0.10.6): it ships a `.gitattributes` that pins line endings, so the deploy layer clears PROCTOR's line-ending warnings.
- Changed: the docs, agent-ready and deploy kits are Squire 0.10.6 (was 0.10.5): the docs builder and agent-ready generator see every file on Docker Desktop mounts.
- Site: `assets/stack-env.js` knows Parity, so a pre-production page links Parity's pre-production site and a dev page links its local `site/` folder. Until now Parity, of all parts, was the one link that ignored its environment.
- Site: Parity is out; the party list links its site.
- Deploy kit refreshed to Squire 0.10.5.
- Docs: BEST_PRACTICES lists the drivers SESAME ships and states one pinning rule.

## [0.7.3] - 2026-11-09

- Docs: the upgrading guide has a 0.7.3 section (no breaks, no library code changes), and the docs index and BEST_PRACTICES name release 0.7.3.
- Docs: Parity joins the party. The README cast, the instance runner page's cast and the front page's key ring carry Parity next to Squire, with its spirit-level mark: the stack's new tool that runs Citadel on your computer (its PHP image, its proxy and MySQL 5.6), where a local copy signs in with SESAME's fake driver. 0.1.0 is coming and it has no site yet, so its tag links nowhere. One more key on the ring, and this one is level.
- Changed: refreshed version mentions. The README and the moving-from-wayne-cas guide say Booster 1.14.0 and later write `waynestate/sesame:^0.7` (they said `^0.6`, which Booster 1.12.0 and 1.13.0 wrote; apps those hopped take the 0.7.0 upgrading step). The dev tools require Pinstripe ^0.3 (lock 0.3.0, was ^0.1.4 at 0.1.4), which bans `var_dump()`: the one in `tests/OidcDriverTest.php`, which proves a dump never shows the client secret, carries a reasoned `pinstripe.banned.varDump` ignore. Open sesame: the doors now match the keys.

## [0.7.2] - 2026-11-09

- Fixed: the docs gave the wrong address for the university's CAS server. The README, the cas driver guide and the Wayne Auth guide set `cas.base_url` to a `login.wayne.edu` address that answers 404, so an app that copied it never reached the sign-in. The address is `https://academica.aws.wayne.edu/cas`; people still see the familiar `login.wayne.edu` screen, because Academica sends the browser there to sign in. SESAME has no built-in CAS address, so no code changes, and a new test keeps every example on the working address.
- Fixed: the Wayne Auth guide said `cas.base_url` could be left out of `WayneAuth::boot()` when Playbook is installed or `SESAME_CAS_BASE_URL` is set. The bridge reads only the array it is given; the guide now says so and shows how to pass `Setting::get('SESAME_CAS_BASE_URL')` instead.
- Docs: the upgrading guide has a 0.7.2 section with the one value to check. MAINTAINING names the CAS server the same way.
- Docs: BEST_PRACTICES, the CI file and the Oracle check say their reasons in plain words instead of internal decision numbers.
- Changed: CI pins the Preflight components to 0.5.0 (was 0.4.0), and the composer-audit job takes the component's own image instead of naming one; the a11y pin test expects 0.5.0, and BEST_PRACTICES S48 names `@0.5.0`.
- Docs: the Proof page records the 2026-11-09 run on the 0.7.2 fixes (863 tests, 2517 assertions, on PHP 8.3, 8.4 and 8.5, and `composer ci`).

## [0.7.1] - 2026-11-09

- Fixed: a session past its idle (2 hours) or absolute (12 hours) timeout no longer ends in a blank 500. `NativeSession::invalidate()` closed the session and started nothing in its place, so the cas driver's pending-login write that follows a timeout (in `sesame_login()` and the Wayne Auth bridge) threw `LogicException: No session is active`. It now leaves the logout marker under the old ID and starts a fresh, empty session under a new ID from `session_create_id()`, never one from the request, with strict mode kept as the app set it; when headers are already sent it leaves the session closed, as before. Sign-out takes the same path.
- Changed: on the native PHP session (raw PHP and the Wayne Auth bridge), because sign-out (`sesame_logout()`, `sesame()->logout()`, `sesame()->signOutLocal()`) now leaves a fresh, empty session open, an app that calls `session_start()` itself after it in the same request (after `logout()` or `signOutLocal()`, or in a shutdown function after `sesame_logout()`) gets PHP's notice "Ignoring session_start() because a session is already active". Drop that call, or guard it with `session_status() !== PHP_SESSION_ACTIVE`.
- Added: `Waynestate\Sesame\Exception\ProviderUnavailable`, a subclass of `AuthenticationFailed`. The cas driver throws it when the ticket check cannot reach the CAS server, runs past the HTTP client's timeout, or gets a 5xx answer (`cas.unavailable`); before this the client's own exception (for Guzzle, a `ConnectException`) escaped `sesame_login()` and `authenticateSSO()`. The client's exception is never chained, because it holds the ticket URL. A 4xx answer is still a plain `AuthenticationFailed`. Catch `ProviderUnavailable` first and answer 503.
- Changed: the oauth2, oidc and saml drivers and the JWKS provider throw `ProviderUnavailable` (same messages and reason codes) where they threw `AuthenticationFailed` for a provider they could not reach, so `catch (AuthenticationFailed $e)` still catches them; the oidc token exchange turns a client exception into `ProviderUnavailable` (`oidc.token_exchange`).
- Changed: a script request from a guest (an XHR with `X-Requested-With: XMLHttpRequest`, or a `fetch` whose first `Accept` type is JSON) gets a 401 with `{"signedIn":false}` and `Cache-Control: no-store` from `sesame_login()` and the Wayne Auth bridge's `authenticateSSO()`, instead of a redirect to the identity provider the script could not follow. Page navigations and PJAX requests are still redirected, and the bridge's `on_guest => deny` still answers `false`.
- Docs: the README, session timeouts, cas driver, Wayne Auth, single-page apps and raw PHP guides, the API reference and the upgrading guide (a 0.7.1 section) say what a timed-out visitor and a script get, and how to catch `ProviderUnavailable`; the session lifetimes are given in hours as well as minutes; troubleshooting has an entry for `No session is active` after a break and rewrites the hung-CAS cause; BEST_PRACTICES S2, S7, S16, S18, S22, S23 and S42 follow the new behavior, and its line citations are current.
- Changed: the `wayneui-copies` and `composer-ci` CI jobs run Composer 2.11.1 (was 2.10.3, no longer maintained), pinned by its multi-arch list digest; 2.11 fixes GHSA-c968-33g3-wh7p and GHSA-fm2w-xgf2-f9fh.
- Changed: the deploy kit is Squire 0.10.2 (was 0.10.1): the kit's `php.ini` sets `session.cookie_samesite = Lax` (the value `NativeSession`'s cookie check asks for); the fallback base names its images in full (`docker.io/library/...`) with Composer as a build argument; and the portable image drops `node_modules/` after the build. BEST_PRACTICES S45 names kit 0.10.2.
- Changed: the deploy layer requires HELMET ^1.3 (locked 1.3.0, was ^1.0 at 1.0.1) and CREED ^0.1.8 (locked 0.1.8, was ^0.1 at 0.1.7); PROCTOR stays ^0.11 at 0.11.0. The dev dependency on Pinstripe moves to ^0.1.4 (locked 0.1.4, was ^0.1 at 0.1.0).
- Changed: the `proctor` CI job on `deploy/` blocks the pipeline (it was allowed to fail); PROCTOR 0.11.0 reports a pass there, with warnings and no failures.
- Docs: the Proof page records the 2026-11-09 run on the 0.7.1 fixes (860 tests, 2511 assertions, on PHP 8.3, 8.4 and 8.5, and `composer ci`).
- Docs: the SAML library choice page, the upgrading guide, BEST_PRACTICES and the saml driver's comments say "the security floor" in plain words instead of an internal item number; the heading "The floor" is now "The security floor" (anchor `#the-security-floor`).
- Docs: MAINTAINING names the maintainer as a role (the stack maintainer), reached through an issue on git.wayne.edu.
- Docs: the `NativeSession` constructor docblock says `checkSessionSettings` also covers the cookie flag check (HttpOnly, Secure on https, a Lax or Strict SameSite; checked, never set), and BEST_PRACTICES' `NativeSession.php` line citations follow it.

## [0.7.0] - 2026-11-09

- Docs: the upgrading guide has a 0.7.0 section: what to set so the new cookie flag warning stays quiet, and the `^0.7` constraint step.
- Changed: the deploy kit is Squire 0.10.0 (was 0.7.1): release.sh keeps Composer and npm download caches in the app folder and the gate takes SQUIRE_PHP_RUNTIME; the compose file, health.php and .env.squire.example follow the template, and the portable image files are added.
- Changed: the deploy kit is Squire 0.10.1 (was 0.10.0): the fallback base image copies Composer 2.11.1 (was 2.10.3, no longer maintained); the deploy layer requires PROCTOR ^0.11 (locked 0.11.0, was ^0.8 at 0.8.0).
- Added: NativeSession warns once per request when the session cookie lacks HttpOnly, Secure (on https) or a Lax or Strict SameSite, and throws with checkSessionSettings: true; it still never sets cookie flags (S3).
- Docs: the upgrading guide's 0.7.0 section and the troubleshooting entry for `SESAME: the session cookie is sent without` give the fix per case: Squire kit 0.10.2 or later (`squire update`, its `php.ini` sets `session.cookie_samesite = Lax`), Laravel (unaffected, it sets its own cookie), and raw PHP on a host whose `php.ini` you do not control (`helmet_boot()` or three `ini_set()` lines before `session_start()`); `Lax` keeps CAS, OIDC and SAML working.
- Docs: truth pass for 2026-11-09. Every raw-PHP example sets the cookie flags with `session_set_cookie_params()` before `session_start()`, so following the docs gives no warning; the README, raw PHP guide, session timeouts table, API reference, troubleshooting and the Wayne Auth guide describe the cookie flag check; BEST_PRACTICES cites current line numbers throughout, S2 says which `session_start()` and cookie calls `NativeSession` does make, S11 says the CAS URL check parses the URL (it said a prefix test), S12 names the `ST-` prefix, S41 says a missing or `None` SameSite is now caught but `Strict` is not, and S45 names kit 0.10.0; MAINTAINING lists every runtime dependency and every `composer ci` step.

## [0.6.3] - 2026-11-09

- Docs: the upgrading guide has a 0.6.3 section, and the README, Laravel guide and wayne-cas guide say JSON sessions work with the shim from 0.6.3.
- Docs: freshness pass for 0.6.2. The upgrading guide has a 0.6.2 section, and the wayne-cas guide, Laravel guide, README and troubleshooting say a wayne-cas app on an Eloquent model needs no resolver subclass from 0.6.2 on; the front page stamp follows the release (`data-docs-version`, it said 0.5), names SAML, fixes the wayne-cas guard row, and its agent prompts know the saml driver and the 0.6.2 floor; the driver tables say `gateway` waits on a live Drawbridge; the check count is seven.
- Fixed: the wayne-cas shim's refreshUser() looks the user up again when the session hands the stored user back as an array (Laravel 13's JSON session serialization). It reads the array's primary key and returns the model through query()->find(), signing the session out when the account is gone; before this it passed the array through, so the guard saw nobody while the middleware let the request in. A wayne-cas app on JSON sessions no longer needs a resolver of its own.

## [0.6.2] - 2026-11-09

- Fixed: the wayne-cas shim's `refreshUser()` works with an Eloquent user model. It checked for a declared static `find` method, which Eloquent models do not have, so every signed-in request failed with `InvalidConfig`. It now looks Eloquent models up through `query()->find()` and keeps the static `find` path for other classes.
- Changed: `NativeSession` keeps a client from choosing its own session ID without a code change (BEST_PRACTICES S3). Whenever no session is active (when it is built before `session_start()`, and before every session start of its own) it turns `session.use_strict_mode` and `session.use_only_cookies` on with `ini_set()`; when the app already started its session with either one off, it raises one `E_USER_WARNING` per request naming the setting and the fix, and the session keeps working. The new `checkSessionSettings` option makes that a hard stop (`true`, a `LogicException` on every call) or turns it all off (`false`). Every raw-PHP example now sets strict mode before `session_start()`; HELMET's `helmet_boot()` and Squire's php.ini already set both. The Laravel adapter is not affected.
- Fixed: `NativeSession::regenerate()` keeps the new session ID it made when `session.use_strict_mode` is on (as HELMET and Squire's php.ini set it): PHP's strict mode had swapped in an ID of its own, so the old session's `sesame.new_session_id` marker named an ID that never existed. The new session is stored once with strict mode off, then reopened with the app's setting back, and the NativeSession tests now run with strict mode on, as production does.
- Changed: CI pins the Preflight components to 0.4.0 (the proctor job clones the full history, so the override-approver check can read git blame).
- Docs: BEST_PRACTICES S20 (CAS single logout) is tagged `Documented`, not `Not yet`: Academica never sends single logout, and the entry already says apps rely on SESAME's session timeouts (S5).

## [0.6.1] - 2026-11-08

- Fixed: SESAME's routes are controller actions instead of closures, so `php artisan route:cache` and `optimize` work in an app that uses SESAME (a Citadel release runs optimize).
- Docs: the README and the wayne-cas guide say Booster 1.12.0 and later write `waynestate/sesame:^0.6` (an app hopped by an earlier Booster takes the 0.5.0 and 0.6.0 upgrade steps), and BEST_PRACTICES S45 is `Checked` now that Squire's CI fails if its kit `php.ini` sets `session.sid_length` or `session.sid_bits_per_character`.
- Changed: the deploy layer runs Squire's kit 0.7.1 (was 0.6.0): `.squire/php.ini` no longer sets the deprecated `session.sid_*` lines, web requests get a `disable_functions` list and Apache hardening (`.squire/php-web.ini`, `.squire/apache-hardening.conf`), and opcache gets production settings with `SQUIRE_OPCACHE_VALIDATE` as the one switch.
- Changed: the front page deploy layer requires HELMET ^1.0 (lock at 1.0.1, was 0.4.0), like the rest of the stack; its shared front controller already runs on HELMET 1.0 in Squire, Sourdough and Pinstripe.
- Docs: BEST_PRACTICES S44 now asks for `Clear-Site-Data: "cache", "storage"` and says why `"cookies"` stays out on a shared host, so the row is `Enforced`; S48 describes the Preflight pins as they are (four components at the `@0.3.1` release tag) and is `Checked`, since PROCTOR's `preflight-pinned` check warns on an unpinned include.
- Changed: the local a11y scripts run Preflight's own checker (vendored from 0.3.1 and pinned by a test), so they check what CI checks: axe-core 4.14.0, 320 and 1280 px, and the reflow check.

## [0.6.0] - 2026-11-08

- Added: CI runs Preflight's a11y component (0.3.1) on site/, every page in light and dark at 320 and 1280 px with the WCAG 1.4.10 reflow check, blocking.
- Changed: CI pins the Preflight components to 0.3.1 (fixed cache keys, and caches saved even when a job fails; D-072).
- Changed: CI saves its Composer and npm caches even when a job fails, so a retry after a dropped download fetches only what is missing (D-072).
- Changed: CI caches Composer and npm downloads under a fixed key, so a lock change downloads only what changed (D-072).
- Added: RFC 7662 token introspection for opaque bearer tokens in the `oauth2` driver, behind `oauth2.introspection_url` (env `SESAME_OAUTH2_INTROSPECTION_URL`). When set, the driver posts a non-JWT bearer token to the endpoint with `oauth2.introspection_client_id` and `oauth2.introspection_client_secret` (env `SESAME_OAUTH2_INTROSPECTION_CLIENT_ID` and `SESAME_OAUTH2_INTROSPECTION_CLIENT_SECRET`) as HTTP Basic auth (client id and secret form-encoded first, as RFC 6749 2.3.1 requires), accepts only a `200` JSON answer whose `active` field is `true`, and applies the configured issuer, audience, expiry and not-before checks to the claims it returns. The URL must be `https://` (`http://localhost` and `http://127.0.0.1` are allowed for tests), and the client id and secret are required when it is set.
- Added: `oauth2.introspection_cache_ttl_seconds` (env `SESAME_OAUTH2_INTROSPECTION_CACHE_TTL_SECONDS`, default `60`, maximum `300`; zero disables the cache), which caps how long an accepted introspection answer is kept under a SHA-256 hash of the token; the raw token is never cached, and a numeric `exp` claim shortens the lifetime further.
- Changed: BEST_PRACTICES S36 moves to `Enforced`: the oauth2 driver's JWT checks and its new RFC 7662 introspection path are both covered by tests.
- Docs: the README, configuration reference, Laravel guide and oauth2 with Entra guide cover the four new introspection settings.

- Fixed: the docs menu closes with Escape and hands focus back to its button (docs kit synced from Squire).
- Fixed: the docs kit's copy button no longer covers the first line of code when it says Copied or Failed: over the code it keeps its resting width and shows a check or a cross when the word does not fit (docs kit synced from Squire).
- Docs: MAINTAINING.md says saml ships (opt-in); BEST_PRACTICES.md is dated 2026-11-08 and drops the answered composer-audit question.
- Changed: the deploy layer requires PROCTOR ^0.8 (locked 0.8.0).
- Added: the native session leaves a "logged out at" marker in the session store when `invalidate()` retires a session (the `logoutReuseTtlSeconds` option of `new NativeSession()` sets the window, 60 seconds by default, `0` destroys the session outright instead); a request that presents that ID gets a fresh empty session, and while the marker is inside the window it logs `sesame.session_anomaly` with `reason` `logged_out_session_reused` once per reuse (BEST_PRACTICES S27).
- Docs: the README art shows `saml` as shipped and opt-in (D-058): the README art kit now re-takes the front page's reference photos in `site/screenshots/` itself, so the gallery's pegboard tags saml Opt-in, as the page does, instead of "later" from a photo taken before the driver shipped. The gallery alt text and `site/README.md` say the same.
- Changed: the pipeline pins every Preflight CI component to 0.3.0 (preflight@0.3.0); component image inputs are plain image names, since Preflight adds the Dependency Proxy prefix itself.
- Fixed: the Vue adapter's logout() sends Laravel's CSRF token as _token (from <meta name="csrf-token">, or the new csrfToken option), so signing out from a Vue page works in Laravel, not only in raw PHP (SESAME-7b).
- Added: a vue job in the GitLab pipeline runs the Vue adapter's tests and type check.
- Docs: a decision page for the 60 second regenerate grace (why, how to turn it off with `graceSeconds: 0`, what is logged after it).
- Fixed: the pipeline's PROCTOR jobs no longer pass `--policy`, which PROCTOR 0.7 refuses in CI; PROCTOR checks against the CREED policy it bundles.
- Changed: the GitLab pipeline pins the Preflight components to 0.2.0 (was 0.1.4).
- Changed: the front page's deploy layer (`deploy/`) runs Squire's kit 0.6.0 (was 0.5.0): `scripts/release.sh` asks PROCTOR's deploy gate for a go or no-go on each release (a report, not a stop, unless `SQUIRE_PROCTOR=enforce`), and `.env.squire.example` lists the new setting. The layer's pins move to HELMET `^0.4` (locked 0.4.0) and PROCTOR `^0.7` (locked 0.7.0).
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
