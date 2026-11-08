---
title: "Configuration | SESAME docs"
description: "Every SESAME config key, its environment variable, its default and its meaning."
url: https://sesame.apps.wayne.edu/docs/reference/configuration.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Configuration[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#configuration)

Every setting SESAME reads, from the plain array `Config::fromArray()` takes to the environment variables the Laravel config resolves. The magic word here is a config array, not an incantation. Start here when you are wiring a driver; the driver guides show the full examples.

## Reading config[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#reading-config)

SESAME reads its config from a plain array through one method on the `final readonly class Config`:

```php
$config = Config::fromArray($configArray);
```

- `driver` and `environment` are always required.
- Each driver adds its own block: `fake`, `cas`, `gateway`, `oauth2`, `oidc` or `saml`. The `oidc` block is read into `OidcOptions`; see the oidc keys below. The `saml` block is read into `SamlOptions`; its keys are in [the saml driver guide](https://sesame.apps.wayne.edu/docs/guides/driver-saml.html#config-keys).
- A truthy `slo` or `single_logout` key is rejected at boot: the identity provider does not support single logout, so asking for it is a mistake to fail on at boot, not at the first logout.
- A missing or empty required value throws `InvalidConfig`. Four keys are resolved from the environment or waynestate/playbook instead of the array, and their error message names the environment setting the developer actually sets:

| Config key | Environment setting |
| --- | --- |
| `cas.base_url` | `SESAME_CAS_BASE_URL` |
| `gateway.issuer` | `SESAME_GATEWAY_ISSUER` |
| `oauth2.issuer` | `SESAME_OAUTH2_ISSUER` |
| `oauth2.jwks_url` | `SESAME_OAUTH2_JWKS_URL` |

The message reads: `Missing required setting <SETTING>. Set it in the environment, or install waynestate/playbook (see the README).`

## Validation[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#validation)

A present key with the wrong type is also an `InvalidConfig` error at boot, before any request is served:

- Booleans must be booleans: `cas.renew`, `cas.assume_mfa`, `gateway.allow_without_replay_guard`, `oauth2.require_at_jwt`.
- Integers: `gateway.leeway_seconds` non-negative, `gateway.max_lifetime` positive, `oauth2.leeway_seconds` non-negative and at most `300`, `oauth2.jwks_cache_ttl_seconds` positive and at most `86400`, `session.idle_minutes` and `session.absolute_minutes` positive.
- Enumerations: `cas.protocol` only `3.0` or `2.0`; `gateway.algorithm` only `ES256`, `RS256` or `EdDSA`; `oauth2.identity_claim` only `preferred_username` or `accessid`.
- `gateway.public_key`, `gateway.public_keys` and `gateway.jwks_file` are mutually exclusive: set exactly one. An empty `public_keys` array or `jwks_file` counts as unset, and `public_key` is then required. A `jwks_file` is read at boot, and a missing or bad key set file is an `InvalidConfig` error (the rules are in the [gateway driver guide](https://sesame.apps.wayne.edu/docs/guides/driver-gateway.html#the-key-set-file)).
- `gateway.trusted_proxies` must be a non-empty array of non-empty strings (CIDR ranges or single IPs).
- `fake.attributes`, `oauth2.required_scopes` and `oauth2.required_roles` must be arrays of non-empty strings.
- The `oidc` block must be an array; `OidcOptions::fromArray()` validates it and names the key in its errors.
- The `saml` block must be an array; `SamlOptions::fromArray()` validates it at boot (URLs, exactly one IdP metadata source, the certificate and key files, which must match) and names the key in its errors.

## Environment variables[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#environment-variables)

Every environment variable SESAME reads, what it does, and its default. The table is generated from the source by Squire's docs builder; do not edit it by hand.

| Variable | Meaning | Default | Read in |
| --- | --- | --- | --- |
| SESAME_CAS_ASSUME_MFA | Count a successful validation as an MFA sign-in; on only when the CAS server enforces MFA for every sign-in | `false` | config/sesame.php |
| SESAME_CAS_BASE_URL | The CAS server's base URL (no trailing slash, including the path the server lives under; Academica is `/cas`) | none (Playbook supplies the WSU value) | config/sesame.php |
| SESAME_CAS_PROTOCOL | The CAS protocol version; set `3.0` when the server releases attributes on `/p3/serviceValidate` | `2.0` | config/sesame.php |
| SESAME_CAS_RENEW | Send `renew=true`; leave off against Academica, which issues no ticket for it | `false` | config/sesame.php |
| SESAME_CAS_REQUIRE_PENDING_LOGIN | Require that this browser started the sign-in before a CAS ticket is accepted (blocks login CSRF); turn off only for CAS-initiated entry | `true` | config/sesame.php |
| SESAME_CAS_SERVICE_URL | The URL the CAS server sends the user back to after verifying them | none (the app's `/login` route) | config/sesame.php |
| SESAME_DRIVER | The protocol SESAME uses to verify identities: `cas`, `gateway`, `oauth2`, `oidc`, `saml` (opt-in) or `fake` | `cas` | config/sesame.php |
| SESAME_FAKE_USERNAME | The identity the `fake` driver returns, for local development | none | config/sesame.php |
| SESAME_GATEWAY_ALGORITHM | The assertion's signature algorithm (`ES256`, `RS256` or `EdDSA`) | `ES256` | config/sesame.php |
| SESAME_GATEWAY_ALLOW_WITHOUT_REPLAY_GUARD | Skip the replay guard entirely; local development only, never in production | `false` | config/sesame.php |
| SESAME_GATEWAY_AUDIENCE | The audience claim the gateway assertion must carry | none | config/sesame.php |
| SESAME_GATEWAY_HEADER | The header the Drawbridge gateway sends its signed JWT assertion in | `X-Sesame-Assertion` | config/sesame.php |
| SESAME_GATEWAY_ISSUER | The issuer claim the gateway assertion must carry | none (Playbook supplies the WSU value) | config/sesame.php |
| SESAME_GATEWAY_JWKS_FILE | A path to a JWKS file holding the gateway's public keys by `kid` (Drawbridge's `keys:jwks` writes it: the current key and the next), read at boot | none (set this, `public_key` or `public_keys`, only one) | config/sesame.php |
| SESAME_GATEWAY_LEEWAY_SECONDS | Clock skew allowed on the assertion's time claims, in seconds | `30` | config/sesame.php |
| SESAME_GATEWAY_MAX_LIFETIME | The longest an assertion may live, in seconds; longer ones are refused | `300` | config/sesame.php |
| SESAME_GATEWAY_PUBLIC_KEY | The gateway's public key: a PEM string or a path to one for ES256 and RS256, a base64-encoded raw key or a path to one for EdDSA | none (set this, `public_keys` or `jwks_file`, only one) | config/sesame.php |
| SESAME_GATEWAY_REPLAY_STORE | The cache store used jtis are kept in, so a captured assertion cannot be replayed | none (the app's default cache store) | config/sesame.php |
| SESAME_GATEWAY_TRUSTED_PROXIES | Comma-separated CIDR ranges (or single IPs) the gateway proxy sits behind; the request must come from one of them | none | config/sesame.php |
| SESAME_LOG | Log sign-in and sign-out events through the app's PSR-3 logger | `true` | config/sesame.php |
| SESAME_MFA_MAX_AGE | How long a verified MFA sign-in stays good, in seconds | `28800` (eight hours) | config/sesame.php |
| SESAME_MFA_ON_FAILURE | What the `sesame.mfa` middleware does when the check fails: `deny` answers 403, `reauth` sends the user back to the login route | `deny` | config/sesame.php |
| SESAME_OAUTH2_AUDIENCE | The audience claim the token must carry (for Entra, the app's client id) | none | config/sesame.php |
| SESAME_OAUTH2_IDENTITY_CLAIM | The claim the username comes from: `preferred_username` or `accessid` | `preferred_username` | config/sesame.php |
| SESAME_OAUTH2_ISSUER | The issuer of the JWT bearer tokens (Microsoft Entra ID first: the tenant's v2.0 endpoints) | none (Playbook supplies the WSU value) | config/sesame.php |
| SESAME_OAUTH2_JWKS_CACHE_TTL_SECONDS | How long the fetched key set is kept, in seconds; refetched at most once a minute for an unknown key id | `3600` | config/sesame.php |
| SESAME_OAUTH2_JWKS_STORE | The cache store the key set is kept in | none (the app's default cache store) | config/sesame.php |
| SESAME_OAUTH2_JWKS_URL | The issuer's key set (JWKS) the tokens are verified against | none (Playbook supplies the WSU value) | config/sesame.php |
| SESAME_OAUTH2_LEEWAY_SECONDS | Clock skew allowed on the `exp` and `nbf` checks, in seconds | `60` | config/sesame.php |
| SESAME_OAUTH2_REQUIRE_AT_JWT | Check the access token's `typ` header is `at+jwt` or `application/at+jwt`; off because Microsoft Entra v2 tokens are typed `JWT` | `false` | config/sesame.php |
| SESAME_OIDC_ASSUME_MFA | Count an OIDC sign-in as an MFA sign-in | `false` | config/sesame.php |
| SESAME_OIDC_AUTHORIZATION_ENDPOINT | Override the authorization endpoint from the discovery document | none (from discovery) | config/sesame.php |
| SESAME_OIDC_CACHE_STORE | The cache store the discovery document and key set are kept in | none (the app's default cache store) | config/sesame.php |
| SESAME_OIDC_CACHE_TTL_SECONDS | How long the discovery document and key set are cached, in seconds | `3600` (maximum 3600) | config/sesame.php |
| SESAME_OIDC_CLAIMS | The ID token claims passed through to the user resolver as identity attributes, comma-separated | `wsu_roles,accessid` | config/sesame.php |
| SESAME_OIDC_CLIENT_AUTH | Client authentication on the token call: `client_secret_post` or `client_secret_basic` | `client_secret_post` | config/sesame.php |
| SESAME_OIDC_CLIENT_ID | The provider app registration's client id | none | config/sesame.php |
| SESAME_OIDC_CLIENT_SECRET | The provider app registration's client secret; read from the environment only, never logged | none | config/sesame.php |
| SESAME_OIDC_END_SESSION_ENDPOINT | Override the end-session endpoint from the discovery document | none (from discovery) | config/sesame.php |
| SESAME_OIDC_ISSUER | The OpenID Connect issuer for browser sign-in (Microsoft Entra ID first) | none (falls back to `SESAME_OAUTH2_ISSUER`; Playbook supplies the WSU value) | config/sesame.php |
| SESAME_OIDC_JWKS_URI | Override the JWKS URI from the discovery document | none (from discovery) | config/sesame.php |
| SESAME_OIDC_LEEWAY_SECONDS | Clock skew allowed on the ID token's time claims, in seconds | `60` | config/sesame.php |
| SESAME_OIDC_POST_LOGOUT_REDIRECT_URI | When set, logout sends the user through the provider's end-session endpoint | none | config/sesame.php |
| SESAME_OIDC_REDIRECT_URI | The redirect URI; must be registered on the app registration as a Web redirect URI | none (the app's `/login/callback` route) | config/sesame.php |
| SESAME_OIDC_ROLES_CLAIM | The ID token claim the roles come from (a JSON list) | `wsu_roles` | config/sesame.php |
| SESAME_OIDC_SCOPES | The scopes requested on the authorization call, space-separated | `openid profile email` | config/sesame.php |
| SESAME_OIDC_STEP_UP_ACR_VALUES | The `acr_values` parameter a step-up login request adds | none | config/sesame.php |
| SESAME_OIDC_STEP_UP_CLAIMS | The `claims` parameter a step-up login request adds | none | config/sesame.php |
| SESAME_OIDC_STEP_UP_MAX_AGE | The `max_age` parameter a step-up login request adds | none | config/sesame.php |
| SESAME_OIDC_STEP_UP_PROMPT | The `prompt` parameter a step-up login request adds | `login` | config/sesame.php |
| SESAME_OIDC_TOKEN_ENDPOINT | Override the token endpoint from the discovery document | none (from discovery) | config/sesame.php |
| SESAME_OIDC_USERNAME_CLAIM | The ID token claim the username comes from | `accessid` | config/sesame.php |
| SESAME_OIDC_USERNAME_FALLBACK | Use the `preferred_username` prefix when the username claim is missing; off because preferred_username is user-changeable | `false` | config/sesame.php |
| SESAME_ROUTES | Register GET `/login`, POST `/logout`, (for oidc) GET `/login/callback` and (for saml) POST `/login/saml/acs` and GET `/login/saml/metadata`; false keeps the routes in the app's own route file | `true` | config/sesame.php |
| SESAME_SAML_ASSUME_MFA | Count every SAML sign-in as MFA; on only when the IdP enforces MFA for this SP | `false` | config/sesame.php |
| SESAME_SAML_ATTRIBUTE_ACCESSID | The SAML attribute names for the AccessID, comma-separated; the first present wins | `urn:oid:0.9.2342.19200300.100.1.1,uid` | config/sesame.php |
| SESAME_SAML_ATTRIBUTE_AFFILIATIONS | The SAML attribute names for the affiliations, comma-separated | `urn:oid:1.3.6.1.4.1.5923.1.1.1.1,eduPersonAffiliation` | config/sesame.php |
| SESAME_SAML_ATTRIBUTE_EMAIL | The SAML attribute names for the email address, comma-separated | `urn:oid:0.9.2342.19200300.100.1.3,mail` | config/sesame.php |
| SESAME_SAML_ATTRIBUTE_EPPN | The SAML attribute names for the eduPersonPrincipalName, comma-separated | `urn:oid:1.3.6.1.4.1.5923.1.1.1.6,eduPersonPrincipalName` | config/sesame.php |
| SESAME_SAML_ATTRIBUTE_NAME | The SAML attribute names for the display name, comma-separated | `urn:oid:2.16.840.1.113730.3.1.241,displayName` | config/sesame.php |
| SESAME_SAML_AUTHN_CONTEXT | The AuthnContextClassRef values to request, comma-separated | none (no RequestedAuthnContext) | config/sesame.php |
| SESAME_SAML_CACHE_STORE | The cache store that parks the IdP's response, keeps the metadata and remembers used assertion IDs; shared by every web server | none (the app's default cache store) | config/sesame.php |
| SESAME_SAML_IDP_CERT_FILE | A path to the IdP's signing certificate, pinned instead of the metadata's | none (the metadata's signing certificates) | config/sesame.php |
| SESAME_SAML_IDP_ENTITY_ID | Which IdP in the metadata to trust; required when the metadata holds more than one | none | config/sesame.php |
| SESAME_SAML_IDP_METADATA_FILE | A path to the IdP's metadata XML, read at boot; set this or the metadata URL | none (no default IdP) | config/sesame.php |
| SESAME_SAML_IDP_METADATA_URL | The IdP's metadata URL (https), fetched and cached; set this or the metadata file | none (no default IdP) | config/sesame.php |
| SESAME_SAML_LEEWAY_SECONDS | Clock skew allowed on the assertion's IssueInstant, in seconds | `60` (maximum 180) | config/sesame.php |
| SESAME_SAML_MAX_ASSERTION_AGE_SECONDS | The oldest an assertion may be, by its IssueInstant, in seconds | `300` | config/sesame.php |
| SESAME_SAML_METADATA_CACHE_TTL_SECONDS | How long fetched IdP metadata is kept, in seconds | `3600` | config/sesame.php |
| SESAME_SAML_MFA_CONTEXTS | The AuthnContextClassRef values that count as MFA, comma-separated | `https://refeds.org/profile/mfa` | config/sesame.php |
| SESAME_SAML_SERVICE_URL | The login URL, where a SAML sign-in starts and finishes; every return-to must stay on its host | none (Laravel: the app's `/login` route) | config/sesame.php |
| SESAME_SAML_SIGN_AUTHN_REQUESTS | Sign the AuthnRequest (needs the SP key pair) | `false` | config/sesame.php |
| SESAME_SAML_SP_ACS_URL | The assertion consumer service URL the IdP posts its response to | none (Laravel: the app's `/login/saml/acs` route) | config/sesame.php |
| SESAME_SAML_SP_CERT_FILE | A path to the SP's PEM certificate (published in the SP metadata); set with the key file | none | config/sesame.php |
| SESAME_SAML_SP_ENTITY_ID | The SP's entity ID, as the IdP knows this app (a URI) | none (Laravel: the app's `/login/saml/metadata` URL) | config/sesame.php |
| SESAME_SAML_SP_KEY_FILE | A path to the SP's PEM private key, for signed AuthnRequests; never inline, never logged | none | config/sesame.php |
| SESAME_SAML_USERNAME_ATTRIBUTE | Which mapped attribute becomes the username | `accessid` | config/sesame.php |
| SESAME_SAML_WANT_MESSAGES_SIGNED | Also require the whole Response to be signed (the assertion signature is always required) | `false` | config/sesame.php |
| SESAME_SESSION_ABSOLUTE_MINUTES | The absolute lifetime in minutes: the longest a session may live, no matter how active it is | `720` | config/sesame.php |
| SESAME_SESSION_IDLE_MINUTES | The idle timeout in minutes: the quiet time after which the session is dropped | `120` | config/sesame.php |
| SESAME_USER_RESOLVER | The class that turns a verified identity into the app's user; it must implement `Waynestate\Sesame\UserResolver` | none | config/sesame.php |

## WSU values (Playbook)[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#wsu-values-playbook)

Four settings carry Wayne State's shared values: where the CAS server lives, and who signs the gateway and oauth2 assertions.

| Setting | Config key | Meaning |
| --- | --- | --- |
| `SESAME_CAS_BASE_URL` | `cas.base_url` | The CAS server, an absolute https:// URL. |
| `SESAME_GATEWAY_ISSUER` | `gateway.issuer` | The `iss` claim the gateway assertion must carry. |
| `SESAME_OAUTH2_ISSUER` | `oauth2.issuer` | The `iss` claim the bearer token must carry. |
| `SESAME_OAUTH2_JWKS_URL` | `oauth2.jwks_url` | The URL of the issuer's key set. |

Set them in the environment, as with every other setting. When the environment is empty, the private waynestate/playbook package supplies the university's values, so a fresh app boots against the real services without a `.env` full of addresses. The environment always wins: a set variable beats the playbook, and an empty one counts as unset. The playbook is never required: it sits in `suggest`, not `require`, and SESAME runs fine without it, reading these four from the environment alone. See the [WSU values guide](https://sesame.apps.wayne.edu/docs/guides/wsu-values.html) for how to get it.

Raw-PHP apps that build their config by hand can resolve a value the same way the Laravel config does:

```php
use Waynestate\Sesame\Support\Setting;

$baseUrl = Setting::get('SESAME_CAS_BASE_URL'); // env, then playbook, else null
```

## Example .env block[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#example-env-block)

The Laravel oidc block, straight from the README:

```
SESAME_DRIVER=oidc
SESAME_OIDC_CLIENT_ID=<application (client) id>
SESAME_OIDC_CLIENT_SECRET=<client secret value>
# SESAME_OIDC_ISSUER falls back to SESAME_OAUTH2_ISSUER (and the playbook).
# SESAME_OIDC_REDIRECT_URI defaults to the app's /login/callback route.
SESAME_OIDC_POST_LOGOUT_REDIRECT_URI=https://app.example.test/
```

Danger

`SESAME_OIDC_CLIENT_SECRET` is environment only: never the playbook, never logged. It is held in a `SensitiveParameterValue`, so `var_export`, `json_encode` and dumps show nothing and `serialize` refuses. Keep PHP's production default `zend.exception_ignore_args = On` as well.

## Config keys[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#config-keys)

### Common[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#common)

| Key | Required | Default | Meaning |
| --- | --- | --- | --- |
| `driver` | Yes | none | `fake`, `cas`, `gateway`, `oauth2` or `oidc`. |
| `environment` | Yes | none | Always required; the `fake` driver refuses to boot in production. |

### fake[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#fake)

| Key | Required | Default | Meaning |
| --- | --- | --- | --- |
| `fake.username` | Yes | none | The identity the `fake` driver returns, for local development. |
| `fake.attributes` | No | `[]` | Attribute name to its values, on the returned identity. |

### cas[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#cas)

| Key | Required | Default | Meaning |
| --- | --- | --- | --- |
| `cas.base_url` | Yes | none | The CAS server, an absolute https:// URL, including the path the CAS server lives under (for Academica, `/cas`). |
| `cas.service_url` | Yes | none | Where the server sends the user back to. |
| `cas.protocol` | No | `2.0` | `3.0` or `2.0`. |
| `cas.renew` | No | `false` | Send `renew` on login and validation, so the CAS server requires a fresh, direct login. |
| `cas.assume_mfa` | No | `false` | A successful validation counts as an MFA sign-in: the identity's `amr` includes `mfa`. On only when the CAS server enforces MFA for every sign-in. |

The service URL is the configured value only: the driver never reads the request's Host or X-Forwarded-\* headers to build it. Protocol behavior, `renew` against Academica, and the MFA facts the driver reads from `authenticationDate` are covered by the [cas driver guide](https://sesame.apps.wayne.edu/docs/guides/driver-cas.html).

### gateway[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#gateway)

| Key | Required | Default | Meaning |
| --- | --- | --- | --- |
| `gateway.header` | No | `X-Sesame-Assertion` | The one header that holds the signed assertion. |
| `gateway.algorithm` | No | `ES256` | `ES256`, `RS256` or `EdDSA`. Pinned, so a token that claims another algorithm is refused. |
| `gateway.public_key` | Yes, unless `public_keys` or `jwks_file` is set | none | The gateway's public key: a PEM string (or a path to one) for `ES256` and `RS256`, or a base64-encoded raw key (or a path to one) for `EdDSA`. |
| `gateway.public_keys` | No | none | A map of `kid` to public key, for rotating the gateway's signing key. |
| `gateway.jwks_file` | No | none | A path to a JWKS file of the gateway's public keys by `kid` (Drawbridge's `keys:jwks` writes it), read at boot. Set one of `public_key`, `public_keys` and `jwks_file`. |
| `gateway.issuer` | Yes | none | The `iss` claim the assertion must carry. |
| `gateway.audience` | Yes | none | The `aud` claim the assertion must carry. |
| `gateway.leeway_seconds` | No | `30` | Clock skew allowed on the `iat`, `nbf` and `exp` checks, in seconds. |
| `gateway.trusted_proxies` | Yes | none | A non-empty array of CIDR ranges (or single IPs) the gateway proxy sits behind. |
| `gateway.max_lifetime` | No | `300` | The longest an assertion may live (`exp` minus `iat`), in seconds. Longer assertions are refused, so a stolen long-lived token is useless. |
| `gateway.replay_store` | No | the app's default cache store | The cache store the used `jti`s are kept in (Laravel only; raw PHP passes its own PSR-16 cache to the guard). |
| `gateway.allow_without_replay_guard` | No | `false` | Skip the replay guard entirely. Local development only: a production app must keep replay protection on. |

The assertion must carry `sub`, `iat`, `exp` and `jti`; the `sub` claim becomes the username. Replay protection and key rotation are covered by the [gateway driver guide](https://sesame.apps.wayne.edu/docs/guides/driver-gateway.html).

### oauth2[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#oauth2)

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

The token must carry an `exp` claim; a token without one is refused. The Entra registration and the key set fetch are covered by the [oauth2 with Entra guide](https://sesame.apps.wayne.edu/docs/guides/driver-oauth2-entra.html).

### oidc[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#oidc)

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `oidc.issuer` | `SESAME_OIDC_ISSUER`, then `SESAME_OAUTH2_ISSUER` | none | The issuer, exactly as the discovery document's `issuer`. Required. |
| `oidc.client_id` | `SESAME_OIDC_CLIENT_ID` | none | The app registration's client id; the ID token's `aud`. Required. |
| `oidc.client_secret` | `SESAME_OIDC_CLIENT_SECRET` | none | Environment only, never the playbook, never logged. Held in a `SensitiveParameterValue`, so `var_export`, `json_encode` and dumps show nothing and `serialize` refuses. Keep PHP's production default `zend.exception_ignore_args = On` as well. Required. |
| `oidc.redirect_uri` | `SESAME_OIDC_REDIRECT_URI` | Laravel: `/login/callback` | Where Entra sends the browser back. https, or `http://localhost` for development. Required in raw PHP. |
| `oidc.scopes` | `SESAME_OIDC_SCOPES` | `openid profile email` | `openid` is always added. |
| `oidc.client_auth` | `SESAME_OIDC_CLIENT_AUTH` | `client_secret_post` | Or `client_secret_basic`. |
| `oidc.username_claim` | `SESAME_OIDC_USERNAME_CLAIM` | `accessid` | The claim the username comes from. |
| `oidc.username_fallback` | `SESAME_OIDC_USERNAME_FALLBACK` | `false` | Opt in to the `preferred_username` prefix when the claim is missing. `preferred_username` is user-changeable; see the warning above. |
| `oidc.roles_claim` | `SESAME_OIDC_ROLES_CLAIM` | `wsu_roles` | The claim that becomes the `roles` attribute; namespaced forms are found. |
| `oidc.claims` | `SESAME_OIDC_CLAIMS` | `wsu_roles,accessid` | Claims passed to the resolver as attributes under their own names. |
| `oidc.assume_mfa` | `SESAME_OIDC_ASSUME_MFA` | `false` | Count a sign-in as MFA when Conditional Access enforces it for the app. |
| `oidc.algorithms` | none | `['RS256']` | The ID token algorithms allowed: `RS256`, `RS384`, `RS512` only. |
| `oidc.leeway_seconds` | `SESAME_OIDC_LEEWAY_SECONDS` | `60` | Clock skew on `exp`, `nbf` and `iat`, at most 300. |
| `oidc.post_logout_redirect_uri` | `SESAME_OIDC_POST_LOGOUT_REDIRECT_URI` | none | Set it to log out of Entra too. |
| `oidc.authorization_endpoint`, `oidc.token_endpoint`, `oidc.jwks_uri`, `oidc.end_session_endpoint` | `SESAME_OIDC_AUTHORIZATION_ENDPOINT` and so on | discovered | Overrides; with the first three set, discovery is skipped. |
| `oidc.cache_ttl_seconds` | `SESAME_OIDC_CACHE_TTL_SECONDS` | `3600` | How long the discovery document and key set are kept, 1 to 3600 seconds (at most an hour, so a moved endpoint or revoked key does not live on). |
| `oidc.cache_store` | `SESAME_OIDC_CACHE_STORE` | the default store | Laravel only. |
| `oidc.state_ttl_seconds` | none | `600` | How long a started sign-in may take, 60 to 3600 seconds. |
| `oidc.step_up.prompt` | `SESAME_OIDC_STEP_UP_PROMPT` | `login` | Sent on a step-up only; `login` or empty. |
| `oidc.step_up.max_age` | `SESAME_OIDC_STEP_UP_MAX_AGE` | none (300 enforced) | Seconds; sent on a step-up only, and enforced on `auth_time`. Unset, it is not sent, but a step-up `auth_time` older than 300 seconds still fails. |
| `oidc.step_up.acr_values` | `SESAME_OIDC_STEP_UP_ACR_VALUES` | none | Sent on a step-up only. |
| `oidc.step_up.claims` | `SESAME_OIDC_STEP_UP_CLAIMS` | none | A JSON object (for example an `acrs` request for an Entra authentication context), sent on a step-up only. |

Browser sign-in flow, the ID token rules and the Entra app registration are covered by the [oidc driver guide](https://sesame.apps.wayne.edu/docs/guides/driver-oidc.html).

### Session timeouts[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#session-timeouts)

Open sesame opens the door for a live session; a stale one finds it locked. Every signed-in session has two clocks. The idle timeout is the quiet time after which the session is dropped, and the absolute lifetime is the longest a session may live, no matter how active it is. Both are in minutes, and both are checked on every `user()` call, so a stale session is signed out on the spot the first time anything asks who is signed in.

| Key | Default | Meaning |
| --- | --- | --- |
| `session.idle_minutes` | `120` | Drop the session after this much quiet time. |
| `session.absolute_minutes` | `720` | End the session this long after sign-in, even if it is still active. |

In Laravel the keys are `SESAME_SESSION_IDLE_MINUTES` and `SESAME_SESSION_ABSOLUTE_MINUTES`.

## Related[#](https://sesame.apps.wayne.edu/docs/reference/configuration.html#related)

- [Drivers](https://sesame.apps.wayne.edu/docs/reference/drivers.html)
- [API](https://sesame.apps.wayne.edu/docs/reference/api.html)
- [Proof](https://sesame.apps.wayne.edu/docs/reference/proof.html)
- [Which driver for my app?](https://sesame.apps.wayne.edu/docs/guides/which-driver.html)
- [WSU values (Playbook)](https://sesame.apps.wayne.edu/docs/guides/wsu-values.html)
- [Session timeouts](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html)
