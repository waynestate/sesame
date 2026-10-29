# The oidc driver's library choice

Open sesame needs the right words, and we would rather borrow a maintained
dictionary than write our own. This note records which library the `oidc`
driver stands on, what else was weighed, and why. Data checked on
2026-10-29 against Packagist (`repo.packagist.org/p2/<package>.json`) and
the Packagist security advisory API.

## What the driver needs

- The authorization code flow with PKCE (S256), `state` and `nonce`.
- Discovery from the issuer's `/.well-known/openid-configuration`, cached.
- ID token validation: RSA signature against the issuer's JWKS with an
  algorithm allowlist, `iss`, `aud`, `azp`, `exp`, `nbf`, `iat`, `nonce`.
- Client authentication with a client secret (`client_secret_post` or
  `client_secret_basic`), the secret never logged.
- PSR-7/17/18 friendliness, a light dependency tree, PHP 8.3 to 8.5.

SESAME already ships a JWT verifier for the `oauth2` driver
(firebase/php-jwt plus `JwksProvider`: cached key set, one refetch per
minute for an unknown `kid`, fail closed). A second JWT stack would mean two
verifiers to keep correct.

## The candidates

| Package | Latest release | PHP | HTTP | PKCE | ID token validation | Weight | Advisories |
|---|---|---|---|---|---|---|---|
| facile-it/php-openid-client | 1.0.0, 2026-06-12 | ^8.2 | PSR-18/17 via php-http/discovery | Not documented in the README | Yes, through web-token/jwt-library and facile-it/php-jose-verifier | Heavy: web-token/jwt-library, php-jose-verifier (which requires symfony/http-client outright), spomky-labs/aes-key-wrap, pki-framework, brick/math, psr/http-server-middleware | None for the package; web-token/jwt-library had four in June 2026, one an algorithm confusion in `JWSVerifier` (fixed in 4.0.7 / 4.1.7) |
| jumbojett/openid-connect-php | v1.0.2, 2024-09-13 | >=7.0 | ext-curl directly, no PSR-18 | Yes | Its own, through phpseclib | Light | None listed |
| league/oauth2-client | 2.9.1, 2026-09-16 | ^7.1 or >=8.0 <8.6 | Guzzle (required) | Yes, S256 built in since 2.7 | No: OAuth 2.0 only | Light: Guzzle and guzzlehttp/psr7 | None listed |
| thenetworg/oauth2-azure | v2.2.6, 2026-06-22 | ^7.1 or ^8.0 | Guzzle, through league/oauth2-client | Through league | Yes, Azure-specific, with its own key fetching on firebase/php-jwt | league plus Azure-specific code | None listed |

## The choice: league/oauth2-client, plus SESAME's own ID token validation

- **league/oauth2-client** does the OAuth 2.0 mechanics: the authorization
  URL, the PKCE verifier and its S256 challenge, the token request with the
  client's credentials, and the token response parsing. It is the most used
  OAuth 2.0 client in PHP, released last month, supports PHP up to 8.5, has
  no published advisories, and its only weight is Guzzle, which the Laravel
  adapter already uses.
- **SESAME's firebase/php-jwt and `JwksProvider`** validate the ID token,
  the same code the `oauth2` driver uses for bearer tokens. The OpenID
  Connect checks league does not do (discovery, `nonce`, `azp`, `iat`,
  the algorithm allowlist) are a few dozen lines in
  `src/Drivers/Oidc/IdTokenValidator.php` and `Discovery.php`, written
  against OpenID Connect Core 3.1.3.7 and Discovery 4.3.

Why not the others:

- **facile-it/php-openid-client** is the most complete OIDC client, but it
  brings a second JOSE stack (web-token) beside the one SESAME already
  trusts, that stack had an algorithm confusion advisory this year, and its
  verifier requires symfony/http-client even when the app uses another
  PSR-18 client. It is the strongest alternative if SESAME ever needs
  `private_key_jwt`, encrypted ID tokens or a full relying-party feature set.
- **jumbojett/openid-connect-php** talks to curl directly (no PSR-18, so the
  tests cannot swap the transport), allows PHP 7.0, and has not had a
  release since September 2024.
- **thenetworg/oauth2-azure** ties the driver to Azure-specific code and
  fetches its own keys, duplicating `JwksProvider`. SESAME's driver works
  with any OpenID provider; Entra is configuration.

## How the dependency is kept optional

league/oauth2-client is in `suggest` and `require-dev`, not `require`, the
same way SESAME treats a PSR-18 client. Only the `oidc` driver needs it.
When it is missing, building the driver fails with:

```
The oidc driver needs league/oauth2-client. Install it with: composer require league/oauth2-client
```

## Two small layers on top of league

- `LeagueProvider` drops league's default `approval_prompt=auto`, a
  pre-standard parameter OpenID Connect does not define, so the
  authorization request carries only what the driver chose.
- `BasicAuthOptionProvider` form-encodes the client id and secret before
  base64 for `client_secret_basic`, as RFC 6749 section 2.3.1 requires;
  league's own basic provider skips that step.

league's token request goes through Guzzle. When the app's PSR-18 client is
Guzzle (the Laravel adapter's default), the driver uses it for the token
request too; otherwise it builds a Guzzle client that does not follow
redirects and times out after ten seconds.
