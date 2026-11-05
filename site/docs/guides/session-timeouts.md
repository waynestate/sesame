---
title: "Session timeouts | SESAME docs"
description: "Every signed-in session has two clocks: an idle timeout that drops it after quiet time and an absolute lifetime that ends it no matter how active it is."
url: https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Session timeouts[#](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html#session-timeouts)

Open sesame opens the door for a live session; a stale one finds it locked. This page is about the two clocks every signed-in session runs on and the settings that control them. When you have tuned them, see [Requiring MFA](https://sesame.apps.wayne.edu/docs/guides/requiring-mfa.html), which ages the sign-in time the same clocks record.

## The two clocks[#](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html#the-two-clocks)

Every signed-in session has two clocks. The idle timeout is the quiet time after which the session is dropped, and the absolute lifetime is the longest a session may live, no matter how active it is. Both are in minutes, and both are checked on every `user()` call, so a stale session is signed out on the spot the first time anything asks who is signed in.

| Key | Default | Meaning |
| --- | --- | --- |
| `session.idle_minutes` | `120` | Drop the session after this much quiet time. |
| `session.absolute_minutes` | `720` | End the session this long after sign-in, even if it is still active. |

## How the clocks are checked[#](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html#how-the-clocks-are-checked)

On login the session stores the sign-in time and the last activity time, and `user()` refreshes the last activity time on every call that finds a live session. A session made before the timeouts existed (a user, no timestamps) counts as expired.

## Regeneration grace[#](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html#regeneration-grace)

On login the session ID is regenerated. For the native PHP session the old ID is not deleted at once: it is marked with a "destroyed at" time and the new ID and kept for a 60 second grace, so a user whose browser is still sending the old ID after a slow network does not lose the login. After the grace, any use of the old ID is treated as a possible hijack: the session is retired, a clean one is started, and `sesame.session_anomaly` is logged as a warning with `reason` `destroyed_session_reused`. Pass `graceSeconds: 0` to `new NativeSession()` to restore the old behavior, where the old session is deleted at once. Laravel uses the framework's own regenerate.

## In Laravel[#](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html#in-laravel)

In Laravel the keys are `SESAME_SESSION_IDLE_MINUTES` and `SESAME_SESSION_ABSOLUTE_MINUTES`.

## Related[#](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html#related)

- [Requiring MFA](https://sesame.apps.wayne.edu/docs/guides/requiring-mfa.html)
- [Step-up (authentication context)](https://sesame.apps.wayne.edu/docs/guides/step-up.html)
- [Laravel](https://sesame.apps.wayne.edu/docs/guides/laravel.html)
- [Raw PHP](https://sesame.apps.wayne.edu/docs/guides/raw-php.html)
