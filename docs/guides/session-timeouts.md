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

## How the clocks are checked

On login the session stores the sign-in time and the last activity time, and
`user()` refreshes the last activity time on every call that finds a live
session. A session made before the timeouts existed (a user, no timestamps)
counts as expired.

## In Laravel

In Laravel the keys are `SESAME_SESSION_IDLE_MINUTES` and
`SESAME_SESSION_ABSOLUTE_MINUTES`.

## Related

- [Requiring MFA](requiring-mfa.md)
- [Step-up (authentication context)](step-up.md)
- [Laravel](laravel.md)
- [Raw PHP](raw-php.md)
