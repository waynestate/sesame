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
Set `session.use_strict_mode=1`, `session.use_only_cookies=1`, `HttpOnly`, `Secure` on HTTPS, and `SameSite=Lax` or `Strict`.
Why: strict mode stops an attacker from choosing the ID, and the flags limit theft and cross-site use.
Sources: [PHP session configuration](https://www.php.net/manual/en/session.configuration.php), [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), [OWASP CSRF Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html) (SameSite is defence in depth only).
How the stack holds it: `Documented`. The README tells apps to let HELMET set the flags. HELMET's `SessionHardening::apply()` sets them, but nothing checks that the app calls it, and `NativeSession` does not check that strict mode is on.

### S4. Use a `__Host-` session cookie name
Name the session cookie with the `__Host-` prefix. The browser then requires `Secure`, `Path=/` and no `Domain`.
Why: a sibling subdomain under `*.apps.wayne.edu` cannot overwrite a host-only cookie.
Sources: [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), [Cookie prefixes in the cookie spec draft](https://datatracker.ietf.org/doc/draft-ietf-httpbis-rfc6265bis/).
How the stack holds it: `Not yet`. Neither SESAME nor HELMET sets a cookie name or prefix.

### S5. Enforce idle and absolute timeouts on the server
End the session after a period of inactivity and after a maximum lifetime, whatever the activity. Do not rely on PHP's garbage collection for this.
Why: a stolen or forgotten session should stop working on its own. PHP's `gc_maxlifetime` only marks data as collectable, and collection is random.
Sources: [ASVS 5.0 V7.3.1 and V7.3.2](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x16-V7-Session-Management.md), [NIST SP 800-63B-4](https://pages.nist.gov/800-63-4/sp800-63b.html) (AAL2: 24 hours overall, 1 hour idle), [PHP session configuration](https://www.php.net/manual/en/session.configuration.php).
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
Sources: [CAS Protocol 3.0 (service identifier, ticket binding)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html), [oauth2-proxy configuration (forwarded-header trust)](https://oauth2-proxy.github.io/oauth2-proxy/configuration/overview/).
How the stack holds it: `Enforced`. `CasDriver` takes `serviceUrl` in its constructor and reads nothing from the request except the `ticket` query value (`src/Drivers/CasDriver.php:67-95`). `CasDriverTest::testServiceUrlComesFromConfigOnly` covers it.

### S11. Talk to the CAS server over HTTPS only
Both the CAS base URL and the service URL must be absolute `https://` URLs.
Why: the validation response is the whole proof of identity, so a plain-HTTP call can be forged on the path. The CAS spec does not itself require TLS for validation, so the deployer must.
Sources: [CAS Protocol 3.0 (security considerations)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html), [OWASP Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).
How the stack holds it: `Enforced`. The constructor throws `InvalidConfig` for a non-`https://` URL (`src/Drivers/CasDriver.php:46-52`, tests `testRejectsAnHttpBaseUrl`, `testRejectsAnHttpServiceUrl`). The check is a prefix test only (see the gap list).

### S12. Check and encode the ticket before it leaves the app
Reject a ticket that is not a string, is empty, is too long or has odd characters. URL-encode both `ticket` and `service` in the validation request.
Why: an unencoded ticket allows parameter injection into the validation URL.
Sources: [CAS Protocol 3.0 (ticket format and length)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html).
How the stack holds it: `Enforced`. `assertValidTicket()` allows letters, digits, `.`, `_` and `-` up to 256 characters, and rejects arrays. The query is built with `PHP_QUERY_RFC3986` (`src/Drivers/CasDriver.php:78-81, 105-114`). Tests: `testRejectsABadTicketBeforeAnyHttpCall`, `testValidateUrlEncodesServiceAndTicket`.

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
How the stack holds it: `Documented`. The code comment says it relies on PHP 8 defaults and passes no `LIBXML_NOENT` (`src/Drivers/CasDriver.php:116-128`). It does not pass `LIBXML_NONET`, does not reject a DOCTYPE, and has no test with an XXE payload.

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
Why: without it, an attacker can obtain a ticket for their own account and make a victim's browser deliver it, which signs the victim in as the attacker.
Sources: [OWASP CSRF Prevention (login CSRF and pre-sessions)](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html).
How the stack holds it: `Not yet`. CAS carries no state parameter, and `CasDriver::login()` accepts any valid ticket that arrives at the service URL (`src/Drivers/CasDriver.php:69-75`).

### S19. Force fresh credentials for sensitive actions
Support `renew` and read the authentication details the server returns, so a sensitive action can require a recent, direct login.
Why: SSO may have been satisfied hours ago, or by a remembered login.
Sources: [CAS Protocol 3.0 (renew, authentication attributes)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html), [ASVS 5.0 V6.8.4](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x15-V6-Authentication.md), [ASVS 5.0 V7.5.1](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x16-V7-Session-Management.md).
How the stack holds it: `Not yet`. There is no `renew` option. Attributes are copied into the identity, but no document says which ones show login age or a remembered login.

### S20. Accept single logout from the CAS server
When the CAS server ends a user's SSO session, it can POST a logout request to each app. The app should find the session by ticket and end it.
Why: otherwise a user who logs out of one app stays signed in to the others.
Sources: [CAS Protocol 3.0 (single logout, back-channel)](https://apereo.github.io/cas/development/protocol/CAS-Protocol-Specification.html).
How the stack holds it: `Not yet`. SESAME does not keep the ticket with the session, so it cannot map a logout request to a session. ADR-0004 says SLO comes "when Academica supports it". The current logout only reaches the IdP from the app that started it.

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
Sources: [OWASP Authentication (TLS)](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).
How the stack holds it: `Not yet`. SESAME takes any client and sets nothing. The README example is `new \GuzzleHttp\Client()` with defaults, and Guzzle's default has no total timeout (`README.md:89-90`).

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
Sources: [OWASP Authentication (generic messages)](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), [ASVS 5.0 V6.3.8](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x15-V6-Authentication.md).
How the stack holds it: `Not yet`. `AuthenticationFailed` messages carry the CAS failure code (`src/Drivers/CasDriver.php:144-146`). Nothing tells apps not to print them.

### S27. Log authentication events, never secrets
Log logins, failures, logouts and session anomalies with user, driver and time. Never log tickets, session IDs, assertions or tokens.
Why: you cannot investigate or detect what you did not record, and a logged secret is a leaked secret.
Sources: [OWASP Logging](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html), [OWASP Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).
How the stack holds it: `Not yet`. SESAME has no logger or event hook.

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
Never read a bare identity header. Verify a signature from the gateway, and do not use the sender's IP as proof.
Why: Citadel's proxy strips no client headers, and app containers likely share one network, so a plain header can come from anywhere.
Sources: [Google IAP signed headers](https://docs.cloud.google.com/iap/docs/signed-headers-howto), [Cloudflare Access JWT validation](https://developers.cloudflare.com/cloudflare-one/identity/authorization-cookie/validating-json/), [Pomerium identity verification](https://www.pomerium.com/docs/capabilities/getting-users-identity), [oauth2-proxy configuration](https://oauth2-proxy.github.io/oauth2-proxy/configuration/overview/).
How the stack holds it: `Documented`. ADR-0004 decision 3 and the CREED auth handbook say so. The driver is not built.

### S31. Check audience, expiry and replay on every assertion
Require the app's host as audience, an expiry of 60 seconds or less, and an issued-at time. Reject expired, wrong-audience, unsigned and replayed assertions.
Why: a captured assertion must not work against another app or later.
Sources: [RFC 8725 (JWT best practices)](https://www.rfc-editor.org/rfc/rfc8725.html), [ASVS 5.0 V9.2.1 and V9.2.3](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x18-V9-Self-contained-Tokens.md), [NIST SP 800-63C-4 (audience and replay)](https://pages.nist.gov/800-63-4/sp800-63c.html).
How the stack holds it: `Documented`. ADR-0004 lists the claims and rejections. No code and no tests yet.

### S32. Pin the algorithm and the key source
Accept one named algorithm per key. Never accept `none`. Take keys only from configuration, never from a URL named inside the token.
Why: attackers pick weak algorithms, or point the verifier at their own key.
Sources: [RFC 8725 sections 3.1 and 3.2](https://www.rfc-editor.org/rfc/rfc8725.html), [ASVS 5.0 V9.1.2 and V9.1.3](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x18-V9-Self-contained-Tokens.md).
How the stack holds it: `Not yet`. The ADR allows either a shared HMAC key or the gateway's public key and leaves the choice open (D-002).

### S33. Keep token types apart
Mark each token kind with its own `typ`, audience or key, so a gateway assertion cannot be accepted as an OAuth2 bearer token, or the reverse.
Why: two drivers that validate JWTs from the same issuer can be tricked into accepting each other's tokens.
Sources: [RFC 8725 sections 3.11 and 3.12](https://www.rfc-editor.org/rfc/rfc8725.html), [ASVS 5.0 V9.2.2](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x18-V9-Self-contained-Tokens.md).
How the stack holds it: `Not yet`.

### S34. The `saml` driver: use a maintained library and require signatures
Do not write XML signature code. Use a maintained SAML library and require signed responses or assertions. Check Destination, Audience, Recipient, InResponseTo and the validity window, and accept each assertion once.
Why: SAML parsing and signature checks have a long history of bypasses, such as signature wrapping.
Sources: [OWASP SAML Security](https://cheatsheetseries.owasp.org/cheatsheets/SAML_Security_Cheat_Sheet.html), [ASVS 5.0 V6.8.2 and V6.8.3](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x15-V6-Authentication.md), [NIST SP 800-63C-4](https://pages.nist.gov/800-63-4/sp800-63c.html).
How the stack holds it: `Not yet`. The library is not chosen. The SAML research lists candidates, and several have advisories or high PHP floors.

### S35. The `saml` driver: start logins at the app, and allowlist the return address
Prefer service-provider-started logins, and treat an IdP-started login as an injection risk. Validate `RelayState` against an allowlist.
Why: a login the app never asked for can be forced onto a victim, and `RelayState` is an open redirect if used raw.
Sources: [OWASP SAML Security](https://cheatsheetseries.owasp.org/cheatsheets/SAML_Security_Cheat_Sheet.html), [NIST SP 800-63C-4 (injection protection)](https://pages.nist.gov/800-63-4/sp800-63c.html).
How the stack holds it: `Not yet`.

### S36. The `oauth2` driver: verify the whole token
Check signature, issuer, audience, expiry and scopes. Read the token from the `Authorization` header, never from a query string. Reject tokens meant for another service.
Why: a token valid somewhere else is not valid here.
Sources: [RFC 9700 (OAuth 2.0 Security BCP)](https://www.rfc-editor.org/rfc/rfc9700.html), [RFC 8725](https://www.rfc-editor.org/rfc/rfc8725.html), [ASVS 5.0 V9](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x18-V9-Self-contained-Tokens.md).
How the stack holds it: `Documented`. ADR-0004 decision 3 lists the checks. The header-only rule is not written down, and the scopes and flows that Academica offers are open (D-004).

## 6. Build and supply chain

### S37. Support only PHP versions that are not end of life
The library requires PHP 8.2 or later, and 8.3 or later from 2027-01-01.
Why: EOL PHP gets no security fixes, and session and crypto fixes are part of that.
Sources: none specific. The version rule is ADR-0003 and ADR-0004.
How the stack holds it: `Checked`. `composer.json` requires `^8.2`, and PROCTOR's PHP floor check compares an app's floor with `policy.json`.

### S38. Audit dependencies for known vulnerabilities
Run a Composer audit in CI and before release.
Why: auth code pulls in HTTP and XML packages whose bugs are your bugs.
Sources: none specific. This is general supply-chain practice.
How the stack holds it: `Checked` in apps, through PROCTOR's `ComposerAuditCheck`. The SESAME repo has no CI file yet.

### S39. Run the tests on every supported PHP
Run the suite on the lowest and the highest supported PHP.
Why: session and XML behaviour differ between PHP versions.
Sources: none specific. The PHP manual notes that session settings change between versions, for example in 8.4.
How the stack holds it: `Documented`. The README gives Docker commands for 8.2 and 8.4. There is no pipeline file in the repo.

### S40. Compatibility shims must not bring back old weaknesses
The planned wayne-cas and simplecas shims map old method names onto the core. They must not restore unencoded tickets, forwarded-header trust or sessions with no logout.
Why: the old clients have these faults, and a shim that copies behaviour copies the faults.
Sources: [ASVS 5.0 V6.8](https://github.com/OWASP/ASVS/blob/v5.0.0/5.0/en/0x15-V6-Authentication.md).
How the stack holds it: `Documented`. ADR-0004 says the shims are thin adapters with no rules. They are not built.

## Gaps for the backlog

Risks in the current code:
- `src/functions.php:84-86, 118` and `README.md:45-47`: no exit after a redirect, and the README example keeps running.
- `src/Sesame.php:42-46`: a null user from the resolver still creates a session with `sesame.identity` set.
- `src/Sesame.php:45-46`: the whole resolver result and the full identity go into the session.
- `src/Drivers/FakeDriver.php:32`: only the exact word `production` is refused. Use an allowlist such as `local`, `development` and `testing`.
- `src/Session/NativeSession.php:54-60`: `invalidate()` leaves an empty session with a new ID and a live cookie. Call `session_destroy()` and expire the cookie.
- `src/Session/NativeSession.php:24, 59`: `session_regenerate_id(true)` deletes the old session at once. The PHP manual warns this can drop a session on an unstable network. Decide the trade-off and write it down.
- `src/Drivers/CasDriver.php:97-100` and `src/functions.php:118`: `$returnTo` is not validated.
- `src/Drivers/CasDriver.php:69-75`: no login binding to the browser.
- `src/Drivers/CasDriver.php:110`: the ticket pattern accepts any prefix. The CAS spec says service tickets start with `ST-`.
- `src/Drivers/CasDriver.php:125-128`: no `LIBXML_NONET`, no DOCTYPE rejection, and the error-handling flag is set to `false` rather than restored to its old value.
- `src/Drivers/CasDriver.php:136`: the `//` search finds the success element at any depth. Read it as a direct child of the response root.
- `src/Drivers/CasDriver.php:183-193`: the HTTPS check is a prefix test. `https://` alone, or a URL with user info, passes. Parse the URL and check the host.
- `README.md:89-90`: the example client has no timeout.
- Repo: no `.gitlab-ci.yml`, so no test or audit job runs on push.

Missing practices:
- Idle and absolute session timeouts, with a configurable value.
- A `__Host-` cookie name, set by HELMET.
- Ticket removal from the URL after login.
- Single logout, which needs the ticket stored with the session.
- `renew` support and a documented way to read login age.
- A logging hook (PSR-3) for authentication events.
- A written rule for the user key (driver plus ID) and for what the resolver must do on a deny.
- A documented lifetime table for each app, covering app, SESAME and IdP sessions.
- Tests: XXE payload, null resolver result, replayed gateway assertion once that driver exists.
- Choose the signing scheme for `gateway`, and the SAML library for `saml` (D-001, D-002).
- Questions for the IdP owner: SAML single logout, signed assertions, released attributes, and `/p3/serviceValidate` attribute release.

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
