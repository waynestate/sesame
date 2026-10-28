# SESAME best practices

This guide covers authentication and session handling for PHP apps that use SESAME, `waynestate/sesame`. It is for WSU developers who add login to an app, and for the people who maintain SESAME. It follows the design in ADR-0004: SESAME returns a verified identity, and the app owns the session. Every driver (`cas`, `gateway`, `saml`, `oauth2`, `fake`) ends in the app's `findOrImportUser(Identity)`.

Each practice has a short statement, one sentence of why, the sources, and how the stack holds it. The tags are:

- `Enforced`: code makes it impossible, or fails. The entry says what.
- `Checked`: PROCTOR or CI warns or fails.
- `Documented`: written down only.
- `Not yet`: a gap.

The tags describe the repo as it stands on 2026-10-27. Only the `fake` and `cas` drivers exist. `gateway`, `saml` and `oauth2` are design only, so their practices are tagged by what is written down, not by what runs.

## 1. Sessions

### S1. Issue a new session ID at login
Regenerate the session ID when authentication succeeds, and end the old one. Do this before anything is stored in the session.
Why: an ID that an attacker planted before login must not become an authenticated session.
Sources: [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), [ASVS 5.0 V7.2.4](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x16-V7-Session-Management.md), [PHP session_regenerate_id](https://www.php.net/manual/en/function.session-regenerate-id.php).
How the stack holds it: `Enforced`. `Sesame::login()` calls `regenerate()` before it stores the user (`src/Sesame.php:44`). `SesameTest::testLoginRegeneratesBeforeItStores` pins the order. There is no switch to turn it off. See the gap list for the `delete_old_session` trade-off.

### S2. The auth library never starts a session or sets cookie flags
The app starts the session, hardened. The library only reads and writes a session that is already active.
Why: one place owns cookie settings, so no library can weaken them.
Sources: [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html).
How the stack holds it: `Enforced`. `NativeSession` throws `LogicException` when no session is active and never calls `session_start()` or sets a cookie (`src/Session/NativeSession.php:62-67`).

### S3. Strict mode, cookie-only IDs, and HttpOnly, Secure and SameSite cookies
Set `session.use_strict_mode=1`, `session.use_only_cookies=1`, `HttpOnly`, `Secure` on HTTPS, and `SameSite=Lax`. Do not use `Strict` on the cookie that carries login state (see S41).
Why: strict mode stops an attacker from choosing the ID, and the flags limit theft and cross-site use. The CAS server sends the browser back to the app with a cross-site navigation, and a `Strict` cookie is not sent on it.
Sources: [PHP session configuration](https://www.php.net/manual/en/session.configuration.php), [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), [OWASP CSRF Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html) (SameSite is defence in depth only).
How the stack holds it: `Documented`. The README tells apps to let HELMET set the flags. HELMET's `SessionHardening::apply()` sets them, but nothing checks that the app calls it, and `NativeSession` does not check that strict mode is on.

### S4. Use a `__Host-` session cookie name
Name the session cookie with the `__Host-` prefix. The browser then requires `Secure`, `Path=/` and no `Domain`.
Why: a sibling subdomain under `*.apps.wayne.edu` cannot overwrite a host-only cookie.
Sources: [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html) (marks `__Host-` "recommended for session IDs"), [Cookie prefixes in the cookie spec draft](https://datatracker.ietf.org/doc/draft-ietf-httpbis-rfc6265bis/) (still an Internet-Draft, revision 22, waiting at the RFC Editor as of 2026-10-27, so there is no RFC number yet).
How the stack holds it: `Not yet`. Neither SESAME nor HELMET sets a cookie name or prefix. In Laravel the name comes from `config/session.php`, so the adapter should document a `__Host-` name there too.

### S5. Enforce idle and absolute timeouts on the server
End the session after a period of inactivity and after a maximum lifetime, whatever the activity. Do not rely on PHP's garbage collection for this.
Why: a stolen or forgotten session should stop working on its own. PHP's `gc_maxlifetime` only marks data as collectable, and collection is random.
Sources: [ASVS 5.0 V7.3.1 and V7.3.2](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x16-V7-Session-Management.md), [NIST SP 800-63B-4](https://pages.nist.gov/800-63-4/sp800-63b.html) (checked second pass: AAL2 says SHOULD be no more than 24 hours overall and 1 hour idle; AAL3 says SHALL be no more than 12 hours overall and SHOULD be no more than 15 minutes idle), [PHP session configuration](https://www.php.net/manual/en/session.configuration.php).
How the stack holds it: `Not yet`. SESAME stores `authenticatedAt` in the identity (`src/Sesame.php:46`), but nothing reads it. No timeout is enforced or configurable.

### S6. Keep session contents small and plain
Store a user key and little else. Do not put a full model object or the raw IdP attributes in the session.
Why: serialized objects grow stale, leak personal data into session storage, and widen the deserialization surface.
Sources: [OWASP Deserialization](https://cheatsheetseries.owasp.org/cheatsheets/Deserialization_Cheat_Sheet.html), [ASVS 5.0 V1.5.2](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x10-V1-Encoding-and-Sanitization.md).
How the stack holds it: `Not yet`. The design says the session holds the user key, but `Sesame::login()` stores whatever `findOrImportUser` returns plus the whole `Identity` with its attributes (`src/Sesame.php:45-46`).

### S7. Logout ends the app session first, then the IdP session
Destroy the server-side session, then send the browser to the IdP logout. A failed redirect must not leave a live session.
Why: deleting only the cookie, or only the IdP session, leaves a usable session.
Sources: [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), [ASVS 5.0 V7.4.1](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x16-V7-Session-Management.md).
How the stack holds it: `Enforced`. `Sesame::logout()` forgets the keys and invalidates before it asks the driver for the IdP URL (`src/Sesame.php:67-74`). `SesameTest::testLogoutInvalidatesBeforeTheIdpUrlIsBuilt` covers it. `NativeSession::invalidate()` empties the data and rotates the ID, but does not call `session_destroy()` or expire the cookie.

### S8. Logout is a protected action
Make logout a POST (or otherwise token-protected) request, not a plain link that any page can trigger.
Why: SameSite=Lax does not stop state changes made by top-level GET navigation.
Sources: [OWASP CSRF Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html).
How the stack holds it: `Not yet`. `sesame_logout()` accepts any method (`src/functions.php:114-119`), and no guidance says to protect it.

### S9. Write down the session lifetimes
Record the idle timeout, the maximum lifetime, and how app, SESAME and IdP sessions relate.
Why: with a federated login there are several sessions, and nobody can reason about logout or re-authentication if their lifetimes are not written down.
Sources: [ASVS 5.0 V7.1.1 and V7.1.3](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x16-V7-Session-Management.md), [ASVS 5.0 V7.6.1](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x16-V7-Session-Management.md).
How the stack holds it: `Not yet`. No template or section in the README or the CREED handbook asks an app to state its lifetimes.

## 2. The `cas` driver

### S10. Build the service URL from configuration only
The service URL comes from config. It never comes from `Host`, `X-Forwarded-*` or any other request header.
Why: a ticket is bound to one service URL, and a URL built from request headers lets a client choose it. Citadel's HA-Proxy appends forwarded headers and strips none.
Sources: [CAS Protocol 3.0 (service identifier, ticket binding)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html), [oauth2-proxy configuration (forwarded-header trust)](https://oauth2-proxy.github.io/oauth2-proxy/configuration/overview/), [Debian tracker for phpCAS CVE-2022-39369](https://security-tracker.debian.org/tracker/CVE-2022-39369) (a real failure: phpCAS before 1.6.0 took the service URL from request headers, and 1.6.0 made apps pass a service base URL).
How the stack holds it: `Enforced`. `CasDriver` takes `serviceUrl` in its constructor and reads nothing from the request except the `ticket` query value (`src/Drivers/CasDriver.php:67-95`). `CasDriverTest::testServiceUrlComesFromConfigOnly` covers it.

### S11. Talk to the CAS server over HTTPS only
Both the CAS base URL and the service URL must be absolute `https://` URLs.
Why: the validation response is the whole proof of identity, so a plain-HTTP call can be forged on the path. The CAS spec does not itself require TLS for validation, so the deployer must.
Sources: [CAS Protocol 3.0 (security considerations)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html), [OWASP Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).
How the stack holds it: `Enforced`. The constructor throws `InvalidConfig` for a non-`https://` URL (`src/Drivers/CasDriver.php:46-52`, tests `testRejectsAnHttpBaseUrl`, `testRejectsAnHttpServiceUrl`). The check is a prefix test only (see the gap list).

### S12. Check and encode the ticket before it leaves the app
Reject a ticket that is not a string, is empty, is too long or has odd characters. URL-encode both `ticket` and `service` in the validation request.
Why: an unencoded ticket allows parameter injection into the validation URL.
Sources: [CAS Protocol 3.0 (ticket format and length)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html) (checked second pass: service tickets start with `ST-`, a service must accept 32 characters and should accept 256), [Apereo, Java CAS Client JWT disclosure, 2026-05-27](https://www.apereo.org/news/2026/java-cas-client-jwt-vulnerability-disclosure) (a ticket-validation flaw in one client that accepts JWT tickets; the page names no CVE and does not mention PHP).
How the stack holds it: `Enforced`. `assertValidTicket()` allows letters, digits, `.`, `_` and `-` up to 256 characters, and rejects arrays. The query is built with `PHP_QUERY_RFC3986` (`src/Drivers/CasDriver.php:78-81, 105-114`). SESAME only reads the XML validation response and never parses a ticket itself, so the JWT ticket class of bug does not apply while that stays true. (The 256-character limit would also stop most JWTs, but that is a side effect, not a defence.) Tests: `testRejectsABadTicketBeforeAnyHttpCall`, `testValidateUrlEncodesServiceAndTicket`.

### S13. A ticket works once
A service ticket may be validated one time. A second use must fail.
Why: a ticket copied from a log, history entry or Referer header must be useless.
Sources: [CAS Protocol 3.0 (tickets are single-use)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html), [ASVS 5.0 V6.8.3](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x15-V6-Authentication.md) (once-only for SAML assertions).
How the stack holds it: `Documented`. The CAS server enforces single use, and SESAME turns `INVALID_TICKET` into `AuthenticationFailed` (`src/Drivers/CasDriver.php:27-29`). SESAME keeps no record of its own, so a misconfigured CAS server would not be caught. `testAReplayedTicketFailsOnTheSecondUse` uses a fake server that already refuses the second call.

### S14. Validate with the 3.0 endpoint when you need attributes
Use `/p3/serviceValidate` for CAS 3.0 attribute release. Fall back to `/serviceValidate` only when the server cannot.
Why: only the 3.0 endpoint returns attributes, and an app that needs them must not guess.
Sources: [CAS Protocol 3.0 (serviceValidate and p3/serviceValidate)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html).
How the stack holds it: `Enforced` for the path choice (`cas.protocol` is `3.0` or `2.0`, and anything else throws). The README adds that attribute release on `/p3/serviceValidate` is not yet verified against Academica.

### S15. Parse the validation response as hostile XML
Reject DOCTYPE declarations, never enable entity substitution or DTD loading, and block network access during parsing.
Why: XML parsers can load files or URLs, or expand entities, from content the sender controls.
Sources: [OWASP XXE Prevention](https://cheatsheetseries.owasp.org/cheatsheets/XML_External_Entity_Prevention_Cheat_Sheet.html), [ASVS 5.0 V1.5.1](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x10-V1-Encoding-and-Sanitization.md).
How the stack holds it: `Enforced`. A DOCTYPE is rejected before parsing (`src/Drivers/CasDriver.php:178-180`), the document is parsed with `LIBXML_NONET` so no external entities are loaded (`src/Drivers/CasDriver.php:185`), and the libxml error flag is restored in a `finally` block (`src/Drivers/CasDriver.php:182-189`). Test: `testXxeWithDoctypeThrowsAuthenticationFailed`.

### S16. Fail closed on anything unexpected
A non-200 answer, malformed XML, a failure element, a missing user or an empty user all end the login with `AuthenticationFailed`. Nothing falls back to a default user.
Why: a login that guesses on bad input is a login bypass.
Sources: [OWASP Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), [CAS Protocol 3.0 (response format)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html).
How the stack holds it: `Enforced`. See `src/Drivers/CasDriver.php:87-92, 130-160`. Tests: `testMalformedXmlThrowsAuthenticationFailed`, `testA500ResponseThrowsAuthenticationFailed`. `Identity` also refuses an empty username (`src/Identity.php:27-29`).

### S17. Remove the ticket from the address bar after login
After a successful login, redirect to the same page without `?ticket=`.
Why: the ticket would otherwise stay in logs, history and Referer headers. It is single-use, so the risk is small, but it is free to remove.
Sources: [CAS Protocol 3.0 (security considerations)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html).
How the stack holds it: `Not yet`. `Sesame::login()` returns the user and the app must redirect. Nothing says so.

### S18. Bind the login to the browser that started it
Set a short-lived value in the browser before the redirect to CAS, and check it when the ticket returns.
Why: without it, an attacker can obtain a ticket for their own account and make a victim's browser deliver it, which signs the victim in as the attacker. Keep the value in a `Lax` cookie, not `Strict` (S41).
Sources: [OWASP CSRF Prevention (login CSRF and pre-sessions)](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html).
How the stack holds it: `Not yet`. CAS carries no state parameter, and `CasDriver::login()` accepts any valid ticket that arrives at the service URL (`src/Drivers/CasDriver.php:69-75`).

### S19. Force fresh credentials for sensitive actions
Support `renew` and read the authentication details the server returns, so a sensitive action can require a recent, direct login.
Why: SSO may have been satisfied hours ago, or by a remembered login.
Sources: [CAS Protocol 3.0 (renew, authentication attributes)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html), [ASVS 5.0 V6.8.4](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x15-V6-Authentication.md) (an app that needs a given strength or recency must check what the IdP returned, and keep a documented fallback if the IdP returns nothing), [ASVS 5.0 V7.5.1](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x16-V7-Session-Management.md) (full re-authentication before changing sensitive account attributes; narrower than "any sensitive action").
How the stack holds it: `Partly enforced` (opt-in). The `cas.renew` option sends `renew` on the login redirect and the validation request, so an app can require a fresh, direct login; the CAS server refuses a ticket that came from an SSO session with `INVALID_TICKET`. `authenticationDate` is read for the login age (S46). Still open: `isFromNewLogin` and `longTermAuthenticationRequestTokenUsed` are not read, so an app cannot yet tell a fresh login from a remembered one. The spec says to ignore `gateway` when `renew` is set.

### S20. Accept single logout from the CAS server
When the CAS server ends a user's SSO session, it can POST a logout request to each app. The app should find the session by ticket and end it.
Why: otherwise a user who logs out of one app stays signed in to the others.
Sources: [CAS Protocol 3.0 (single logout, back-channel)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html) (checked second pass: SLO is optional, back-channel POST only with no front-channel in the 3.0 spec, the server ignores errors from the POST, and clients that do not support it ignore it), [phpCAS clustering](https://apereo.atlassian.net/wiki/spaces/CASC/pages/103252534/phpCAS+clustering) (several app nodes need shared session storage so a logout that reaches one node can find sessions made on another), [phpCAS advanced SAML example](https://git.mdrn.pl/piwik-CASLogin.git/blob/HEAD:/CAS/docs/examples/example_advanced_saml11.php) (a mirror; restricts which hosts may send logout requests, because bogus logout XML can tie up the server), [Koha CAS code](https://git.koha-community.org/Koha-community/Koha/src/commit/39e88fc1f77d3926e4beae7353c920098093d5b7/C4/Auth_with_cas.pm) (prior art: stores the ticket with the local session).
How the stack holds it: `Not yet`, and moot against Academica. Academica never sends single logout, back-channel or front-channel, so there is no logout request for SESAME to accept and no ticket to store with the session. A user who logs out of one app stays signed in to the others until their own session ends, so apps rely on their own session lifetime: SESAME's idle and absolute timeouts (S5) end the session on the app's side. If a future CAS server does send SLO, accept the POST only from the configured CAS host, and never let it read a session the request names directly. SLO needs a server-side session store, so refuse to enable it with a client-side store such as the Laravel `cookie` driver at config time. The current logout only reaches the IdP from the app that started it.

### S21. Never redirect to an address the user supplied, unchecked
Allow only local paths, or hosts on an allowlist, for the post-login return and the post-logout `service` value.
Why: an open redirect turns a trusted login page into a phishing link.
Sources: [OWASP Unvalidated Redirects and Forwards](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html), [CAS Protocol 3.0 (/logout service parameter)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html).
How the stack holds it: `Not yet`. `logoutUrl()` and `sesame_logout()` pass `$returnTo` through as given (`src/Drivers/CasDriver.php:97-100`, `src/functions.php:114-119`). The CAS server checks its own registered services, which is a second line, not a first.

### S22. Stop the script after a redirect
After sending a `Location` header, end the request so no protected code runs.
Why: a redirect does not stop PHP. The rest of the page runs, and its output can reach the client.
Sources: none of the sources above make this point directly. It follows from how PHP's `header()` works: it queues a header and the script keeps running.
How the stack holds it: `Not yet`. `sesame_login()` and `sesame_logout()` send the header and return (`src/functions.php:84-86, 118`). The README example keeps running after `sesame_login()` (`README.md:45-47`).

### S23. Give the HTTP client a timeout and keep TLS verification on
Set connect and total timeouts on the PSR-18 client that calls CAS. Never disable certificate checks.
Why: a hung CAS server should fail one login, not hold every PHP worker.
Sources: [OWASP Authentication (TLS)](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), [Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html) (checked second pass: `timeout` and `connect_timeout` both default to `0`, which waits forever; `verify` defaults to `true`).
How the stack holds it: `Not yet`. SESAME takes any client and sets nothing. The README example is `new \GuzzleHttp\Client()` with defaults, and Guzzle's default has no total timeout (`README.md:89-90`). The first pass claim holds.

## 3. Identity and the app's user

### S24. Key users by IdP plus the IdP's own stable ID
Look users up by the driver and the IdP's identifier, not by email or a display attribute.
Why: two IdPs may issue the same name, and email can change or be reused.
Sources: [ASVS 5.0 V6.8.1](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x15-V6-Authentication.md), [OWASP Authentication (federation)](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).
How the stack holds it: `Not yet`. `Identity` carries `driver` and `username`, but the README and the resolver docblock do not tell apps to use both as the key.

### S25. Authentication is not authorization
`findOrImportUser` decides whether this person may use this app. When it says no, the user must not end up signed in.
Why: a valid CAS login means "a WSU person", not "a person with access to this app".
Sources: [NIST SP 800-63C-4 (RP maps the assertion to a provisioned account)](https://pages.nist.gov/800-63-4/sp800-63c.html).
How the stack holds it: `Not yet`. If the resolver returns `null`, `Sesame::login()` still regenerates the session and stores `sesame.identity` (`src/Sesame.php:42-46`). `user()` then returns null, but an app that checks the identity key is signed in. No test covers a null result.

### S26. Show users a generic failure, keep the detail in logs
The login error page must not say why a ticket failed. Record the reason for operators.
Why: detail helps an attacker probe, and it is no use to the user.
Sources: [OWASP Authentication (generic messages)](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), [ASVS 5.0 V16.5.1](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x25-V16-Security-Logging-and-Error-Handling.md) (generic message for security-sensitive errors; no stack traces, queries, keys or tokens). Corrected in the second pass: the first pass cited V6.3.8, but that requirement is about not revealing whether an account exists, not about error detail.
How the stack holds it: `Not yet`. `AuthenticationFailed` messages carry the CAS failure code (`src/Drivers/CasDriver.php:144-146`). Nothing tells apps not to print them.

### S27. Log authentication events, never secrets
Log logins, failures, logouts and session anomalies with user, driver and time. Never log tickets, session IDs, assertions or tokens.
Why: you cannot investigate or detect what you did not record, and a logged secret is a leaked secret.
Sources: [OWASP Logging](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html), [OWASP Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), [ASVS 5.0 V16](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x25-V16-Security-Logging-and-Error-Handling.md) (V16.3.1 log all authentication successes and failures with the authentication type; V16.2.5 never log credentials and mask or hash session tokens; V16.4.1 encode logged data against log injection; V16.2.2 record security event times in UTC or with an offset).
How the stack holds it: `Not yet`. SESAME has no logger or event hook. The CAS failure code and any user-supplied value (the username, the ticket text) are log-injection input, so a logging hook must encode them.

## 4. The `fake` driver and configuration

### S28. The fake driver cannot run in production
It throws when the environment is production. It never falls back silently.
Why: a fake login in production is an open door.
Sources: none specific. This is a project rule from ADR-0004.
How the stack holds it: `Enforced`. `FakeDriver` throws `FakeDriverInProduction` (`src/Drivers/FakeDriver.php:32-34`), tested by `FakeDriverTest::testRefusesProduction`. The test is an exact match on the word `production`, so an environment named `prod` or `live` boots it (see the gap list).

### S29. Required settings have no defaults
`driver`, `environment`, `cas.base_url` and `cas.service_url` must be set. There is no built-in default for the IdP address.
Why: a silent default points real users at a server the app owner never chose.
Sources: none specific. This is a project rule from ADR-0004 and the scaffold design.
How the stack holds it: `Enforced`. `Config::fromArray()` throws `InvalidConfig` on a missing or empty value (`src/Config.php:30-92`). Tests in `ConfigTest`.

## 5. Drivers still to build

### S30. The `gateway` driver accepts only a signed assertion
Never read a bare identity header. Verify a signature from the gateway, and do not use the sender's IP as proof. Read one configured header that holds a signed token. Never read `Remote-User`, `X-Remote-User`, `HTTP_REMOTE_USER` or any underscore variant.
Why: Citadel's proxy strips no client headers, and app containers likely share one network, so a plain header can come from anywhere. A proxy that strips `Remote-User` does not stop a client from sending `Remote_User`, and PHP maps both to the same variable.
Sources: [Google IAP signed headers](https://docs.cloud.google.com/iap/docs/signed-headers-howto), [Cloudflare Access JWT validation](https://developers.cloudflare.com/cloudflare-one/identity/authorization-cookie/validating-json/), [Pomerium identity verification](https://www.pomerium.com/docs/capabilities/getting-users-identity), [oauth2-proxy configuration](https://oauth2-proxy.github.io/oauth2-proxy/configuration/overview/), [oauth2-proxy CVE-2025-64484](https://www.tenable.com/cve/CVE-2025-64484) (fixed in 7.13.0 by ignoring case and `_`/`-` when stripping), [Caddy CVE-2026-52845](https://corgea.com/advisories/vulnerabilities/CVE-2026-52845) (`php_fastcgi` turns `-` to `_`, so `Remote_Groups` survives the strip; fixed in Caddy 2.11.4).
How the stack holds it: `Enforced`. `GatewayDriver` reads only the one configured header (`src/Drivers/GatewayDriver.php:155-166`) and never a plain identity header, and it requires the direct peer (`REMOTE_ADDR`) to match a configured CIDR range before it looks at the assertion (`src/Drivers/GatewayDriver.php:130-145`). Tested by `GatewayDriverTest::testIgnoresPlainIdentityHeadersWhenThereIsNoSignedAssertion`, `testPlainIdentityHeadersAreIgnoredInFavorOfTheSignedAssertion`, `testRejectsAnUntrustedPeer` and `testRejectsARequestWithNoSourceAddress`.

### S31. Check audience, expiry and replay on every assertion
Require the app's host as audience, an expiry of 60 seconds or less, and an issued-at time. Reject expired, wrong-audience, unsigned and replayed assertions.
Why: a captured assertion must not work against another app or later.
Sources: [RFC 8725 (JWT best practices)](https://www.rfc-editor.org/rfc/rfc8725.html), [ASVS 5.0 V9.2.1 and V9.2.3](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x18-V9-Self-contained-Tokens.md), [NIST SP 800-63C-4 (audience and replay)](https://pages.nist.gov/800-63-4/sp800-63c.html).
How the stack holds it: `Partly enforced`. `GatewayDriver` checks the audience (`PermittedFor`), the issuer (`IssuedBy`) and the time claims `iat`, `nbf` and `exp` with a configured leeway (`LooseValidAt`) (`src/Drivers/GatewayDriver.php:205-221`), and it requires `sub`, `iat` and `exp` to be present (`src/Drivers/GatewayDriver.php:231-258`). Tested by `GatewayDriverTest::testRejectsAWrongAudience`, `testRejectsAWrongIssuer`, `testRejectsAnExpiredToken`, `testRejectsATokenNotYetValid`, `testRejectsATokenIssuedInTheFuture` and the missing-claim tests. Not yet: replay. The driver has no `jti` or nonce check, so a captured assertion still works within its validity window, and the expiry is whatever the gateway sets — the driver does not cap it at 60 seconds.

### S32. Pin the algorithm and the key source
Accept one named algorithm per key. Never accept `none`. Take keys only from configuration, never from a URL named inside the token.
Why: attackers pick weak algorithms, or point the verifier at their own key.
Sources: [RFC 8725 sections 3.1 and 3.2](https://www.rfc-editor.org/rfc/rfc8725.html), [ASVS 5.0 V9.1.2 and V9.1.3](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x18-V9-Self-contained-Tokens.md).
How the stack holds it: `Enforced`. The library is `lcobucci/jwt` 5.6.1, and the driver uses the gateway's public key (asymmetric), not a shared HMAC key, so the D-002 choice is resolved. `GatewayDriver` pins the algorithm from config (`src/Drivers/GatewayDriver.php:340-352`) and verifies with `SignedWith`, which checks the token's `alg` header against the pinned signer before it looks at the signature (`src/Drivers/GatewayDriver.php:205-221`), so a token that claims another algorithm or `none` is refused — the same failure mode the `web-token/jwt-framework` [June 2026 advisory](https://github.com/web-token/jwt-framework/security/advisories) describes. The key comes only from config (`src/Drivers/GatewayDriver.php:358-369`), never from a URL named inside the token. Tested by `GatewayDriverTest::testRejectsAnAlgNoneToken`, `testRejectsAWrongAlgorithm` and `testRejectsABadSignature`.

### S33. Keep token types apart
Mark each token kind with its own `typ`, audience or key, so a gateway assertion cannot be accepted as an OAuth2 bearer token, or the reverse.
Why: two drivers that validate JWTs from the same issuer can be tricked into accepting each other's tokens.
Sources: [RFC 8725 sections 3.11 and 3.12](https://www.rfc-editor.org/rfc/rfc8725.html), [ASVS 5.0 V9.2.2](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x18-V9-Self-contained-Tokens.md), [RFC 9068 (JWT profile for access tokens)](https://www.rfc-editor.org/rfc/rfc9068.html) (a resource server must check that `typ` is `at+jwt` or `application/at+jwt` and reject anything else; the required claims are `iss`, `exp`, `aud`, `sub`, `client_id`, `iat` and `jti`).
How the stack holds it: `Not yet`. For the `oauth2` driver, the gateway assertion should use a different `typ` from `at+jwt`, so the two cannot be swapped.

### S34. The `saml` driver: use a maintained library and require signatures
Do not write XML signature code. Use a maintained SAML library and require signed responses or assertions. Check Destination, Audience, Recipient, InResponseTo and the validity window, and accept each assertion once.
Why: SAML parsing and signature checks have a long history of bypasses, such as signature wrapping.
Sources: [OWASP SAML Security](https://cheatsheetseries.owasp.org/cheatsheets/SAML_Security_Cheat_Sheet.html), [ASVS 5.0 V6.8.2 and V6.8.3](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x15-V6-Authentication.md), [NIST SP 800-63C-4](https://pages.nist.gov/800-63-4/sp800-63c.html), [GitHub Security Lab on ruby-saml, 2025-03-12](https://github.blog/security/sign-in-as-anyone-bypassing-saml-sso-authentication-with-parser-differentials/), [Debian tracker CVE-2026-49283](https://security-tracker.debian.org/tracker/CVE-2026-49283), [Debian tracker CVE-2026-49289](https://security-tracker.debian.org/tracker/CVE-2026-49289), [Packagist onelogin/php-saml](https://repo.packagist.org/p2/onelogin/php-saml.json), [Packagist litesaml/lightsaml](https://repo.packagist.org/p2/litesaml/lightsaml.json), [Packagist simplesamlphp/saml2](https://repo.packagist.org/p2/simplesamlphp/saml2.json), [lightsaml CVE-2026-63182](https://github.com/litesaml/lightsaml/security/advisories/GHSA-w553-pwx6-3mg9), [lightsaml GHSA-rm7m-7jq4-gw46](https://github.com/litesaml/lightsaml/security/advisories/GHSA-rm7m-7jq4-gw46), [simplesamlphp/saml2 advisories](https://github.com/simplesamlphp/saml2/security/advisories), [SAML-Toolkits/php-saml advisories](https://github.com/SAML-Toolkits/php-saml/security/advisories).
How the stack holds it: `Not yet`. The library is not chosen. The second pass weighs the PHP candidates. `onelogin/php-saml` 4.3.2 is the first choice: it is MIT, requires PHP `>=7.3` (so it fits the 8.3 floor), and is a full service-provider toolkit (SP-initiated login, ACS, SLO, metadata, checks of Destination, Audience and InResponseTo). It uses xmlseclibs, and it has one advisory, CVE-2025-66475 (xmlseclibs signature wrapping, fixed in 4.3.1). `litesaml/lightsaml` is out: it is a low-level model library, not a toolkit, and its 5.x and 6.x lines need PHP 8.4, which breaks the 8.3 floor. It has two 2026 advisories: CVE-2026-63182 (signature wrapping, fixed in 5.0.1 and 4.7.1) and GHSA-rm7m-7jq4-gw46 (6.0.0 reads a `-1` from xmlseclibs as a valid signature, fixed in 6.0.1). `simplesamlphp/saml2` is an LGPL alternative; its 6.x line fits the 8.3 floor, and it has three 2026 advisories, one titled "Incomplete fix of CVE-2026-49283". Choose a library with a recent release, subscribe to its advisories, and run `composer audit` against it.

### S47. The `saml` driver: one XML parser for the signature and the data
Parse the SAML message once, and take every value you trust (digest, signed info, subject, conditions) from the exact node the signature covers. Do not parse the same document with two XML libraries. Validate against a local schema before any security decision, with no automatic schema download.
Why: the ruby-saml bypass was a parser difference. The digest was checked on one reading of the document and the signature on another, so each check passed alone. The OWASP SAML sheet also says to confirm the signature's `Reference URI` covers the assertion actually used, and not to pick security elements with `getElementsByTagName`.
Sources: [GitHub Security Lab on ruby-saml](https://github.blog/security/sign-in-as-anyone-bypassing-saml-sso-authentication-with-parser-differentials/), [OWASP SAML Security](https://cheatsheetseries.owasp.org/cheatsheets/SAML_Security_Cheat_Sheet.html), [ASVS 5.0 V1.5.3](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x10-V1-Encoding-and-Sanitization.md) (parsers for one data type should parse consistently).
How the stack holds it: `Not yet`. No SAML code exists. The `cas` driver uses one parser (`simplexml_load_string`), so it is not affected today.

### S35. The `saml` driver: start logins at the app, and allowlist the return address
Prefer service-provider-started logins, and treat an IdP-started login as an injection risk. Validate `RelayState` against an allowlist.
Why: a login the app never asked for can be forced onto a victim, and `RelayState` is an open redirect if used raw.
Sources: [OWASP SAML Security](https://cheatsheetseries.owasp.org/cheatsheets/SAML_Security_Cheat_Sheet.html), [NIST SP 800-63C-4 (injection protection)](https://pages.nist.gov/800-63-4/sp800-63c.html).
How the stack holds it: `Not yet`. Add: a SAML POST response is a cross-site POST, and browsers do not send `Lax` or `Strict` cookies on it, so the session cookie will be missing when the response arrives. Vendor docs for several IdP products and a SimpleSAMLphp report describe this. Without the cookie the app cannot check `InResponseTo`, so the login is only as strong as an IdP-initiated one. The usual fix is to keep the request ID in a short-lived `SameSite=None; Secure` cookie. I did not verify other fixes. See S41.

### S36. The `oauth2` driver: verify the whole token
Check signature, issuer, audience, expiry and scopes. That list is for JWT access tokens (RFC 9068). Read the token from the `Authorization` header, never from a query string. Reject tokens meant for another service. If the server issues opaque tokens, verify them by introspection (RFC 7662): authenticate to the endpoint over TLS, and never cache a result past its `exp`. Make the cache lifetime a config value, defaulting to 60 seconds or less.
Why: a token valid somewhere else is not valid here.
Sources: [RFC 9700 (OAuth 2.0 Security BCP)](https://www.rfc-editor.org/rfc/rfc9700.html) (published January 2025 as BCP 240; checked second pass: exact redirect URI matching; PKCE with S256; access tokens restricted to one resource server; sender-constrained tokens, for example DPoP or mTLS, as a SHOULD; clients that use several authorization servers must defend against mix-up attacks, with the RFC 9207 `iss` response parameter as one defence), [RFC 9068](https://www.rfc-editor.org/rfc/rfc9068.html), [RFC 8725](https://www.rfc-editor.org/rfc/rfc8725.html), [RFC 7662 (OAuth 2.0 Token Introspection)](https://www.rfc-editor.org/rfc/rfc7662.html), [ASVS 5.0 V9](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x18-V9-Self-contained-Tokens.md).
How the stack holds it: `Documented`. ADR-0004 decision 3 lists the checks. The header-only rule is not written down, and the scopes and flows that Academica offers are open (D-004). The `oauth2` driver is a resource-server check (bearer tokens in, identity out), so the client-side items (PKCE, redirect URIs, mix-up) apply only if SESAME ever starts a login itself. Decide that scope in the ADR.

## 6. Build and supply chain

### S37. Support only PHP versions that are not end of life
The library requires PHP 8.3 or later.
Why: EOL PHP gets no security fixes, and session and crypto fixes are part of that.
Sources: [PHP supported versions](https://www.php.net/supported-versions.php) (checked second pass: 8.2 security support ends 2026-12-31; 8.3 on 2027-12-31; 8.4 on 2028-12-31; 8.5, released 2025-11-20, on 2029-12-31). The version rule is ADR-0003 and ADR-0004.
How the stack holds it: `Checked`. `composer.json` requires `^8.3`, and PROCTOR's PHP floor check compares an app's floor with `policy.json`. The first pass dates are right: 8.2 loses security support two months from this check, so the 2027-01-01 floor of 8.3 matches php.net.

### S38. Audit dependencies for known vulnerabilities
Run a Composer audit in CI and before release.
Why: auth code pulls in HTTP and XML packages whose bugs are your bugs.
Sources: [Packagist, Composer 2.9](https://blog.packagist.com/composer-2-9/) (released 2025-11-13: updates that would install a package version with a known advisory are blocked by default, setting `audit.block-insecure`; `audit.block-abandoned` is off by default), [GitLab dependency scanning](https://docs.gitlab.com/user/application_security/dependency_scanning/) (an Ultimate-tier feature, per its page).
How the stack holds it: `Checked`. Second pass correction: the first pass said the SESAME repo has no CI file. `.gitlab-ci.yml` now exists and includes the PREFLIGHT `php-test` and `composer-audit` components. In apps, PROCTOR's `ComposerAuditCheck` also checks. Because Composer 2.9 blocks advisory-hit versions by default, an advisory published later can fail an install or update, so write down who may add an audit ignore and for how long. A disputed advisory (the `firebase/php-jwt` case above) is the test of that rule. Nothing in the repo says what the components check, so read PREFLIGHT before relying on it. GitLab's dependency scanning needs Ultimate and I do not know the tier of the WSU instance, so `composer audit` stays the base check.

### S39. Run the tests on every supported PHP
Run the suite on the lowest and the highest supported PHP.
Why: session and XML behaviour differ between PHP versions.
Sources: none specific. The PHP manual notes that session settings change between versions, for example in 8.4.
How the stack holds it: `Checked`. `.gitlab-ci.yml` passes `php_versions: ["8.3", "8.4", "8.5"]` to the PREFLIGHT `php-test` component, so every supported PHP from the floor up runs the tests on each push. Earlier passes said the versions were not visible in this repo; they are now.

### S40. Compatibility shims must not bring back old weaknesses
The planned wayne-cas and simplecas shims map old method names onto the core. They must not restore unencoded tickets, forwarded-header trust or sessions with no logout.
Why: the old clients have these faults, and a shim that copies behaviour copies the faults.
Sources: [ASVS 5.0 V6.8](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x15-V6-Authentication.md).
How the stack holds it: `Documented`. ADR-0004 says the shims are thin adapters with no rules. They are not built.

## 7. Added in the second pass (2026-10-27)

S47 sits in section 5 beside the other `saml` items. The rest are here.

### S41. Choose the SameSite value for the login flow on purpose
Use `SameSite=Lax` for the session cookie, so the browser sends it when the CAS server redirects back with a top-level GET. Do not use `Strict` for any cookie that must be present on that return. For a SAML POST response, no `Lax` or `Strict` cookie is sent, so the login-binding state (S18, S35) needs its own short-lived `SameSite=None; Secure` cookie.
Why: with `Strict`, the cookie is missing on the very request that finishes the login, so a login binding check (S18) fails for every user. With `Lax`, a cross-site POST carries no cookie at all.
Sources: [rfc6265bis draft, section 5.6.7.2](https://datatracker.ietf.org/doc/draft-ietf-httpbis-rfc6265bis/) (the draft lets browsers send a defaulted-`Lax` cookie on a POST only if it is under about 2 minutes old, and says that is a SHOULD, so do not rely on it), [Resolution SAML SSO, SameSite note](https://wiki.resolution.de/doc/saml-sso/latest/all/knowledgebase-articles/technical/impact-of-the-changes-to-the-samesite-cookie-flag-default-behavior-in-chrome), [ComponentSpace FAQ](https://docs.componentspace.com/saml-for-asp-net-core/faq/). The vendor pages describe their own products. The CAS `Strict` consequence is my reading of the cookie rules, not a quote from a source.
How the stack holds it: `Not yet`. S3 now says `Lax`, but nothing checks the value.

### S42. Do not follow redirects on the validation call, and keep host name checks on
The call to `/p3/serviceValidate` must go to the configured CAS host and nowhere else. Turn redirects off for it. Never set TLS verification to `false`, and never replace the client's host name check.
Why: a validation call that follows a redirect can be sent to another host or to plain HTTP. A real failure on this theme: in July 2026 CERT Polska published CVE-2026-15243, where the Java and Jasig CAS clients accepted any CA-trusted certificate for any host name when the URL matched the allowlist, so a network attacker could capture a ticket-granting ticket.
Sources: [CERT Polska, CVE-2026-15243](https://cert.pl/en/posts/2026/07/CVE-2026-15243) (confirmed in Java Apereo CAS Client 4.1.0 and Jasig CAS Client 3.6.4; the page names no fixed version and no workaround; no PHP client is named), [Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html) (default `allow_redirects` follows up to 5 redirects and allows `http` and `https`).
How the stack holds it: `Not yet`. SESAME reads only the response status and body. PSR-18 has no standard redirect or TLS option, so the app's client configures them. The README does not show it. Add the redirect and `verify` settings to the README client example, and add an integration test against a server with a wrong-host certificate.

### S43. Regenerate the session without losing it on a bad network
Prefer to keep the old session for a short grace period, marked with a "destroyed at" time and the new ID, over deleting it at once. Treat use of the old ID after the grace period as a possible hijack and force a new login.
Why: the PHP manual says `session_regenerate_id()` does not handle an unstable network well and a user can lose the session. Immediate deletion also removes the chance to detect a stolen ID being replayed.
Sources: [PHP manual, session_regenerate_id](https://www.php.net/manual/en/function.session-regenerate-id.php) (its example uses 300 seconds, which is the example's choice and not a rule).
How the stack holds it: `Not yet`. `NativeSession::regenerate()` calls `session_regenerate_id(true)`. The first pass listed this as a trade-off to decide; the manual's own advice is the grace period. For logout the immediate delete is right (S7). For login it is the open question.

### S44. Mark logout and login responses as not cacheable, and clear browser data
Send `Cache-Control: no-store` on responses that carry session data. On logout also send `Clear-Site-Data: "cache", "cookies", "storage"`, and still expire the session cookie.
Why: a back button or a shared browser should not show a signed-in page after logout.
Sources: [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html) (recommends `no-store` on responses with session data, and `Clear-Site-Data` on logout). One search result says Safari does not support the header. I did not confirm that on a primary page, so expire the cookie too.
How the stack holds it: `Not yet`. `sesame_logout()` sends only `Location`.

### S45. Leave the session ID length at PHP's defaults
Do not set `session.sid_length` or `session.sid_bits_per_character`. On PHP 8.4 and later they are deprecated, and the default ID is 32 hexadecimal characters (128 bits).
Why: a low setting weakens the ID, and the settings now raise deprecation notices. OWASP asks for at least 64 bits of entropy in an ID.
Sources: [PHP RFC, Deprecations for PHP 8.4](https://wiki.php.net/rfc/deprecations_php_8_4) (confirmed through search results, not read in full), [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html).
How the stack holds it: `Not yet`. SESAME does not touch the setting. HELMET's hardening step could check it.

### S46. Take login time from the IdP, and read time through a clock
Prefer the IdP's `authenticationDate` over the time SESAME received the response, and give the library a PSR-20 clock instead of calling `new DateTimeImmutable()`.
Why: `authenticatedAt` is when SESAME saw the response, not when the person signed in. Timeout and re-authentication rules (S5, S19) need the real login age, and a clock you can set makes those rules testable.
Sources: [CAS Protocol 3.0 (`authenticationDate`)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html), [PSR-20 Clock](https://www.php-fig.org/psr/psr-20/) (defines `ClockInterface::now()` returning a `DateTimeImmutable`; I could not read its status on that page).
How the stack holds it: `Enforced`. `CasDriver` reads `authenticationDate` from the attributes and uses it as the `authenticatedAt` when it is present and parseable; otherwise it falls back to the driver's clock. Time is read through a PSR-20 clock (injected, defaulting to the system clock), and a value more than five minutes ahead of the clock is treated as bad and ignored.

### S48. Pin shared CI components to a commit
Include pipeline components by commit SHA, never `~latest`.
Why: a moving component can change what your pipeline runs, or what it skips, with no change in your repo. GitLab says components are used at your own risk.
Sources: [GitLab CI/CD components](https://docs.gitlab.com/ci/components/) (commit SHA is the preferred pin; "Avoid using `latest`").
How the stack holds it: `Documented`. `.gitlab-ci.yml` pins both PREFLIGHT components to one commit SHA and says never to use `~latest`. Nothing checks it, so PROCTOR could flag an unpinned `include:` in any repo.

## 8. MFA

### S49. Require MFA on protected pages, and fail closed
A protected page can require that the user's MFA is still good, not just that they are signed in. The check reads the identity's `authTime` (when the user last completed MFA) and `amr` (the methods they used), and is satisfied only when the `authTime` is not in the future, is not older than the allowed age, and the `amr` includes an MFA method. When any of those is missing, the check denies the request.
Why: a signed-in session is not the same as a recently-verified one, and a page that holds sensitive data should ask for proof the user's MFA is still good.
Sources: [NIST SP 800-63B-4 (step-up authentication)](https://pages.nist.gov/800-63-4/sp800-63b.html), [ASVS 5.0 V6.8.4](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x15-V6-Authentication.md) (an app that needs a given strength or recency must check what the IdP returned, and keep a documented fallback if the IdP returns nothing).
How the stack holds it: `Enforced`. `Mfa::satisfied()` fails closed on a missing `authTime`, a missing MFA method, an age that has passed, or a future `authTime` (`src/Mfa.php`). `Sesame::requireMfa()` answers `NO_AUTH_TIME` when there is no user or no identity in the session (`src/Sesame.php`), and `sesame_require_mfa()` throws `MfaNotSatisfied` on a deny (`src/functions.php`). The gateway driver reads the MFA facts only from the signed assertion's `auth_time` and `amr` claims, and a missing or malformed claim leaves the facts unset, so a claim the gateway did not verify can never satisfy the check (`src/Drivers/GatewayDriver.php`). The cas driver reports no MFA facts, so a cas identity always fails the check. The Laravel `sesame.mfa` middleware applies the same check to a route (`src/Laravel/RequireMfa.php`). Tests: `MfaTest`, `SesameTest::testRequireMfaIsSatisfiedWhenTheIdentityHasRecentMfa`, `GatewayDriverTest::testExtractsAuthTimeAndAmrFromTheAssertion`, `RequireMfaTest`.

## Gaps for the backlog

Risks in the current code:
- `src/functions.php:84-86, 118` and `README.md:45-47`: no exit after a redirect, and the README example keeps running.
- `src/Sesame.php:42-46`: a null user from the resolver still creates a session with `sesame.identity` set.
- `src/Sesame.php:45-46`: the whole resolver result and the full identity go into the session.
- `src/Drivers/FakeDriver.php:32`: only the exact word `production` is refused. Use an allowlist such as `local`, `development` and `testing`.
- `src/Session/NativeSession.php:54-60`: `invalidate()` leaves an empty session with a new ID and a live cookie. Call `session_destroy()` and expire the cookie.
- `src/Session/NativeSession.php:24, 59`: `session_regenerate_id(true)` deletes the old session at once. The PHP manual warns this can drop a session on an unstable network. Decide the trade-off and write it down.
- `src/Drivers/CasDriver.php:86-111`: no login binding to the browser.
- `README.md:120-121`: the example client has no timeout.
- Repo: `.gitlab-ci.yml` runs the tests on PHP 8.3, 8.4 and 8.5. Still open: read what the `composer-audit` component fails on.
- `README.md`: Docker commands cover 8.3 and newer.
- `src/Drivers/CasDriver.php`: nothing reads `isFromNewLogin` or `longTermAuthenticationRequestTokenUsed`, so an app cannot tell a fresh login from a remembered one (S19).
- `src/functions.php:118`: logout sends no `Cache-Control: no-store` or `Clear-Site-Data` (S44).
- `src/Session/NativeSession.php:24`: use a destroy time stamp and grace period at login instead of immediate delete (S43).
- README client example: add no-redirect and TLS-verify settings, and a timeout (S23, S42).

Missing practices:
- Idle and absolute session timeouts, with a configurable value.
- A `__Host-` cookie name, set by HELMET.
- Ticket removal from the URL after login.
- Single logout, which needs the ticket stored with the session. Moot against Academica, which never sends it; apps rely on their own session lifetime (S5).
- A logging hook (PSR-3) for authentication events.
- A written rule for the user key (driver plus ID) and for what the resolver must do on a deny.
- A documented lifetime table for each app, covering app, SESAME and IdP sessions.
- Tests: XXE payload, null resolver result, replayed gateway assertion once that driver exists.
- Choose the signing scheme for `gateway`, and the SAML library for `saml` (D-001, D-002).
- Questions for the IdP owner: SAML single logout, signed assertions, released attributes, and `/p3/serviceValidate` attribute release. Add: does Academica release `authenticationDate`, `isFromNewLogin` and `longTermAuthenticationRequestTokenUsed`, and does it support `renew` (S19). Answered: Academica does not send single logout POSTs (S20).
- Decide the SameSite value for every cookie in the login flow, and write it in the README and in HELMET (S41).
- A cache and `Clear-Site-Data` rule for logout (S44).
- A written rule for who may add a Composer audit ignore, since Composer 2.9 blocks advisory-hit versions by default (S38).
- Scope of the `oauth2` driver in ADR-0004: resource server only, or also a client that starts logins (S36).
- A test for the `saml` library choice: feed it a document with a second signature, a DOCTYPE and a namespace trick, and expect rejection (S34, S47).
- Check whether the CERT Polska CVE-2026-15243 advisory affects any PHP CAS client the old `wayne-cas` package or its shim wraps. The advisory names only the Java and Jasig clients.

## Sources

Checked on 2026-10-27.

- OWASP, [Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html)
- OWASP, [Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html)
- OWASP, [SAML Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/SAML_Security_Cheat_Sheet.html)
- OWASP, [Cross-Site Request Forgery Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html)
- OWASP, [Unvalidated Redirects and Forwards Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html)
- OWASP, [XML External Entity Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/XML_External_Entity_Prevention_Cheat_Sheet.html)
- OWASP, [Deserialization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Deserialization_Cheat_Sheet.html)
- OWASP, [Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html)
- OWASP ASVS 5.0.0, [V7 Session Management](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x16-V7-Session-Management.md)
- OWASP ASVS 5.0.0, [V6 Authentication](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x15-V6-Authentication.md)
- OWASP ASVS 5.0.0, [V9 Self-contained Tokens](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x18-V9-Self-contained-Tokens.md)
- OWASP ASVS 5.0.0, [V1 Encoding and Sanitization](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x10-V1-Encoding-and-Sanitization.md)
- NIST, [SP 800-63B-4, Authentication and Authenticator Management](https://pages.nist.gov/800-63-4/sp800-63b.html)
- NIST, [SP 800-63C-4, Federation and Assertions](https://pages.nist.gov/800-63-4/sp800-63c.html)
- Apereo, [CAS Protocol Specification (3.0)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html)
- PHP manual, [session_regenerate_id](https://www.php.net/manual/en/function.session-regenerate-id.php)
- PHP manual, [Session runtime configuration](https://www.php.net/manual/en/session.configuration.php)
- IETF, [RFC 8725, JSON Web Token Best Current Practices](https://www.rfc-editor.org/rfc/rfc8725.html)
- IETF, [RFC 9700, Best Current Practice for OAuth 2.0 Security](https://www.rfc-editor.org/rfc/rfc9700.html)
- IETF, [Cookies: HTTP State Management Mechanism (rfc6265bis draft)](https://datatracker.ietf.org/doc/draft-ietf-httpbis-rfc6265bis/)
- Google Cloud, [Verifying the IAP signed header](https://docs.cloud.google.com/iap/docs/signed-headers-howto)
- Cloudflare, [Validate the Access JWT](https://developers.cloudflare.com/cloudflare-one/identity/authorization-cookie/validating-json/)
- Pomerium, [Getting the user's identity](https://www.pomerium.com/docs/capabilities/getting-users-identity)
- oauth2-proxy, [Configuration overview](https://oauth2-proxy.github.io/oauth2-proxy/configuration/overview/)

Added in the second pass, all checked 2026-10-27:

- CERT Polska, [CVE-2026-15243, Apereo CAS Client certificate host name validation](https://cert.pl/en/posts/2026/07/CVE-2026-15243), checked 2026-10-27
- Apereo, [Java CAS Client JWT vulnerability disclosure](https://www.apereo.org/news/2026/java-cas-client-jwt-vulnerability-disclosure), checked 2026-10-27
- Debian security tracker, [CVE-2022-39369 (phpCAS)](https://security-tracker.debian.org/tracker/CVE-2022-39369), checked 2026-10-27
- Debian security tracker, [CVE-2026-49283](https://security-tracker.debian.org/tracker/CVE-2026-49283) and [CVE-2026-49289](https://security-tracker.debian.org/tracker/CVE-2026-49289) (SimpleSAMLphp SAML2 library), checked 2026-10-27
- GitHub Security Lab, [Sign in as anyone: bypassing SAML SSO with parser differentials](https://github.blog/security/sign-in-as-anyone-bypassing-saml-sso-authentication-with-parser-differentials/) (2025-03-12), checked 2026-10-27
- GitLab advisory database, [firebase/php-jwt CVE-2025-45769](https://advisories.gitlab.com/composer/firebase/php-jwt/CVE-2025-45769/), checked 2026-10-27
- Packagist, [Composer 2.9](https://blog.packagist.com/composer-2-9/), checked 2026-10-27
- GitLab, [CI/CD components](https://docs.gitlab.com/ci/components/) and [Dependency scanning](https://docs.gitlab.com/user/application_security/dependency_scanning/), checked 2026-10-27
- PHP, [Supported versions](https://www.php.net/supported-versions.php), checked 2026-10-27
- PHP RFC, [Deprecations for PHP 8.4](https://wiki.php.net/rfc/deprecations_php_8_4) (via search results only), checked 2026-10-27
- OWASP ASVS 5.0.0, [V16 Security Logging and Error Handling](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x25-V16-Security-Logging-and-Error-Handling.md), checked 2026-10-27
- IETF, [RFC 9068, JWT Profile for OAuth 2.0 Access Tokens](https://www.rfc-editor.org/rfc/rfc9068.html), checked 2026-10-27
- Guzzle, [Request options](https://docs.guzzlephp.org/en/stable/request-options.html), checked 2026-10-27
- PHP-FIG, [PSR-20 Clock](https://www.php-fig.org/psr/psr-20/), checked 2026-10-27
- Apereo, [phpCAS clustering](https://apereo.atlassian.net/wiki/spaces/CASC/pages/103252534/phpCAS+clustering), checked 2026-10-27
- Resolution, [SameSite and SAML SSO](https://wiki.resolution.de/doc/saml-sso/latest/all/knowledgebase-articles/technical/impact-of-the-changes-to-the-samesite-cookie-flag-default-behavior-in-chrome) and ComponentSpace, [SAML FAQ](https://docs.componentspace.com/saml-for-asp-net-core/faq/), checked 2026-10-27 (vendor pages; search results only)
- Koha, [CAS authentication code](https://git.koha-community.org/Koha-community/Koha/src/commit/39e88fc1f77d3926e4beae7353c920098093d5b7/C4/Auth_with_cas.pm) and a [phpCAS example mirror](https://git.mdrn.pl/piwik-CASLogin.git/blob/HEAD:/CAS/docs/examples/example_advanced_saml11.php), checked 2026-10-27 (search results only)
- Tenable, [CVE-2025-64484, oauth2-proxy header stripping](https://www.tenable.com/cve/CVE-2025-64484), checked 2026-10-27
- Corgea, [CVE-2026-52845, Caddy forward_auth header stripping](https://corgea.com/advisories/vulnerabilities/CVE-2026-52845), checked 2026-10-27
- GitHub, [firebase/php-jwt security advisories](https://github.com/firebase/php-jwt/security/advisories), checked 2026-10-27
- GitHub, [web-token/jwt-framework security advisories](https://github.com/web-token/jwt-framework/security/advisories), checked 2026-10-27
- Packagist, [onelogin/php-saml](https://repo.packagist.org/p2/onelogin/php-saml.json), checked 2026-10-27
- Packagist, [litesaml/lightsaml](https://repo.packagist.org/p2/litesaml/lightsaml.json), checked 2026-10-27
- Packagist, [simplesamlphp/saml2](https://repo.packagist.org/p2/simplesamlphp/saml2.json), checked 2026-10-27
- GitHub, [lightsaml CVE-2026-63182](https://github.com/litesaml/lightsaml/security/advisories/GHSA-w553-pwx6-3mg9), checked 2026-10-27
- GitHub, [lightsaml GHSA-rm7m-7jq4-gw46](https://github.com/litesaml/lightsaml/security/advisories/GHSA-rm7m-7jq4-gw46), checked 2026-10-27
- GitHub, [simplesamlphp/saml2 security advisories](https://github.com/simplesamlphp/saml2/security/advisories), checked 2026-10-27
- GitHub, [SAML-Toolkits/php-saml security advisories](https://github.com/SAML-Toolkits/php-saml/security/advisories), checked 2026-10-27
- IETF, [RFC 7662, OAuth 2.0 Token Introspection](https://www.rfc-editor.org/rfc/rfc7662.html), checked 2026-10-27

## Second pass (2026-10-27)

Added 8 practices (S41 to S48), tagged against the code on main: 7 `Not yet`, 1 `Documented`. Corrected 5 first-pass claims (listed below). Eleven other entries gained detail or sources. S47 is in section 5.

Corrected:
- S26: the first pass cited ASVS V6.3.8, which is about account enumeration. Replaced with V16.5.1.
- S3: the first pass allowed `SameSite=Strict`. Changed to `Lax`, with the reason (S41).
- S19: the first pass said no document names the CAS login-age attributes. The CAS 3.0 spec names `authenticationDate`, `isFromNewLogin` and `longTermAuthenticationRequestTokenUsed`.
- S38, S39 and the gap list: the first pass said the repo has no `.gitlab-ci.yml`. It has one (PREFLIGHT components pinned to a commit).
- S39: the Docker matrix now includes PHP 8.5, and CI runs 8.3, 8.4 and 8.5 through PREFLIGHT.
- S43 (new) records that the PHP manual itself advises a grace period over `session_regenerate_id(true)`, which the first pass left as an open trade-off.

Checked and found correct: PHP support dates (S37), NIST AAL2 24 hours and 1 hour (S5), the ASVS 5.0 requirement numbers V6.8.1 to V6.8.4, V7.1.1, V7.1.3, V7.2.4, V7.3.1, V7.3.2, V7.4.1, V7.6.1, V9.1.2, V9.1.3, V9.2.1 to V9.2.3, V1.5.1 and V1.5.2, the `ST-` ticket prefix, Guzzle's zero default timeout, and the `__Host-` OWASP recommendation. The rfc6265bis reference is still a draft, as the first pass said.

Extended with new sources: S4, S5, S10, S12, S20, S23, S27, S32, S33, S34, S36.

Not verified, so read with care: the dispute status of `firebase/php-jwt` CVE-2025-45769; CVE-2025-27773 and the follow-up ruby-saml CVEs (66567, 66568), seen in search results only; dates of the 2026 SAML2 library CVEs; Safari support for `Clear-Site-Data`; the latest phpCAS release (a search suggested 1.6.2, not confirmed); the vendor SameSite and SAML pages; the fixed version for CVE-2026-15243. Nothing found in any page tried to give instructions to the reader.
