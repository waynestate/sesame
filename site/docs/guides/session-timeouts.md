---
title: "Session timeouts | SESAME docs"
description: "Every signed-in session has two clocks: an idle timeout that drops it after quiet time and an absolute lifetime that ends it no matter how active it is."
url: https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Session timeouts [#](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html#session-timeouts)

Open sesame opens the door for a live session; a stale one finds it locked. This page is about the two clocks every signed-in session runs on and the settings that control them. When you have tuned them, see [Requiring MFA](https://sesame.apps.wayne.edu/docs/guides/requiring-mfa.html), which ages the sign-in time the same clocks record.

## The two clocks [#](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html#the-two-clocks)

Every signed-in session has two clocks. The idle timeout is the quiet time after which the session is dropped, and the absolute lifetime is the longest a session may live, no matter how active it is. Both are in minutes, and both are checked on every `user()` call, so a stale session is signed out on the spot the first time anything asks who is signed in.

| Key | Default | Meaning |
| --- | --- | --- |
| `session.idle_minutes` | `120` | Drop the session after this much quiet time (2 hours by default). |
| `session.absolute_minutes` | `720` | End the session this long after sign-in, even if it is still active (12 hours by default). |
| `logoutReuseTtlSeconds` | `60` | The window in which a reused logged-out session ID is logged as an anomaly, in seconds. A `new NativeSession()` option, not a config key; `0` makes `invalidate()` destroy the session outright instead of leaving the logout marker. |
| `checkSessionSettings` | `null` | How a native session treats `session.use_strict_mode`, `session.use_only_cookies` and the session cookie's flags. A `new NativeSession()` option, not a config key. `null` turns both settings on whenever no session is active and warns once per request (`E_USER_WARNING`) when the app started its session with either one off, or with a cookie that lacks `HttpOnly`, `Secure` (on https) or a `Lax` `SameSite` (a `Strict` one is named as the reason sign-in fails); `true` also turns the settings on but throws `LogicException` on every call instead of warning; `false` leaves the settings alone and stays quiet. SESAME never sets a cookie flag. |

## How the clocks are checked [#](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html#how-the-clocks-are-checked)

On login the session stores the sign-in time and the last activity time, and `user()` refreshes the last activity time on every call that finds a live session. A session made before the timeouts existed (a user, no timestamps) counts as expired.

## When a session times out [#](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html#when-a-session-times-out)

A session past either clock is signed out by the `user()` call that finds it, and the rest of the request runs as a guest's. What the guest gets depends on the request:

| Request | Answer |
| --- | --- |
| A page (a browser navigation) | The sign-in, as for any guest: `sesame_login()` and the Wayne Auth bridge send the browser to CAS (or the driver's identity provider), and the Laravel middleware to the login route. With a live CAS session the trip is silent. |
| A script (an XHR, or a `fetch` whose first `Accept` type is JSON) | A 401 instead of a redirect the script could not follow. `sesame_login()` and the Wayne Auth bridge send `{"signedIn":false}` (the signed-out answer of `sesame_status()`), with `Cache-Control: no-store`; the Laravel middleware sends its plain 401. Treat it as "reload the page". |
| `sesame_status()` or `GET /sesame/status` | `{"signedIn":false}` with a 401, as for any signed-out session. |
| The sign-out form | The sign-out runs as usual: there is nothing left to end in the app, and the browser still goes to the provider's logout. |

On the native PHP session, signing out retires the old session (its ID is dead, see the logout reuse window below) and starts a fresh, empty one under a new ID that `session_create_id()` made, never one from the request, with strict mode kept as the app set it. That is what lets the same request store the pending sign-in the cas driver binds to the browser. Before 0.7.1 the old session was closed and nothing replaced it, so that write threw `LogicException: No session is active` and a timed-out visitor saw a blank 500. When the page has already printed something (headers sent), no new cookie can go out, so the session stays closed, as it did before. Laravel's own session has always carried on after a sign-out.

## Regeneration grace [#](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html#regeneration-grace)

On login the session ID is regenerated. For the native PHP session the old ID is not deleted at once: it is marked with a "destroyed at" time and the new ID and kept for a 60 second grace, so a user whose browser is still sending the old ID after a slow network does not lose the login. After the grace, any use of the old ID is treated as a possible hijack: the session is retired, a clean one is started, and `sesame.session_anomaly` is logged as a warning with `reason` `destroyed_session_reused`. Pass `graceSeconds: 0` to `new NativeSession()` to restore the old behavior, where the old session is deleted at once. Laravel uses the framework's own regenerate.

## Logout reuse window [#](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html#logout-reuse-window)

When `invalidate()` retires a session it leaves a "logged out at" marker in the session store itself, under the same ID, and the request carries on in the fresh session described above. The marker lives in the store, not in process memory, so no extra cache is needed and every worker sees the same marker. A later request that presents that ID is not revived: the marked session is retired, a fresh empty one starts, and while the reuse window lasts (60 seconds by default, the `logoutReuseTtlSeconds` option of `new NativeSession()`) the reuse is logged once with `reason` `logged_out_session_reused`. Past the window the marked session is still retired, just not logged. Pass `logoutReuseTtlSeconds: 0` to turn the check off: the session is destroyed outright on logout, the old behavior.

## In Laravel [#](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html#in-laravel)

In Laravel the keys are `SESAME_SESSION_IDLE_MINUTES` and `SESAME_SESSION_ABSOLUTE_MINUTES`.

## Related [#](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html#related)

- [Requiring MFA](https://sesame.apps.wayne.edu/docs/guides/requiring-mfa.html)
- [Step-up (authentication context)](https://sesame.apps.wayne.edu/docs/guides/step-up.html)
- [Laravel](https://sesame.apps.wayne.edu/docs/guides/laravel.html)
- [Raw PHP](https://sesame.apps.wayne.edu/docs/guides/raw-php.html)
