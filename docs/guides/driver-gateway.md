---
title: The gateway driver
description: The recommended path on Citadel: verify the signed JWT assertion the Drawbridge gateway sends in one header, never a bare identity header.
---

# The gateway driver

This page sets up the `gateway` driver, the recommended path on Citadel: it verifies the signed JWT assertion the Drawbridge gateway sends in one header, never a bare identity header. Configure the driver below, then read the key rotation steps before you rotate the gateway's signing key.

> [!NOTE]
> Drawbridge is not live yet, so there is no production gateway to point at today. Until it is, CAS (the `cas` driver) is today's option for a Citadel app, and it needs no per-app Entra registration. Once Drawbridge is live, every app behind it uses this driver and needs no Entra registration of its own.

## How it verifies

The gateway driver signs the user in from a signed JWT assertion that the auth gateway (Drawbridge) puts in one header. It verifies the assertion's signature against the gateway's public key, then checks the issuer, audience and time claims. It never reads a plain identity header: `Remote-User`, `X-Remote-User`, or any spelling of them, because a plain header can come from anywhere on the shared network. And the request must come from a trusted proxy: the direct peer (`REMOTE_ADDR`) must match one of the configured CIDR ranges. A valid assertion from an untrusted peer is still rejected.

The algorithm is pinned from config, so a token that claims a different algorithm (or `none`) is refused before its signature is even looked at.

Every assertion is single-use. The driver requires a `jti` claim, refuses an assertion that lives longer than `gateway.max_lifetime` seconds, and remembers each `jti` it has seen in a replay guard, so a captured assertion cannot be played back a second time. The guard is a PSR-16 cache: the Laravel adapter wires one up for you, and a raw PHP app passes its own (below).

## Configure the driver

```php
<?php

declare(strict_types=1);

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Drivers\Psr16ReplayGuard;

$config = Config::fromArray([
    'driver' => 'gateway',
    'environment' => 'production',
    'gateway' => [
        'public_key' => file_get_contents('/etc/sesame/gateway.pub'),
        'issuer' => 'https://drawbridge.wayne.edu',
        'audience' => 'sesame',
        'trusted_proxies' => ['10.0.0.0/8'],
    ],
]);

// The gateway driver needs no HTTP client: it only reads the assertion from
// the request. It does need a replay guard, so a captured assertion cannot be
// played back a second time. Any PSR-16 cache works (symfony/cache is shown);
// the Laravel adapter wires one up for you, so you only see this in raw PHP.
$guard = new Psr16ReplayGuard(new FilesystemAdapter());
$driver = DriverFactory::make($config, null, null, null, $guard);
```

Then build the Sesame facade with the driver, as in the Raw PHP section of the README.

## Config keys

| Key | Required | Default | Meaning |
|---|---|---|---|
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

## Assertion claims

The assertion must carry `sub`, `iat`, `exp` and `jti`; the `sub` claim becomes the username. The optional `email`, `name` and `affiliations` claims become read-only identity attributes. The sign-in time on the identity comes from the driver's clock.

The optional `auth_time` and `amr` claims become the identity's MFA facts, for `requireMfa()`. `auth_time` is the epoch seconds of the user's last MFA sign-in; `amr` is the list of methods they used. A missing or malformed `auth_time` leaves the MFA time unset, and a missing or malformed `amr` leaves the method list empty, so a claim the gateway did not verify can never satisfy the check.

## Rotating the gateway's signing key

The gateway signs with one key at a time and names it in the `kid` header of every assertion. An app may list several public keys at once, so a key rotation needs no coordinated window. The driver verifies each assertion with the key listed under its `kid`; an unknown `kid` is refused, and so is one signed by another listed key under the wrong `kid`. With two or more keys listed, an assertion with no `kid` is refused; with exactly one, it is verified with that key, so a single-key setup keeps working. The algorithm stays pinned from config for every key.

### The key set file

The easy way to list the keys is the key set file Drawbridge publishes (`bin/drawbridge keys:jwks`): a standard JWKS file that always holds the gateway's current key and its next one. Point `gateway.jwks_file` (or `SESAME_GATEWAY_JWKS_FILE`) at it. Public keys are not secrets, so the file may live in your repository or on a read-only mount.

```dotenv
SESAME_GATEWAY_JWKS_FILE=/var/www/app/config/drawbridge-keys.json
```

SESAME reads the file at boot and stops with an `InvalidConfig` error, before any request is served, when the file:

- is missing, unreadable or larger than 64 KB;
- is not a JWKS document (a JSON object with a non-empty `keys` list) or holds more than 8 keys;
- has a key without a `kid`, or lists a `kid` twice;
- holds any private key material (`d`, `p`, `q`, `dp`, `dq`, `qi`, `oth` or `k`); if this happens, treat that private key as exposed;
- has a key of the wrong type for `gateway.algorithm` (ES256 needs EC P-256, RS256 needs RSA of 2048 bits or more, EdDSA needs OKP Ed25519), or an `alg` or `use` that does not match.

### A rotation, from the app's side

1. The gateway switches to its next key. Your app already trusts it, because it was in the file all along: nothing to do.
2. Drawbridge publishes a new file (the new current key and a new next key; the retired key is gone). Deploy it, or refresh the mount, when you can. Until then the old file still works.

Drawbridge's [rotation guide](https://drawbridge.apps.wayne.edu/docs/guides/rotating-the-signing-key.html) has the gateway's side and the emergency steps.

### The same keys in PHP config

`gateway.public_keys` takes the same keys as a map. Set one of `public_key`, `public_keys` and `jwks_file`, never two.

```php
'gateway' => [
    'public_keys' => [
        'drawbridge-2026-11' => file_get_contents('/etc/sesame/gateway-2026-11.pub'),
        'drawbridge-2027-11' => file_get_contents('/etc/sesame/gateway-2027-11.pub'),
    ],
    // issuer, audience, trusted_proxies and the rest, as before.
],
```

Two keys on the pegboard, one door: while the old one still hangs there, nobody gets locked out.

## Related

- [The cas driver](driver-cas.md)
- [Running off Citadel](running-off-citadel.md)
