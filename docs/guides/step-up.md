---
title: Step-up (authentication context)
description: Send the user back through a stronger sign-in mid-session, with the prompt, max_age, acr_values and claims parameters a step-up request adds.
---

# Step-up (authentication context)

Open sesame opens the door; step-up checks the user actually walked through the right one. This page is how a step-up travels from the identity provider onto the identity, how to check it on a protected page, and which parameters a step-up request adds. Next, see [Requiring MFA](requiring-mfa.md) for the age check on the same facts.

## What a step-up is

When the gateway requires a step-up for a path, it says so in the signed assertion's `acrs` claim, a list of authentication context ids (`c1` through `c99`). The gateway driver reads that claim and keeps only the ids of the right shape; anything else is dropped, and a missing or malformed claim is not an error, it is simply no step-up.

The contexts live on the identity, and the check is membership, never a prefix match: an identity with `c2` and `c10` satisfies `c2` and `c10`, but not `c1`.

```php
$identity->authContexts();      // list<string>, e.g. ['c1']
$identity->hasAuthContext('c1'); // bool
```

## Checking the context

In raw PHP, a protected page reads the user and the context proof in one call:

```php
// Throws AuthContextNotSatisfied when the identity lacks the context.
$user = sesame_require_context('c1');
```

The core method returns a bool instead of throwing:

```php
if (! $sesame->hasAuthContext('c1')) {
    // The signed-in identity does not have the required context.
}
```

In Laravel the `sesame.context` middleware does the same check on a route:

```php
Route::get('/vault', [VaultController::class, 'index'])
    ->middleware(['web', 'auth.sesame', 'sesame.context:c1']);
```

A request without the context gets a 403, or a JSON 403 with a `message` for JSON requests. A route that names no context fails closed.

## Asking for a step-up (the oidc driver)

With the oidc driver, a login request with `step_up=1` adds the app's configured step-up parameters: `prompt=login` by default, and optionally `max_age`, `acr_values` and `claims`. The request can ask for a step-up but cannot choose what it is; only config feeds those values. The callback then requires a fresh `auth_time`: not in the future (beyond the leeway), not before the sign-in started when `prompt=login` was sent, and within `max_age`, which is 300 seconds when `oidc.step_up.max_age` is not set, so a step-up is never unbounded.

A page that needs fresh credentials sends the user to `login.php?step_up=1` in raw PHP. In Laravel, `sesame.mfa` with `mfa.on_failure=reauth` sends the user to `/login?step_up=1` for you.

### Config keys

| Key | Env | Default | Meaning |
|---|---|---|---|
| `oidc.step_up.prompt` | `SESAME_OIDC_STEP_UP_PROMPT` | `login` | Sent on a step-up only; `login` or empty. |
| `oidc.step_up.max_age` | `SESAME_OIDC_STEP_UP_MAX_AGE` | none (300 enforced) | Seconds; sent on a step-up only, and enforced on `auth_time`. Unset, it is not sent, but a step-up `auth_time` older than 300 seconds still fails. |
| `oidc.step_up.acr_values` | `SESAME_OIDC_STEP_UP_ACR_VALUES` | none | Sent on a step-up only. |
| `oidc.step_up.claims` | `SESAME_OIDC_STEP_UP_CLAIMS` | none | A JSON object (for example an `acrs` request for an Entra authentication context), sent on a step-up only. |

## Which drivers report contexts

The gateway and oidc drivers report authentication contexts (the oidc driver from the ID token's `acrs` claim, after a step-up that asked for one through `oidc.step_up.claims`). A cas identity has none, so a cas identity always fails the check.

> [!NOTE]
> The `acrs` claim feeds `hasAuthContext()`, while `auth_time` and `amr` feed the MFA check. They are separate facts: a context says the sign-in went through a stronger path, and the MFA facts say when and how.

## Related

- [Requiring MFA](requiring-mfa.md)
- [The oidc driver](driver-oidc.md)
- [The gateway driver](driver-gateway.md)
- [Laravel](laravel.md)
- [Session timeouts](session-timeouts.md)

