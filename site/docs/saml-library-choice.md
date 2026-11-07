---
title: "The saml driver's library choice | SESAME docs"
description: "Which library the saml driver stands on, what else was weighed, why, and the version floor."
url: https://sesame.apps.wayne.edu/docs/saml-library-choice.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# The saml driver's library choice[#](https://sesame.apps.wayne.edu/docs/saml-library-choice.html#the-saml-driver-s-library-choice)

Ali Baba did not forge his own cave door, and SESAME does not write its own XML signature checks. This note records which library the `saml` driver stands on, what else was weighed, and why. Data checked on 2026-11-07 against Packagist (`repo.packagist.org/p2/<package>.json`) and the Packagist security advisory API.

## What the driver needs[#](https://sesame.apps.wayne.edu/docs/saml-library-choice.html#what-the-driver-needs)

- A service provider for the Web Browser SSO profile: an AuthnRequest over the HTTP-Redirect binding (optionally signed), the Response over HTTP-POST.
- Response validation in one place: the XML schema, XML signature verification that also checks what the signature covers (the classic wrapping attacks), Destination, Recipient, Audience, Issuer, InResponseTo, the time conditions and the bearer subject confirmation.
- IdP metadata parsing and SP metadata generation.
- A small dependency tree, a permissive license (SESAME is MIT), PHP 8.3 to 8.5, and an active maintainer with a record of fixing advisories.

## The candidates[#](https://sesame.apps.wayne.edu/docs/saml-library-choice.html#the-candidates)

| Package | Latest release | License | PHP | Dependencies | Advisories (all time) |
| --- | --- | --- | --- | --- | --- |
| onelogin/php-saml | 4.4.1, 2026-11-04 (4.3.2, 2026-05-07, on the 4.3 line) | MIT | >=8.0 (4.4), >=7.3 (4.3) | robrichards/xmlseclibs only (4.4 pulls xmlseclibs 4, which brings phpseclib 3; 4.3 uses xmlseclibs 3.1, with no further packages) | 3, the newest (CVE-2025-66475, through xmlseclibs) fixed in 4.3.1 |
| simplesamlphp/saml2 | v6.3.1, 2026-11-05; v7 in release candidates | LGPL-2.1-or-later | ^8.2 to ^8.5 by release; v7 needs ^8.5 | nyholm/psr7, psr/clock, psr/log, simplesamlphp/assert, xml-common, xml-security and xml-soap | 9, two of them in July 2026 (CVE-2026-49283, CVE-2026-49289); simplesamlphp/xml-security has 2 more |

## The choice: onelogin/php-saml[#](https://sesame.apps.wayne.edu/docs/saml-library-choice.html#the-choice-onelogin-php-saml)

- **One library, one reading.** php-saml's `Response::isValid()` does every check in the list above in strict mode, against the one parsed document it verified, and reads the assertion through the reference its signature points at. SESAME reads its extra values (IssueInstant, AuthnInstant, the class reference) through the same document and the same query (S47).
- **The smaller surface.** One dependency (xmlseclibs), against seven packages for simplesamlphp/saml2, most of them its own XML stack.
- **The license.** MIT, like SESAME. simplesamlphp/saml2 is LGPL, which is usable but is one more thing every app has to think about.
- **The record.** Three advisories in its life, each fixed promptly; the last, through xmlseclibs, was fixed in 4.3.1 the day it was published. simplesamlphp/saml2's newer releases also move their PHP floor around (v6.3.0 and v6.2.2 to v6.2.4 required PHP 8.5; v6.3.1 dropped it again), which would make SESAME's own PHP support follow theirs.

simplesamlphp/saml2 is the strongest alternative if SESAME ever needs the whole SAML protocol (artifact binding, SOAP, attribute queries); the driver needs only Web Browser SSO.

## The floor (SESAME-22)[#](https://sesame.apps.wayne.edu/docs/saml-library-choice.html#the-floor-sesame-22)

SESAME refuses php-saml below **4.3.2** and xmlseclibs below **3.1.5**:

- php-saml 4.3.1 fixed CVE-2025-66475 (signature verification through xmlseclibs), and 4.3.2 requires xmlseclibs 3.1.5 or later.
- xmlseclibs 3.1.4 fixed CVE-2025-66578 (a canonicalization error) and 3.1.5 fixed CVE-2026-32313 (a missing AES-GCM tag check on encrypted data).

The floor is held three ways, so it cannot drift:

1. `composer.json` lists both under `conflict`, so Composer refuses to install an older release beside SESAME.
2. `SamlDriver::assertLibraryInstalled()` checks the installed versions through Composer's runtime API when the driver is built, and refuses a dev branch, because nothing says it holds the fixes.
3. `tests/SamlConfigTest.php` fails when the installed versions are below the floor, or when `composer.json` and the driver's constants disagree.

`^4.3.2` takes either line. On 4.3 the tree is php-saml and xmlseclibs 3.1; on 4.4 (what the lock file has today) xmlseclibs 4 adds phpseclib 3 and two small paragonie packages. The driver's tests pass on both. `composer audit` in CI covers phpseclib's own advisories.

## How the dependency is kept optional[#](https://sesame.apps.wayne.edu/docs/saml-library-choice.html#how-the-dependency-is-kept-optional)

onelogin/php-saml is in `suggest` and `require-dev`, not `require`, the same way SESAME treats league/oauth2-client. Only the `saml` driver needs it. When it is missing, building the driver fails with:

```
The saml driver needs onelogin/php-saml. Install it with: composer require onelogin/php-saml
```

## What SESAME adds on top[#](https://sesame.apps.wayne.edu/docs/saml-library-choice.html#what-sesame-adds-on-top)

php-saml is configured strictly (`strict`, `wantAssertionsSigned`, `wantXMLValidation`, `requireDestination`, `destinationStrictlyMatches`, `rejectUnsolicitedResponsesWithInResponseTo`, and no RequestedAuthnContext unless the app asks for one). SESAME then adds what php-saml leaves to the app:

- **The two-hop ACS.** The IdP's cross-site POST carries no Lax session cookie, so the ACS parks the Response under a single-use handle and the check runs on the following GET, where the session is back (S35).
- **The pending request in the session**, so a Response is accepted only for a sign-in this browser started (login CSRF), and only once.
- **The replay guard**, keyed on the issuer and the assertion ID.
- **SHA-2 only.** php-saml still verifies SHA-1 signatures; SESAME refuses them.
- **Its own clock.** php-saml checks the conditions against the system clock with a fixed 180-second drift. SESAME also checks the IssueInstant against the injected PSR-20 clock, with its own leeway and maximum age.
- **The ACS URL, not the request.** php-saml compares Destination and Recipient with "the current URL" from `$_SERVER`. SESAME runs the check as if the request were at the configured ACS URL (`AcsUrl::during()`), which is also right behind a TLS-terminating proxy.
- **RelayState through ReturnTo**, checked on the way out and again on the way back.
