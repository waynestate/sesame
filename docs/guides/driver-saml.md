---
title: The saml driver (SAML 2.0 sign-in)
description: Browser sign-in as a SAML 2.0 service provider against a Shibboleth-style IdP, opt-in, with signed assertions required.
---

# The saml driver (SAML 2.0 sign-in)

The goal: sign a person into your app through the browser with SAML 2.0, with your app as the service provider (SP) and a Shibboleth-style identity provider (IdP) vouching for them. This page walks through how a sign-in runs, every config key, and what to hand the IdP's administrators; then set the driver up on the [Raw PHP](raw-php.md) or [Laravel](laravel.md) page.

> [!NOTE]
> The saml driver is opt-in. Nothing in it runs unless `SESAME_DRIVER=saml`, and SESAME assumes no IdP: there is no default WSU IdP entity, so you name the IdP's metadata yourself. On Citadel prefer the `gateway` driver behind Drawbridge, and for Entra prefer `oidc`. Reach for SAML when the IdP you must use speaks only SAML.

## What you need

Open sesame, in a language with a schema. The saml driver stands on
`onelogin/php-saml`, which SESAME suggests but does not require:

```
composer require onelogin/php-saml
```

Without it, building the driver fails with `The saml driver needs
onelogin/php-saml. Install it with: composer require onelogin/php-saml`.
With a release older than the floor (php-saml 4.3.2, xmlseclibs 3.1.5, both
for published signature and encryption advisories), building the driver
fails too, and SESAME's `composer.json` refuses to install those releases
beside it. Why that library, and what else was weighed:
[docs/saml-library-choice.md](../saml-library-choice.md).

You also need:

- The IdP's metadata: an https URL, or the XML saved as a file in the
  deploy. Exactly one.
- A shared PSR-16 cache (in Laravel, a cache store every web server sees).
  The driver parks the IdP's answer there for two minutes, keeps the fetched
  metadata, and remembers the assertion IDs it has accepted.
- The app's session (SESAME's `SessionPort`), as for `cas` and `oidc`.
- Optionally an SP key pair, as file paths, for signed AuthnRequests and the
  certificate in the SP metadata.

## How a sign-in runs

A SAML sign-in takes three requests, one more than you might expect.

1. **The start.** A request to the login URL without `saml_handle` builds an
   AuthnRequest (the HTTP-Redirect binding, signed when
   `saml.sign_authn_requests` is on), keeps its ID in the app's session
   (bound to this browser, single use, gone after ten minutes), and
   redirects to the IdP. A checked `return_to` rides along as the
   RelayState.
2. **The ACS.** The IdP posts its Response to the ACS URL. That POST is
   cross-site, so the browser sends no `SameSite=Lax` session cookie with it
   (S35, S41), and a session started there would replace the user's real
   one. So the ACS never touches the session: it parks the raw, unchecked
   Response in the PSR-16 cache under a random 256-bit handle (single use,
   two minutes) and answers `303 See Other` to the login URL with
   `saml_handle=<handle>`.
3. **The finish.** That GET is a top-level navigation, so the session
   cookie is back. The driver takes the parked Response and the pending
   request it answers (both single use), and php-saml validates it in strict
   mode: the XML schema (no DOCTYPE), the signature and exactly what it
   covers, Destination and Recipient against the configured ACS URL,
   Audience against the SP entity ID, Issuer against the IdP, InResponseTo
   against the pending request, the time conditions, and the bearer subject
   confirmation. SESAME then adds its own checks: a signed assertion
   (always), SHA-2 signatures and digests only, an IssueInstant no further
   ahead than `saml.leeway_seconds` and no older than
   `saml.max_assertion_age_seconds`, and an assertion ID the replay guard
   has never seen. The identity goes to the app's
   `findOrImportUser(Identity)`, and the core regenerates the session ID,
   as for every driver.

`sesame_login()` and the Laravel route then send the user to the checked
return-to, so the handle never stays in the address bar.

IdP-initiated (unsolicited) responses are refused: a sign-in must start at
the app. A Response that answers another browser's request (login CSRF) is
refused too, because the pending request lives in this browser's session.

## Identity, attributes and MFA

`saml.attributes` maps SESAME's names to the SAML attribute names the IdP
releases; the first name present wins. The defaults are the eduPerson and
inetOrgPerson OIDs a Shibboleth IdP releases, then their friendly names:

| SESAME name | SAML attribute names (default) |
|---|---|
| `accessid` | `urn:oid:0.9.2342.19200300.100.1.1`, `uid` |
| `email` | `urn:oid:0.9.2342.19200300.100.1.3`, `mail` |
| `name` | `urn:oid:2.16.840.1.113730.3.1.241`, `displayName` |
| `affiliations` | `urn:oid:1.3.6.1.4.1.5923.1.1.1.1`, `eduPersonAffiliation` |
| `eppn` | `urn:oid:1.3.6.1.4.1.5923.1.1.1.6`, `eduPersonPrincipalName` |

The username comes from `saml.username_attribute` (default `accessid`).
When the assertion has no such attribute, the sign-in fails with
`saml.missing_attribute` and the error names the settings to change. The
mapped attributes reach the resolver under SESAME's names
(`$identity->attributes['affiliations']`), along with `issuer`, `name_id`
(when the IdP sends one) and `authn_context`.

The AuthnStatement gives the MFA facts. `AuthnInstant` becomes the
identity's sign-in time (never invented: without one, the MFA check fails
closed). An `AuthnContextClassRef` listed in `saml.mfa_contexts` (the REFEDS
MFA profile, `https://refeds.org/profile/mfa`, by default) adds `mfa` to the
methods, and the class reference is also the identity's authentication
context, so `sesame.context:https://refeds.org/profile/mfa` works.
`saml.assume_mfa` counts every sign-in as MFA, for an IdP that enforces MFA
for this SP but does not say so.

## Step-up and return-to

A login request with `step_up=1` sends `ForceAuthn="true"`, so the IdP asks
for credentials again. The finish then requires a fresh `AuthnInstant`: not
in the future beyond the leeway, not before the sign-in started, and within
300 seconds. In Laravel, `sesame.mfa` with `mfa.on_failure=reauth` sends the
user to `/login?step_up=1` for you.

A login request may carry `return_to`. It goes out as the RelayState only
when it passes the ReturnTo rule (an absolute https URL with the login URL's
scheme, host and port, and no user name or password), and the RelayState
that comes back is checked again with the same rule, so it can never be an
open redirect.

## Logout

Local only. There is no single logout: SESAME refuses `slo` at boot, so the
app session is cleared and the IdP's session lives on (the next sign-in is
usually silent).

## Raw PHP

The ACS is its own page, and it must NOT call `session_start()`.

```php
<?php
// boot-saml.php: shared by the three pages below. No session_start() here.

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Session\NativeSession;

$config = Config::fromArray([
    'driver' => 'saml',
    'environment' => 'production',
    'saml' => [
        'sp' => [
            'entity_id' => 'https://app.example.test/metadata.php',
            'acs_url' => 'https://app.example.test/acs.php',
            'cert_file' => getenv('SESAME_SAML_SP_CERT_FILE') ?: null,
            'key_file' => getenv('SESAME_SAML_SP_KEY_FILE') ?: null,
        ],
        'idp' => [
            'metadata_file' => __DIR__.'/config/idp-metadata.xml',
        ],
        'service_url' => 'https://app.example.test/login.php',
    ],
]);

$session = new NativeSession(); // does not start the session
$driver = DriverFactory::make(
    $config,
    new \GuzzleHttp\Client(['allow_redirects' => false, 'timeout' => 10]), // only for a metadata URL
    new Psr17Factory(),
    null,
    null,
    new Psr16Cache(new FilesystemAdapter('sesame')), // shared by every web server
    $session,
);
```

```php
<?php
// acs.php: the IdP posts here. No session_start(): the 303 goes to login.php.
require __DIR__.'/boot-saml.php';
sesame_saml_acs($driver);
```

```php
<?php
// metadata.php: the SP metadata for the IdP's administrators.
require __DIR__.'/boot-saml.php';
sesame_saml_metadata($driver);
```

```php
<?php
// login.php: starts the sign-in, finishes it with the saml_handle, then
// sends the user to the checked return_to or back here.
require __DIR__.'/boot-saml.php';

use Waynestate\Sesame\Sesame;

ini_set('session.gc_maxlifetime', '7200'); // at least SESAME's idle timeout; see Raw PHP
ini_set('session.use_strict_mode', '1'); // or helmet_boot(); NativeSession warns without it
session_start();

sesame(new Sesame($driver, new MyUserResolver(), $session, $config));

$user = sesame_login();
```

A protected page sends a guest to `login.php?return_to=<its own https URL>`,
and a page that needs fresh credentials to `login.php?step_up=1`.

## Laravel

```
SESAME_DRIVER=saml
SESAME_SAML_IDP_METADATA_URL=<the IdP's metadata URL>
# or SESAME_SAML_IDP_METADATA_FILE=/path/to/idp-metadata.xml
# The SP entity ID, the ACS URL and the login URL default to the app's
# /login/saml/metadata, /login/saml/acs and /login routes.
SESAME_SAML_SP_CERT_FILE=/path/to/sp.crt
SESAME_SAML_SP_KEY_FILE=/path/to/sp.key
SESAME_SAML_CACHE_STORE=redis
```

The adapter registers two more routes beside `GET /login`, both outside the
`web` middleware group, because neither may start a session:

| Route | Name | What it does |
|---|---|---|
| `POST /login/saml/acs` | `sesame.saml.acs` | Parks the IdP's Response and answers 303 to `/login?saml_handle=...`; an unusable POST gets a plain 400. No session, no CSRF token (the IdP cannot send one). |
| `GET /login/saml/metadata` | `sesame.saml.metadata` | The SP metadata as `application/samlmetadata+xml`. |

It passes the Laravel session and the `sesame.saml.cache_store` cache to
the driver. Laravel's default `SameSite=Lax` session cookie is right: the
browser drops it on the IdP's POST, which is why the ACS parks the response,
and sends it on the 303's GET. `Strict` would drop it on that GET too.

## What to hand the IdP's administrators

Send them the SP metadata URL (`/login/saml/metadata` in Laravel). It
carries the entity ID, the ACS URL with the HTTP-POST binding,
`WantAssertionsSigned="true"` and, when set, the SP certificate. Ask them:

- to **sign assertions** (Shibboleth's `signAssertions` for this SP). SESAME
  refuses an unsigned assertion even inside a signed Response;
- to release the attributes in `saml.attributes` (by default the AccessID as
  `uid`, plus `mail`, `displayName` and `eduPersonAffiliation`);
- to sign with SHA-256 or better; SHA-1 is refused;
- not to encrypt assertions unless the app has an SP key pair to decrypt
  them with.

## Config keys

| Key | Env | Default | Meaning |
|---|---|---|---|
| `saml.sp.entity_id` | `SESAME_SAML_SP_ENTITY_ID` | Laravel: `/login/saml/metadata` | The SP's entity ID, as the IdP knows the app. Required in raw PHP. |
| `saml.sp.acs_url` | `SESAME_SAML_SP_ACS_URL` | Laravel: `/login/saml/acs` | Where the IdP posts its Response. https, or `http://localhost` for development. Required in raw PHP. |
| `saml.service_url` | `SESAME_SAML_SERVICE_URL` | Laravel: `/login` | The login URL, where a sign-in starts and finishes; every return-to must stay on its host. Required in raw PHP. |
| `saml.sp.cert_file`, `saml.sp.key_file` | `SESAME_SAML_SP_CERT_FILE`, `SESAME_SAML_SP_KEY_FILE` | none | The SP's PEM certificate and private key, as file paths; set both or neither. The key must match the certificate. Never inline, never logged: the key is held in a `SensitiveParameterValue`. |
| `saml.sign_authn_requests` | `SESAME_SAML_SIGN_AUTHN_REQUESTS` | `false` | Sign the AuthnRequest (RSA-SHA256). Needs the SP key pair. |
| `saml.want_messages_signed` | `SESAME_SAML_WANT_MESSAGES_SIGNED` | `false` | Also require a signed Response. The assertion signature is required either way. |
| `saml.idp.metadata_url` | `SESAME_SAML_IDP_METADATA_URL` | none | The IdP's metadata, https only, fetched without following redirects and cached. Set this or the file. |
| `saml.idp.metadata_file` | `SESAME_SAML_IDP_METADATA_FILE` | none | The IdP's metadata XML as a file, read at boot (a bad file stops the app). Set this or the URL. |
| `saml.idp.entity_id` | `SESAME_SAML_IDP_ENTITY_ID` | none | Which IdP in the metadata to trust; required when the document holds more than one. No default WSU IdP. |
| `saml.idp.cert_file` | `SESAME_SAML_IDP_CERT_FILE` | the metadata's signing certificates | Pin the IdP's signing certificate. |
| `saml.attributes` | `SESAME_SAML_ATTRIBUTE_ACCESSID`, `_EMAIL`, `_NAME`, `_AFFILIATIONS`, `_EPPN` | the table above | SESAME's name to SAML attribute names, comma-separated; an empty value keeps the default for that name. |
| `saml.username_attribute` | `SESAME_SAML_USERNAME_ATTRIBUTE` | `accessid` | Which mapped attribute becomes the username. |
| `saml.mfa_contexts` | `SESAME_SAML_MFA_CONTEXTS` | `https://refeds.org/profile/mfa` | AuthnContextClassRef values that count as MFA. |
| `saml.assume_mfa` | `SESAME_SAML_ASSUME_MFA` | `false` | Count every sign-in as MFA, when the IdP enforces it for this SP. |
| `saml.authn_context` | `SESAME_SAML_AUTHN_CONTEXT` | none | AuthnContextClassRef values to request (a RequestedAuthnContext); none is sent by default. |
| `saml.leeway_seconds` | `SESAME_SAML_LEEWAY_SECONDS` | `60` | Clock skew on the IssueInstant and the step-up checks, 0 to 180 seconds. php-saml's own conditions allow a fixed 180. |
| `saml.max_assertion_age_seconds` | `SESAME_SAML_MAX_ASSERTION_AGE_SECONDS` | `300` | The oldest an assertion may be, by its IssueInstant, 30 to 3600 seconds. |
| `saml.metadata_cache_ttl_seconds` | `SESAME_SAML_METADATA_CACHE_TTL_SECONDS` | `3600` | How long fetched metadata is kept, 60 to 86400 seconds. |
| `saml.request_ttl_seconds` | none | `600` | How long a started sign-in may take, 60 to 3600 seconds. |
| `saml.cache_store` | `SESAME_SAML_CACHE_STORE` | the default store | Laravel only. Must be shared by every web server of the app. |

## Errors

Every refusal is an `AuthenticationFailed` with a short reason in
parentheses, never a value from the XML:

| Reason | What happened |
|---|---|
| `saml.acs` | The ACS got no usable `SAMLResponse` (not a POST, empty, too large, not base64). |
| `saml.handle` | The `saml_handle` is unknown, used, or older than two minutes. |
| `saml.response` | The Response is not well-formed XML, or carries a DOCTYPE. |
| `saml.unsolicited` | The Response answers no request (IdP-initiated). |
| `saml.in_response_to` | It answers a request this browser did not send, or one already used or expired. |
| `saml.invalid: <code>` | php-saml refused it; the code is its `ValidationError` name, for example `no_signed_assertion`, `invalid_signature`, `wrong_audience`, `wrong_issuer`, `wrong_destination`, `assertion_expired` or `wrong_number_of_assertions`. |
| `saml.algorithm` | A signature or digest outside SHA-2. |
| `saml.issue_instant`, `saml.too_old` | The IssueInstant is in the future beyond the leeway, or older than the maximum age. |
| `saml.replay` | The assertion ID was accepted before. |
| `saml.step_up` | A step-up sign-in was not fresh. |
| `saml.missing_attribute` | The username attribute is missing. |
| `saml.metadata` | The IdP metadata could not be fetched or used. |

## Related

- [Which driver for my app?](which-driver.md)
- [The saml driver's library choice](../saml-library-choice.md)
- [The oidc driver](driver-oidc.md)
- [The gateway driver](driver-gateway.md)
- [The cas driver](driver-cas.md)
- [Raw PHP](raw-php.md)
- [Laravel](laravel.md)
- [Requiring MFA](requiring-mfa.md)
- [Step-up (authentication context)](step-up.md)
