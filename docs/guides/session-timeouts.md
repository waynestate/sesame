---
title: Session timeouts
description: Every signed-in session has two clocks: an idle timeout that drops it after quiet time and an absolute lifetime that ends it no matter how active it is.
---

# Session timeouts

Open sesame opens the door for a live session; a stale one finds it locked.
This page is about the two clocks every signed-in session runs on and the
settings that control them. When you have tuned them, see
[Requiring MFA](requiring-mfa.md), which ages the sign-in time the same
clocks record.

## The two clocks

Every signed-in session has two clocks. The idle timeout is the quiet time
after which the session is dropped, and the absolute lifetime is the longest
a session may live, no matter how active it is. Both are in minutes, and both
are checked on every `user()` call, so a stale session is signed out on the
spot the first time anything asks who is signed in.

| Key | Default | Meaning |
|---|---|---|
| `session.idle_minutes` | `120` | Drop the session after this much quiet time. |
| `session.absolute_minutes` | `720` | End the session this long after sign-in, even if it is still active. |
| `logoutReuseTtlSeconds` | `60` | The window in which a reused logged-out session ID is logged as an anomaly, in seconds. A `new NativeSession()` option, not a config key; `0` makes `invalidate()` destroy the session outright instead of leaving the logout marker. |
| `checkSessionSettings` | `null` | How a native session treats `session.use_strict_mode` and `session.use_only_cookies`. A `new NativeSession()` option, not a config key. `null` turns both on whenever no session is active and warns once per request (`E_USER_WARNING`) when the app started its session with either one off; `true` also turns them on but throws `LogicException` on every call instead of warning; `false` leaves the settings alone and stays quiet. |

## How the clocks are checked

On login the session stores the sign-in time and the last activity time, and
`user()` refreshes the last activity time on every call that finds a live
session. A session made before the timeouts existed (a user, no timestamps)
counts as expired.

## Regeneration grace

On login the session ID is regenerated. For the native PHP session the old
ID is not deleted at once: it is marked with a "destroyed at" time and the
new ID and kept for a 60 second grace, so a user whose browser is still
sending the old ID after a slow network does not lose the login. After the
grace, any use of the old ID is treated as a possible hijack: the session is
retired, a clean one is started, and `sesame.session_anomaly` is logged as a
warning with `reason` `destroyed_session_reused`. Pass `graceSeconds: 0` to
`new NativeSession()` to restore the old behavior, where the old session is
deleted at once. Laravel uses the framework's own regenerate.

## Logout reuse window

When `invalidate()` retires a session it leaves a "logged out at" marker in
the session store itself, under the same ID. The marker lives in the store,
not in process memory, so no extra cache is needed and every worker sees the
same marker. A later request that presents that ID is not revived: the marked
session is retired, a fresh empty one starts, and while the reuse window lasts
(60 seconds by default, the `logoutReuseTtlSeconds` option of
`new NativeSession()`) the reuse is logged once with `reason`
`logged_out_session_reused`. Past the window the marked session is still
retired, just not logged. Pass `logoutReuseTtlSeconds: 0` to turn the check
off: the session is destroyed outright on logout, the old behavior.

## In Laravel

In Laravel the keys are `SESAME_SESSION_IDLE_MINUTES` and
`SESAME_SESSION_ABSOLUTE_MINUTES`.

## Related

- [Requiring MFA](requiring-mfa.md)
- [Step-up (authentication context)](step-up.md)
- [Laravel](laravel.md)
- [Raw PHP](raw-php.md)
