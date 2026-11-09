---
title: Drivers
description: The full list of drivers and their status: fake, cas, gateway, saml, oauth2 and oidc.
---

# Drivers

The drivers SESAME can build, their status, and the exact values each one ships with. Pick a driver with [Which driver for my app?](../guides/which-driver.md), then read the matching guide for setup.

## Status

| Driver | Status |
|---|---|
| `fake` | Now. Local development and tests only. Refuses to boot in production. |
| `cas` | Now. CAS 2.0 against Academica by default. |
| `gateway` | Now (built and tested). The recommended driver for apps on Citadel once Drawbridge is live: a signed JWT assertion from the auth gateway (on Citadel, that is `auth.apps.wayne.edu`, the first planned one), never a bare header. No Entra registration per app: Drawbridge holds it once. Drawbridge is released (0.4.0) but has no production gateway yet, so a Citadel app uses `cas` today. |
| `saml` | Now, opt-in. A SAML 2.0 service provider for a Shibboleth-style IdP; signed assertions required. No default IdP: the app names the IdP's metadata. Needs `onelogin/php-saml` 4.3.2 or later. |
| `oauth2` | Now. A JWT bearer token verified against the issuer's key set (JWKS), RS256 only. Microsoft Entra ID first. APIs only. |
| `oidc` | Now. Browser sign-in with OpenID Connect (code flow with PKCE), Microsoft Entra ID first. Needs its own Entra registration per app; for apps off Citadel and APIs. Needs `league/oauth2-client`. |

## The factory

`DriverFactory::make()` builds the driver named by the `driver` config value. Anything else throws `InvalidConfig` with `Unknown driver "<name>".`

```php
public static function make(
    Config $config,
    ?ClientInterface $http = null,
    ?RequestFactoryInterface $requests = null,
    ?ClockInterface $clock = null,
    ?ReplayGuard $replayGuard = null,
    ?CacheInterface $jwksCache = null,
    ?SessionPort $session = null,
): Driver
```

| Argument | Driven by | What it does |
|---|---|---|
| `$http` (PSR-18 client) | `cas`, `oauth2`, `oidc`, `saml` (metadata URL only) | Talks to the identity provider over HTTP. The factory cannot build an HTTP client itself, so the app passes one. |
| `$requests` (PSR-17 request factory) | `cas`, `oauth2`, `oidc`, `saml` (metadata URL only) | Builds the HTTP requests the drivers send. |
| `$clock` (PSR-20 clock) | `cas`, `gateway`, `oauth2` | Supplies the sign-in time. Without one, those drivers use the system clock. |
| `$replayGuard` (a `ReplayGuard`, for example a `Psr16ReplayGuard` over a PSR-16 cache) | `gateway`, `saml` | Remembers used assertion `jti` values (for `saml`, assertion IDs) so a captured assertion cannot be replayed. Fifth argument. Optional for `saml`, which builds a `Psr16ReplayGuard` over the sixth argument's cache without one. |
| `$jwksCache` (PSR-16 cache) | `oauth2`, `oidc`, `saml` | Holds the fetched key set. Sixth argument. Required for `oauth2`; optional for `oidc`, which keeps the discovery document and the key set in process memory without one. Required for `saml`, which parks the IdP's response there, keeps fetched metadata and remembers used assertion IDs. |
| `$session` (the app's `SessionPort`) | `cas`, `oidc`, `saml` | Holds the driver's pending sign-in (for `oidc` the state, nonce and PKCE verifier; for `saml` the AuthnRequest ID), bound to the browser. Seventh argument. |

When a required argument or config value is missing, the factory throws `InvalidConfig`:

| Missing | Message |
|---|---|
| PSR-18 client for `cas` | `The cas driver needs a PSR-18 client. Pass $http to DriverFactory::make().` |
| PSR-17 request factory | `The cas, oauth2 and oidc drivers need a PSR-17 request factory. Pass $requests to DriverFactory::make().` |
| PSR-18 client for `oauth2` | `The oauth2 driver needs a PSR-18 client to fetch the issuer's key set. Pass $http to DriverFactory::make().` |
| PSR-16 cache for `oauth2` | `The oauth2 driver needs a PSR-16 cache to hold the fetched key set. Pass $jwksCache to DriverFactory::make().` |
| `oidc` config block | `The oidc driver needs the "oidc" config block.` |
| PSR-18 client for `oidc` | `The oidc driver needs a PSR-18 client for discovery, the key set and the token request. Pass $http to DriverFactory::make().` |
| App session for `oidc` | `The oidc driver needs the app session to bind each sign-in to the browser. Pass $session to DriverFactory::make().` |
| `saml` config block | `The saml driver needs the "saml" config block.` |
| App session for `saml` | `The saml driver needs the app session to bind each sign-in to the browser. Pass $session to DriverFactory::make().` |
| PSR-16 cache for `saml` | `The saml driver needs a PSR-16 cache to park the IdP response and remember used assertions. Pass $jwksCache to DriverFactory::make().` |
| Non-empty `gateway.public_key`, `gateway.issuer` or `gateway.audience` (and `oauth2.issuer`, `oauth2.jwks_url`, `oauth2.audience`) | `The config key "%s" must be a non-empty string.` |
| Unknown driver | `Unknown driver "%s".` |

## fake

A driver for local development and tests only. It returns the identity it was built with, every time. It refuses to construct outside the allowed development environments: it throws, it never silently falls back. The check is an allowlist, so an environment that is not explicitly a development one (production, staging, or anything unknown) can never boot the fake driver.

| Value | Exact |
|---|---|
| Name | `fake` |
| Allowed environments | `local`, `development`, `dev`, `testing`, `test` (an allowlist, matched after lowercasing and trimming) |
| Identity | The username and attributes it was built with, every time |
| Outside the allowlist | Throws `FakeDriverInProduction` with `The fake driver only runs in local, development and testing environments.` |
| `logoutUrl()`, `serviceUrl()` | `null` |

The factory builds it from the `fakeUsername` and `fakeAttributes` config properties, with the username defaulting to the empty string.

## cas

CAS 2.0 against Academica by default.

- The service URL is the configured value only. This driver never reads `Host`, `X-Forwarded-Proto`, `X-Forwarded-Host` or any other request header to build it, which removes the wayne-cas proto trust and the phpCAS `X-Forwarded-Port` break in one rule.
- The default protocol is 2.0 because Academica is believed to redirect cookieless `/p3/serviceValidate` calls to the login page. Set the protocol to 3.0 (`cas.protocol`) when a CAS server does release attributes on `/p3/serviceValidate`.
- Ticket replay: the CAS server refuses a reused ticket with `INVALID_TICKET`. SESAME relies on that and surfaces it as `AuthenticationFailed`.
- The sign-in time comes from the CAS `authenticationDate` attribute when the server sends one; otherwise it is the driver's clock. Time is read through a PSR-20 clock, so the future-date guard is testable.
- CAS carries no MFA signal of its own, so by default the identity carries no MFA facts and the MFA check fails closed. With `assume_mfa` on, the driver trusts the CAS server's owner's word that a successful validation means MFA happened for the user's current SSO session: the identity's `amr` then includes `mfa`, and its MFA time (`authTime`) is the `authenticationDate` when the server sent one. The driver never invents an `authTime`: with no `authenticationDate`, the MFA time stays unset and the MFA check answers `NO_AUTH_TIME`.

| Value | Exact |
|---|---|
| Protocol | `2.0` by default; only `2.0` or `3.0` are accepted |
| `cas.base_url`, `cas.service_url` | Must be absolute `https://` URLs |
| Ticket | An `ST-` service ticket, at most 256 characters (`^ST-[A-Za-z0-9._\-]{1,253}$`) |
| Validation path | `/serviceValidate` for 2.0, `/p3/serviceValidate` for 3.0 |
| Login query | `service=` always; `renew=true` when `renew` is on |
| Logout URL | `/logout?service=<return>&url=<return>` (Academica reads `url=`; `service=` is the CAS-standard parameter name) |

The exact error messages:

| Condition | Message |
|---|---|
| Bad protocol | `The config key "cas.protocol" must be "3.0" or "2.0", got "%s".` |
| Bad URL | `The config key "%s" must be an absolute https:// URL, got "%s".` |
| Bad ticket | `The ticket in the request is not a valid CAS ticket.` |

## gateway

Signed JWT assertions from the Drawbridge gateway. "Open Sesame", but only the real magic word gets the door.

- The driver reads one configured header that holds a signed JWT, verifies its signature against the gateway's public key, and checks the issuer, audience and time claims.
- It never reads a plain identity header (`Remote-User`, `X-Remote-User`, or any spelling of them), because a plain header can come from anywhere on the shared network.
- The request must also come from a trusted proxy: the direct peer (`REMOTE_ADDR`) must match one of the configured CIDR ranges. A valid assertion from an untrusted peer is still rejected.
- The algorithm is pinned from config, so a token that claims a different algorithm (or `none`) is refused before its signature is even looked at.
- Every assertion is single-use. The gateway signs a fresh assertion for every request, so a captured one is only dangerous if it can be sent twice: the driver requires a `jti` claim, refuses assertions that live longer than the configured maximum, and hands the `jti` to a `ReplayGuard`, which remembers it for the assertion's lifetime. A second use of the same `jti` is a replay and is refused.
- A token without an issue time or an expiry is not a usable assertion, and a token without a subject has no one to sign in. The subject becomes the username.

| Value | Exact |
|---|---|
| Assertion header | `X-Sesame-Assertion` by default (the factory's default when the config names none) |
| Max assertion lifetime | 300 seconds (the constructor default for `maxLifetimeSeconds`) |
| Replay guard | Required unless `gateway.allow_without_replay_guard` is on (local development only) |
| Required claims | A subject, an issue time and an expiry, plus the `jti` for the replay guard |
| Public keys | a single `gateway.public_key`, `gateway.public_keys` as a map of `kid` to key, or `gateway.jwks_file`, a JWKS file of the same keys read at boot |
| Choosing a key | the token's `kid` names the listed key; an unknown `kid` is refused, and so is a missing one when two or more keys are listed (one listed key verifies a token without a `kid`) |
| Key set file limits | at most 64 KB and 8 keys, public keys only, one `kid` each, the type `gateway.algorithm` needs |

The exact error messages:

| Condition | Message |
|---|---|
| No replay guard | `The gateway driver needs a ReplayGuard so a captured assertion cannot be replayed. Pass one to the driver (or to DriverFactory::make()), or set gateway.allow_without_replay_guard to true for local development only.` |
| Empty key map | `The config key "gateway.public_keys" must be a non-empty map of kid to key.` |
| Two key settings with a key set file | `Set only one of "gateway.public_key", "gateway.public_keys" and "gateway.jwks_file": they are three ways to give the same keys.` |
| Missing key set file | `The gateway key set file named by "gateway.jwks_file" does not exist or cannot be read.` |
| Private key material in the key set | `The key "<kid>" in the gateway key set ("gateway.jwks_file") holds private key material. Publish only the public half, and treat that private key as exposed.` |

## oauth2

JWT bearer tokens from an identity provider. "Open Sesame", where the magic word is a token only the issuer can sign.

- The driver reads the `Authorization` header (a Bearer token, per RFC 6750), verifies its RS256 signature against a key from the issuer's JWKS, and checks the issuer, audience, time, scope and role claims.
- It accepts only RS256: `none` and HMAC algorithms are refused before their signatures are even looked at, because an HMAC token signed with the public key is a classic forgery.
- Microsoft Entra ID is the first planned issuer: point `issuer`, `jwks_url` and `audience` at the tenant's v2.0 endpoints and the app's client id, and the driver signs the user in from the access token. Any other RS256 issuer works with the same settings.
- The username comes from one configurable claim: `preferred_username` by default, or `accessid` (the UPN's mail prefix) when the app wants that.
- There is no redirect step and no logout on the provider side: the token arrives with the request, and signing out is the app's own session being dropped.

| Value | Exact |
|---|---|
| Token | A Bearer token in the `Authorization` header (RFC 6750) |
| Algorithm | RS256 only; `none` and HMAC are refused |
| Identity claim | `preferred_username` by default, or `accessid` |
| Key set | Fetched from `jwks_url` over HTTP and kept in the PSR-16 cache |
| Unknown key | `AuthenticationFailed` with `No published signing key matches the access token (oauth2.unknown_key).` |

## oidc

Browser sign-in with OpenID Connect, Microsoft Entra ID first. "Open Sesame" said to the right door, then checked at the counter. The driver runs the authorization code flow with PKCE (S256):

1. A request without a code is the start. The driver makes a fresh `state` and `nonce`, has `league/oauth2-client` build the authorization URL and the PKCE verifier, keeps all three in the app's session (bound to this browser, single use, expiring), and answers with a `Redirect` to the identity provider.
2. A request with a code (or an error) is the callback. The pending sign-in for its `state` is taken out of the session first, so a replayed callback finds nothing. `league/oauth2-client` exchanges the code with the PKCE verifier and the client secret, and SESAME's own validator (the same `firebase/php-jwt` and `JwksProvider` the oauth2 driver uses) checks the ID token: signature on an RSA allowlist, `iss`, `aud`, `azp`, `exp`, `nbf`, `iat` and the nonce.

- The endpoints come from the issuer's discovery document, kept in a PSR-16 cache, unless config names them.
- No `prompt` and no `max_age` are sent by default, so an existing Entra session signs the user in without a click.
- A start request with `step_up=1` adds the app's configured step-up parameters (`prompt=login` by default, and optionally `max_age`, `acr_values` and `claims`), and its callback then requires a fresh `auth_time`.
- The username comes from one configurable claim (`accessid` by default). Falling back to the part of `preferred_username` before the `@` is an opt-in (`oidc.username_fallback`), because users can change `preferred_username`.
- `iss`, `sub`, `tid` and `oid` are passed to the resolver, so apps can key accounts on issuer plus `sub`, or tenant plus `oid`. The roles claim (`wsu_roles` by default) becomes the `roles` attribute, the claims the app lists in `oidc.claims` (`wsu_roles` and `accessid` by default) are passed through under their own names, and `auth_time` and `amr` become the identity's MFA facts with the same rules as the cas driver's `authenticationDate`: the driver never invents a sign-in time.
- The access token and the ID token are never stored: the identity is all the session keeps.
- The driver needs `league/oauth2-client`, which SESAME suggests but does not require.
- The token request goes through Guzzle (via `league/oauth2-client`). When the app's PSR-18 client is Guzzle, it is reused for that too, with redirects turned off on each token request; otherwise a Guzzle client is built that does not follow redirects and gives up after ten seconds. Discovery and the key set go through the PSR-18 client.

## saml

Browser sign-in as a SAML 2.0 service provider, opt-in, for a Shibboleth-style IdP. The same magic words, now with a schema. The driver stands on `onelogin/php-saml` (strict mode) and runs in three requests:

1. A request to the login URL without `saml_handle` is the start. The driver builds an AuthnRequest (HTTP-Redirect, signed when configured), keeps its ID in the app's session (single use, expiring), and answers with a `Redirect` to the IdP; a checked `return_to` rides along as the RelayState.
2. The IdP's POST to the ACS URL goes to `receive()`, not `login()`. It carries no Lax session cookie, so the driver parks the raw Response in the PSR-16 cache under a single-use 256-bit handle, without touching the session, and answers with a `Redirect` (sent as 303) to the login URL with `saml_handle`.
3. That GET is the finish. The parked Response and its pending request are taken (both single use), and php-saml checks the schema, the signature and what it covers, Destination and Recipient (against the configured ACS URL), Audience, Issuer, InResponseTo, the time conditions and the bearer subject confirmation. SESAME adds a required assertion signature, SHA-2 only, an IssueInstant within the leeway and the maximum age, and the replay guard on the assertion ID.

- IdP-initiated responses are refused; a sign-in must start at the app.
- The username comes from one mapped attribute (`accessid`, from `uid`, by default). `email`, `name`, `affiliations` and `eppn` are mapped from the eduPerson and inetOrgPerson OIDs or their friendly names, and `issuer`, `name_id` and `authn_context` are passed too.
- `AuthnInstant` is the identity's sign-in time, never invented; an `AuthnContextClassRef` in `saml.mfa_contexts` (the REFEDS MFA profile by default) adds `mfa` and is the identity's authentication context.
- `step_up=1` sends `ForceAuthn` and requires a fresh `AuthnInstant`.
- No single logout: `logoutUrl()` returns `null`, so logout is local.
- `metadata()` returns the SP metadata, with `WantAssertionsSigned="true"`.
- The driver needs `onelogin/php-saml` 4.3.2 or later (and xmlseclibs 3.1.5 or later), which SESAME suggests but does not require. Older releases are refused at boot and by `composer.json`'s `conflict`.

Setup and every key: [The saml driver](../guides/driver-saml.md).

## What every driver returns

Every driver exposes the same four methods: `name()`, `login()`, `logoutUrl()` and `serviceUrl()`. `login()` answers with a `Redirect` to the identity provider, or with an `Authenticated` step carrying an `Identity` (the username, the attributes, the driver name and the sign-in time). The `fake` driver's `logoutUrl()` and `serviceUrl()` return `null`; the `cas` driver's return the configured logout URL and service URL.

## Walk around the shop

The front page's counter, pegboard and duplicator.

![Three cuts of the front page: the hero with SESAME's mark under the shop light; the pegboard 'One blank per protocol', with all six keys cut, fake marked local only, cas, gateway, oauth2 and oidc marked now, and saml marked opt-in; and the duplicator 'Swap the blank. Keep the door.', which re-keys an app from cas to gateway and opens the door for ab1234.](../readme/gallery.jpg)

## Related

- [Which driver for my app?](../guides/which-driver.md)
- [The cas driver](../guides/driver-cas.md)
- [The gateway driver](../guides/driver-gateway.md)
- [oauth2 with Entra](../guides/driver-oauth2-entra.md)
- [The oidc driver](../guides/driver-oidc.md)
- [The saml driver](../guides/driver-saml.md)
- [Configuration](configuration.md)
