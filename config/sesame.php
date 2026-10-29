<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | The protocol SESAME uses to verify identities: "cas" for the
    | university's CAS server, "gateway" for signed assertions from the
    | DRAWBRIDGE gateway, "oauth2" for JWT bearer tokens from an identity
    | provider (Microsoft Entra ID first), or "fake" for local development.
    |
    */

    'driver' => env('SESAME_DRIVER', 'cas'),

    /*
    |--------------------------------------------------------------------------
    | CAS
    |--------------------------------------------------------------------------
    |
    | The CAS server's base URL (no trailing slash, including the path the
    | CAS server lives under, for Academica /cas) and the URL the server
    | sends the user back to after verifying them. When service_url is not
    | set, the adapter uses the app's /login route.
    |
    | protocol is 2.0 by default: Academica is believed to redirect
    | cookieless /p3/serviceValidate calls to the login page. Set it to 3.0
    | when a CAS server releases attributes on /p3/serviceValidate.
    |
    */

    'cas' => [
        'base_url' => env('SESAME_CAS_BASE_URL'),
        'service_url' => env('SESAME_CAS_SERVICE_URL'),
        'protocol' => env('SESAME_CAS_PROTOCOL', '2.0'),
        // Leave off against Academica: it issues no ticket for renew=true.
        'renew' => env('SESAME_CAS_RENEW', false),
        // On only when the CAS server enforces MFA for every sign-in: a
        // successful validation then counts as an MFA sign-in.
        'assume_mfa' => env('SESAME_CAS_ASSUME_MFA', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fake driver
    |--------------------------------------------------------------------------
    |
    | The identity the fake driver returns, for local development.
    |
    */

    'fake' => [
        'username' => env('SESAME_FAKE_USERNAME'),
        'attributes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway driver
    |--------------------------------------------------------------------------
    |
    | The DRAWBRIDGE gateway signs each request with a JWT assertion and
    | sends it in one header. The driver verifies the signature against the
    | gateway's public key and checks the issuer, audience and time claims.
    | It never reads a plain identity header, and the request must come from
    | one of the trusted proxies.
    |
    | public_key is the gateway's public key: a PEM string (or a path to one)
    | for ES256 and RS256, or a base64-encoded raw key (or a path to one) for
    | EdDSA. trusted_proxies is a comma-separated list of CIDR ranges (or
    | single IPs) the gateway proxy sits behind.
    |
    | Every assertion is single-use. The driver requires a jti claim, refuses
    | assertions that live longer than max_lifetime (seconds), and remembers
    | each used jti in a cache so a captured assertion cannot be replayed.
    | replay_store names the cache store the jtis are kept in (null uses the
    | app's default store). allow_without_replay_guard skips the replay guard
    | entirely: local development only, never in production.
    |
    */

    'gateway' => [
        'header' => env('SESAME_GATEWAY_HEADER', 'X-Sesame-Assertion'),
        'algorithm' => env('SESAME_GATEWAY_ALGORITHM', 'ES256'),
        'public_key' => env('SESAME_GATEWAY_PUBLIC_KEY'),
        'issuer' => env('SESAME_GATEWAY_ISSUER'),
        'audience' => env('SESAME_GATEWAY_AUDIENCE'),
        'leeway_seconds' => env('SESAME_GATEWAY_LEEWAY_SECONDS', 30),
        'trusted_proxies' => array_filter(array_map('trim', explode(',', (string) env('SESAME_GATEWAY_TRUSTED_PROXIES', '')))),
        'max_lifetime' => env('SESAME_GATEWAY_MAX_LIFETIME', 300),
        'replay_store' => env('SESAME_GATEWAY_REPLAY_STORE'),
        'allow_without_replay_guard' => env('SESAME_GATEWAY_ALLOW_WITHOUT_REPLAY_GUARD', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth2 driver
    |--------------------------------------------------------------------------
    |
    | The oauth2 driver signs the user in from a JWT bearer token in the
    | Authorization header, verified against the issuer's key set (JWKS).
    | Only RS256 tokens are accepted.
    |
    | Microsoft Entra ID is the first planned issuer: point issuer and
    | jwks_url at the tenant's v2.0 endpoints (the discovery document's
    | "issuer" and "jwks_uri" values) and audience at the app's client id.
    | Any other RS256 issuer works with the same settings.
    |
    | required_scopes and required_roles are lists the token must cover:
    | scopes against the scp claim (a space-separated list), roles against
    | the roles claim (an array). An empty list checks nothing.
    | identity_claim names the claim the username comes from:
    | preferred_username (the default) or accessid.
    |
    | leeway_seconds is the clock skew allowed on the exp and nbf checks.
    | The fetched key set is kept for jwks_cache_ttl_seconds (default one
    | hour) and refetched at most once a minute when a token carries an
    | unknown key id. jwks_store names the cache store the key set is kept
    | in (null uses the app's default store).
    |
    */

    'oauth2' => [
        'issuer' => env('SESAME_OAUTH2_ISSUER'),
        'jwks_url' => env('SESAME_OAUTH2_JWKS_URL'),
        'audience' => env('SESAME_OAUTH2_AUDIENCE'),
        'leeway_seconds' => env('SESAME_OAUTH2_LEEWAY_SECONDS', 60),
        'required_scopes' => [],
        'required_roles' => [],
        'identity_claim' => env('SESAME_OAUTH2_IDENTITY_CLAIM', 'preferred_username'),
        'jwks_cache_ttl_seconds' => env('SESAME_OAUTH2_JWKS_CACHE_TTL_SECONDS', 3600),
        'jwks_store' => env('SESAME_OAUTH2_JWKS_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session timeouts
    |--------------------------------------------------------------------------
    |
    | How long a signed-in session may live, in minutes. The idle timeout is
    | the quiet time after which the session is dropped; the absolute
    | lifetime is the longest a session may live, no matter how active it
    | is.
    |
    */

    'session' => [
        'idle_minutes' => env('SESAME_SESSION_IDLE_MINUTES', 120),
        'absolute_minutes' => env('SESAME_SESSION_ABSOLUTE_MINUTES', 720),
    ],

    /*
    |--------------------------------------------------------------------------
    | MFA
    |--------------------------------------------------------------------------
    |
    | How long a verified MFA sign-in stays good, in seconds. The default is
    | eight hours. on_failure is what the sesame.mfa middleware does when the
    | check fails: "deny" answers 403, and "reauth" sends the user back to
    | the login route.
    |
    */

    'mfa' => [
        'max_age' => env('SESAME_MFA_MAX_AGE', 28800),
        'on_failure' => env('SESAME_MFA_ON_FAILURE', 'deny'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | When true, the adapter registers GET /login (sesame.login) and
    | POST /logout (sesame.logout). Set to false to keep the routes in the
    | app's own route file; the auth.sesame middleware still points at the
    | sesame.login route name.
    |
    */

    'routes' => env('SESAME_ROUTES', true),

    /*
    |--------------------------------------------------------------------------
    | User resolver
    |--------------------------------------------------------------------------
    |
    | The class that turns a verified identity into the app's user. It must
    | implement Waynestate\Sesame\UserResolver.
    |
    */

    'user_resolver' => env('SESAME_USER_RESOLVER'),

];
