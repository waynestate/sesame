---
title: "The gateway driver | Sesame docs"
description: "The recommended path on Citadel: verify the signed JWT assertion the Drawbridge gateway sends in one header, never a bare identity header."
url: https://sesame.apps.wayne.edu/docs/guides/driver-gateway.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# The gateway driver[#](https://sesame.apps.wayne.edu/docs/guides/driver-gateway.html#the-gateway-driver)

This page sets up the `gateway` driver, the recommended path on Citadel: it verifies the signed JWT assertion the Drawbridge gateway sends in one header, never a bare identity header. Configure the driver below, then read the key rotation steps before you rotate the gateway's signing key.

> \[!NOTE\] Drawbridge is not live yet, so there is no production gateway to point at today. Until it is, CAS (the `cas` driver) is today's option for a Citadel app, and it needs no per-app Entra registration. Once Drawbridge is live, every app behind it uses this driver and needs no Entra registration of its own.

## How it verifies[#](https://sesame.apps.wayne.edu/docs/guides/driver-gateway.html#how-it-verifies)

The gateway driver signs the user in from a signed JWT assertion that the auth gateway (Drawbridge) puts in one header. It verifies the assertion's signature against the gateway's public key, then checks the issuer, audience and time claims. It never reads a plain identity header: `Remote-User`, `X-Remote-User`, or any spelling of them, because a plain header can come from anywhere on the shared network. And the request must come from a trusted proxy: the direct peer (`REMOTE_ADDR`) must match one of the configured CIDR ranges. A valid assertion from an untrusted peer is still rejected.

The algorithm is pinned from config, so a token that claims a different algorithm (or `none`) is refused before its signature is even looked at.

Every assertion is single-use. The driver requires a `jti` claim, refuses an assertion that lives longer than `gateway.max_lifetime` seconds, and remembers each `jti` it has seen in a replay guard, so a captured assertion cannot be played back a second time. The guard is a PSR-16 cache: the Laravel adapter wires one up for you, and a raw PHP app passes its own (below).

## Configure the driver[#](https://sesame.apps.wayne.edu/docs/guides/driver-gateway.html#configure-the-driver)

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

## Config keys[#](https://sesame.apps.wayne.edu/docs/guides/driver-gateway.html#config-keys)

| Key | Required | Default | Meaning |
| --- | --- | --- | --- |
| `gateway.header` | No | `X-Sesame-Assertion` | The one header that holds the signed assertion. |
| `gateway.algorithm` | No | `ES256` | `ES256`, `RS256` or `EdDSA`. Pinned, so a token that claims another algorithm is refused. |
| `gateway.public_key` | Yes | none | The gateway's public key: a PEM string (or a path to one) for `ES256` and `RS256`, or a base64-encoded raw key (or a path to one) for `EdDSA`. |
| `gateway.public_keys` | No | none | A map of `kid` to public key, for rotating the gateway's signing key. Use this or `public_key`, not both. |
| `gateway.issuer` | Yes | none | The `iss` claim the assertion must carry. |
| `gateway.audience` | Yes | none | The `aud` claim the assertion must carry. |
| `gateway.leeway_seconds` | No | `30` | Clock skew allowed on the `iat`, `nbf` and `exp` checks, in seconds. |
| `gateway.trusted_proxies` | Yes | none | A non-empty array of CIDR ranges (or single IPs) the gateway proxy sits behind. |
| `gateway.max_lifetime` | No | `300` | The longest an assertion may live (`exp` minus `iat`), in seconds. Longer assertions are refused, so a stolen long-lived token is useless. |
| `gateway.replay_store` | No | the app's default cache store | The cache store the used `jti`s are kept in (Laravel only; raw PHP passes its own PSR-16 cache to the guard). |
| `gateway.allow_without_replay_guard` | No | `false` | Skip the replay guard entirely. Local development only: a production app must keep replay protection on. |

## Assertion claims[#](https://sesame.apps.wayne.edu/docs/guides/driver-gateway.html#assertion-claims)

The assertion must carry `sub`, `iat`, `exp` and `jti`; the `sub` claim becomes the username. The optional `email`, `name` and `affiliations` claims become read-only identity attributes. The sign-in time on the identity comes from the driver's clock.

The optional `auth_time` and `amr` claims become the identity's MFA facts, for `requireMfa()`. `auth_time` is the epoch seconds of the user's last MFA sign-in; `amr` is the list of methods they used. A missing or malformed `auth_time` leaves the MFA time unset, and a missing or malformed `amr` leaves the method list empty, so a claim the gateway did not verify can never satisfy the check.

## Rotating the gateway's signing key[#](https://sesame.apps.wayne.edu/docs/guides/driver-gateway.html#rotating-the-gateway-s-signing-key)

The gateway may list two public keys at once, so a key rotation needs no coordinated window. The driver reads the `kid` header of each assertion and verifies it with the key listed under that `kid`; an assertion with a missing or unknown `kid` is refused, and so is one signed by the other listed key under the wrong `kid`. The algorithm stays pinned from config for every key.

1. Add the new public key to `gateway.public_keys` under its `kid`, next to the old one, and deploy every app.
2. Switch the gateway's `DRAWBRIDGE_SIGNING_KEY_ID` and its private key to the new pair. New assertions carry the new `kid`, and the old ones still verify against the old key until they expire.
3. After the old assertions have expired (they live 60 seconds by default; `gateway.max_lifetime` is the ceiling), remove the old entry. With one key left, you can go back to `gateway.public_key`.

```php
'gateway' => [
    'public_keys' => [
        '2026a' => file_get_contents('/etc/sesame/gateway-2026a.pub'),
        '2026b' => file_get_contents('/etc/sesame/gateway-2026b.pub'),
    ],
    // issuer, audience, trusted_proxies and the rest, as before.
],
```

Two keys on the pegboard, one door: while the old one still hangs there, nobody gets locked out.

## Related[#](https://sesame.apps.wayne.edu/docs/guides/driver-gateway.html#related)

- [The cas driver](https://sesame.apps.wayne.edu/docs/guides/driver-cas.html)
- [Running off Citadel](https://sesame.apps.wayne.edu/docs/guides/running-off-citadel.html)
