---
title: Requiring MFA
description: Require proof that the user's MFA is still good, and pick what happens when the check fails: deny with 403, or send the user back to login.
---

# Requiring MFA

Open sesame opens the door; requiring MFA checks the key is still in the lock. A signed-in session is not the same as a recently-verified one, so a protected page can ask for proof that the user's MFA is still good, not just that they are signed in. This page is the rule itself and how to apply it from raw PHP or Laravel; next, see [Step-up (authentication context)](step-up.md) for asking the identity provider for a stronger sign-in.

## The rule

The check reads two facts from the identity: `authTime`, when the user last completed MFA, and `amr`, the methods they used. It is satisfied when the identity has an `authTime` that is not in the future, is not older than the allowed age, and the `amr` includes an MFA method. It fails closed: no `authTime`, no MFA method, or an age that has passed all deny the request. Anything the provider did not report is treated as missing, so a driver that carries no MFA facts is always denied.

The checks run in a fixed order, so the reason is stable:

| Order | Reason | When |
|---|---|---|
| 1 | `NO_AUTH_TIME` | The identity has no `authTime`. |
| 2 | `FUTURE_AUTH_TIME` | `authTime` sits more than 60 seconds in the future (a clock problem). |
| 3 | `TOO_OLD` | `authTime` is older than the allowed age. |
| 4 | `NO_MFA_METHOD` | The `amr` list carries no method that counts as MFA. |
| 5 | `SATISFIED` | All of the above pass. |

A sign-in exactly the allowed age old is still satisfied. The allowed age defaults to eight hours (`28800` seconds).

## MFA methods

The `amr` values that count as MFA:

| MFA method | Meaning |
|---|---|
| `mfa` | A generic MFA step. |
| `otp` | A one-time password. |
| `hwk` | A hardware key. |
| `swk` | A software key. |
| `fido` | A FIDO/WebAuthn assertion. |
| `sms` | A one-time code by SMS. |
| `pop` | A possession proof. |

## Raw PHP

In raw PHP, a protected page reads the user and the MFA proof in one call:

```php
// Throws MfaNotSatisfied when the MFA check is not satisfied.
$user = sesame_require_mfa();
```

Or, with a custom max age:

```php
$user = sesame_require_mfa(3600);
```

The core method returns the result instead of throwing:

```php
$result = $sesame->requireMfa();

if (! $result->satisfied) {
    // $result->reason is a stable code; $result->message() is the human text.
}
```

## Which drivers carry MFA facts

The gateway and oidc drivers report MFA facts from the token's `auth_time` and `amr` claims (the oidc driver also has `oidc.assume_mfa`, see [The oidc driver](driver-oidc.md)). The cas driver reports none by default, because CAS carries no usable MFA signal, so a cas identity fails the check. With `cas.assume_mfa` on, a cas identity carries `mfa` in its `amr` and its MFA time from the CAS `authenticationDate` attribute, so the check can pass when the CAS server enforces MFA for every sign-in.

> [!WARNING]
> Turn on `cas.assume_mfa` only when the CAS server enforces MFA for every sign-in. Ask your CAS server's owner before turning it on; a server that enforces MFA only sometimes, or only for some users, would make the MFA check pass for sign-ins that never did MFA.

## In Laravel

Require MFA on a route with the `sesame.mfa` middleware:

```php
Route::get('/vault', fn () => view('vault'))->middleware(['auth.sesame', 'sesame.mfa']);
```

It lets the request through when the signed-in user's MFA is still good, and otherwise stops it: a 403 by default, a JSON 403 for JSON requests, and a redirect to the login route when `mfa.on_failure` is `reauth`. The allowed age comes from the route parameter when there is one, and from `mfa.max_age` when there is not:

```php
Route::get('/vault', fn () => view('vault'))->middleware(['auth.sesame', 'sesame.mfa:3600']);
```

The `mfa.on_failure` setting picks what a failed check does: `deny` (the default) answers a 403, and `reauth` sends the user back to the login route. With the oidc driver, `reauth` asks the provider for a step-up, so the user comes back with a fresh `auth_time`; see [Step-up (authentication context)](step-up.md).

## Related

- [Session timeouts](session-timeouts.md)
- [The oidc driver](driver-oidc.md)
- [The cas driver](driver-cas.md)
- [The gateway driver](driver-gateway.md)
- [Laravel](laravel.md)
- [Step-up (authentication context)](step-up.md)

