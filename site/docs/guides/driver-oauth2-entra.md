---
title: "oauth2 with Entra | SESAME docs"
description: "Sign in from a JWT bearer token verified against the issuer's key set, or from an opaque token through RFC 7662 introspection, Microsoft Entra ID first, APIs only."
url: https://sesame.apps.wayne.edu/docs/guides/driver-oauth2-entra.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# oauth2 with Entra[#](https://sesame.apps.wayne.edu/docs/guides/driver-oauth2-entra.html#oauth2-with-entra)

The goal: sign an API in from a JWT bearer token that Microsoft Entra ID (or any other RS256 issuer) has already issued, without a browser round trip. Open sesame, for machines: the lock checks the key against the issuer's own key set, and the door stays yours. Read this page to wire the driver up, then move on to [raw-php.md](https://sesame.apps.wayne.edu/docs/guides/raw-php.html) to build the facade and start signing in.

## How the driver verifies a token[#](https://sesame.apps.wayne.edu/docs/guides/driver-oauth2-entra.html#how-the-driver-verifies-a-token)

The oauth2 driver signs the user in from a JWT bearer token in the `Authorization` header, verified against the issuer's key set (a JWKS) fetched from `oauth2.jwks_url`. Only RS256 tokens are accepted: a token that claims `none` or an HMAC algorithm is refused before its signature is even looked at, and the key used to verify is the one the issuer published under the token's key id, never anything the token itself carries. After the signature, the driver checks the issuer, audience, expiry and not-before claims (with a leeway), then any required scopes and roles.

A token that is not a three-part JWT is opaque while introspection is off, and it is refused. When `oauth2.introspection_url` is set, the driver posts the token to that RFC 7662 endpoint with the configured client credentials as HTTP Basic auth, accepts only a `200` JSON answer whose `active` field is `true`, and applies the same issuer, audience, expiry and not-before checks to the claims the endpoint returns. Accepted answers are cached briefly under a hash of the token; the token itself is never cached or logged.

Microsoft Entra ID is the first supported issuer. Point `issuer` and `jwks_url` at the tenant's v2.0 endpoints (the `issuer` and `jwks_uri` values in the tenant's discovery document) and `audience` at the app's client id. Any other RS256 issuer works with the same settings.

## Setting up the driver[#](https://sesame.apps.wayne.edu/docs/guides/driver-oauth2-entra.html#setting-up-the-driver)

```php
<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;

$config = Config::fromArray([
    'driver' => 'oauth2',
    'environment' => 'production',
    'oauth2' => [
        'issuer' => 'https://login.microsoftonline.com/<tenant>/v2.0',
        'jwks_url' => 'https://login.microsoftonline.com/<tenant>/discovery/v2.0/keys',
        'audience' => '<client id>',
        'required_scopes' => ['openid'],
    ],
]);

// Any PSR-18 client works, for example Guzzle. Set a timeout: Guzzle has no
// total timeout by default, so a hung issuer would hold the request open
// forever.
$http = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);

// Any PSR-16 cache works (symfony/cache is shown); the Laravel adapter wires
// one up for you, so you only see this in raw PHP.
$driver = DriverFactory::make($config, $http, new Psr17Factory(), null, null, new FilesystemAdapter());
```

Then build the Sesame facade with the driver, as in the [Raw PHP](https://sesame.apps.wayne.edu/docs/guides/raw-php.html) page above.

## Config keys[#](https://sesame.apps.wayne.edu/docs/guides/driver-oauth2-entra.html#config-keys)

| Key | Required | Default | Meaning |
| --- | --- | --- | --- |
| `oauth2.issuer` | Yes | none | The `iss` claim the token must carry. For Entra, the `issuer` value in the tenant's discovery document. |
| `oauth2.jwks_url` | Yes | none | The URL of the issuer's JSON Web Key Set. For Entra, the `jwks_uri` value in the tenant's discovery document. |
| `oauth2.audience` | Yes | none | The `aud` claim the token must carry. For Entra, the app's client id. |
| `oauth2.leeway_seconds` | No | `60` | Clock skew allowed on the `exp` and `nbf` checks, in seconds. |
| `oauth2.required_scopes` | No | `[]` | Scopes the token's `scp` claim must cover, all of them. An empty list checks nothing. |
| `oauth2.required_roles` | No | `[]` | Roles the token's `roles` claim must include, all of them. An empty list checks nothing. |
| `oauth2.identity_claim` | No | `preferred_username` | The claim the username comes from: `preferred_username`, or `accessid` (the UPN's mail prefix, Entra's user claim). |
| `oauth2.jwks_cache_ttl_seconds` | No | `3600` | How long the fetched key set is kept before it is refetched, in seconds. |
| `oauth2.jwks_store` | No | the app's default cache store | The cache store the key set is kept in (Laravel only; raw PHP passes its own PSR-16 cache). |
| `oauth2.require_at_jwt` | No | `false` | When `true`, the token's `typ` header must be `at+jwt` or `application/at+jwt` (RFC 9068), and any other token is refused. **Off by default on purpose:** Microsoft Entra v2 access tokens are typed `JWT`, so turning it on refuses every Entra token. Turn it on only for an identity provider that issues RFC 9068 tokens. |
| `oauth2.introspection_url` | No | none | The RFC 7662 endpoint for opaque bearer tokens: `https://` only (`http://localhost` and `http://127.0.0.1` are allowed for tests). Leave it unset to keep the driver JWT-only. |
| `oauth2.introspection_client_id` | No | none | The client id sent as HTTP Basic auth to the introspection endpoint. Required when `oauth2.introspection_url` is set. |
| `oauth2.introspection_client_secret` | No | none | The client secret sent as HTTP Basic auth to the introspection endpoint. Required when `oauth2.introspection_url` is set. |
| `oauth2.introspection_cache_ttl_seconds` | No | `60` | How long an accepted introspection answer is kept, in seconds, capped by the token's `exp`. Zero disables the cache. |

Warning

Leave `oauth2.require_at_jwt` off for Entra. Microsoft Entra v2 access tokens are typed `JWT`, so requiring `at+jwt` refuses every Entra token. Turn it on only for an identity provider that issues RFC 9068 tokens.

Note

Leave `oauth2.introspection_url` unset for ordinary Entra apps: Entra v2 issues JWTs that the driver verifies locally against the JWKS. Set the introspection keys only when your issuer hands out opaque bearer tokens that need an RFC 7662 check.

## What the token must carry[#](https://sesame.apps.wayne.edu/docs/guides/driver-oauth2-entra.html#what-the-token-must-carry)

A JWT must carry an `exp` claim; a JWT without one is refused. The `email` and `name` claims become read-only identity attributes, and the optional `auth_time` and `amr` claims become the identity's MFA facts, for `requireMfa()`, exactly as in the gateway driver.

An accepted introspection answer must carry a usable username: the configured `oauth2.identity_claim` (`preferred_username` by default) or `sub`. Its `iss`, `aud`, `exp` and `nbf` claims are checked when present, with the same leeway as the JWT path.

## Key set caching and fail-closed fetches[#](https://sesame.apps.wayne.edu/docs/guides/driver-oauth2-entra.html#key-set-caching-and-fail-closed-fetches)

The key set is fetched once and kept for `oauth2.jwks_cache_ttl_seconds`. When a token carries a key id the cached set does not know, the provider refetches the set once, and at most once a minute, so a rotated key is picked up without hammering the issuer. The first fetch does not start that minute, so a key rotated just after it is still picked up at once. Everything about the fetch fails closed: an unreachable issuer, a non-2xx answer, or an empty or malformed key set all refuse the sign-in rather than let it through.

## Related[#](https://sesame.apps.wayne.edu/docs/guides/driver-oauth2-entra.html#related)

- [Which driver for my app?](https://sesame.apps.wayne.edu/docs/guides/which-driver.html)
- [The oidc driver (Entra sign-in)](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html)
- [Raw PHP](https://sesame.apps.wayne.edu/docs/guides/raw-php.html)
- [Laravel](https://sesame.apps.wayne.edu/docs/guides/laravel.html)
- [Requiring MFA](https://sesame.apps.wayne.edu/docs/guides/requiring-mfa.html)
