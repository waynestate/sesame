---
title: Decision: the 60 second regenerate grace
description: Why SESAME keeps the old session for 60 seconds after it regenerates the session ID, and how to turn that off.
---

# Decision: the 60 second regenerate grace

When SESAME regenerates the session ID at login, it does not delete the old session at once. It keeps the old session for a short grace, so a user on a slow network does not lose the login, and a reuse after the grace is treated as a possible hijack.

## Decision

`NativeSession::regenerate()` marks the old session with a "destroyed at" time and the new ID, keeps it for a 60 second grace, and copies the data to the new session. The constructor takes `graceSeconds`, and 60 is the default.

## Why

The [PHP manual](https://www.php.net/manual/en/function.session-regenerate-id.php) says `session_regenerate_id()` does not handle an unstable network well. When the old ID is deleted at once, a user whose browser is still sending the old ID can lose the session when the response with the new ID never arrives. Deleting at once also removes the chance to see a stolen ID being replayed. The manual's example keeps the old session for 300 seconds; that is the example's choice, not a rule. The full reasoning is in `BEST_PRACTICES.md`, section S43.

## Under strict mode

`session.use_strict_mode=1` (HELMET and Squire's php.ini set it) refuses a session ID the store has never seen, and the new ID is not in the store yet. So `regenerate()` stores the new session once with strict mode off, then reopens it with the app's setting back. The new ID comes from `session_create_id()`, never from the request, and the old session's marker names the ID the user is really on. The PHP manual's own example turns strict mode off for the same step.

## Turning it off

Pass `graceSeconds: 0` to the `NativeSession` constructor to restore the immediate delete. A negative value is refused with an `InvalidArgumentException`.

## After the grace

A use of the old ID after the grace is treated as a possible hijack: the session is retired, a clean one starts, and `sesame.session_anomaly` is logged as a warning with `reason` `destroyed_session_reused` when a logger is set. The log carries no session ID or session data.

## Not covered

The Laravel adapter uses the framework's own regenerate and is unchanged. Logout is a separate path: `invalidate()` retires the session, leaving a short-lived logout marker in the store by default (S7).

## Tests

`NativeSessionTest` covers the behavior:

- `testRegenerateKeepsTheOldSessionDuringTheGrace`
- `testRegenerateUnderStrictModeKeepsItsNewIdAndTheSetting`
- `testTheOldSessionIsStillUsableWithinTheGrace`
- `testTheOldSessionIsRejectedAfterTheGraceAndLogged`
- `testZeroGraceDeletesTheOldSessionAtOnce`
- `testANegativeGraceIsRefused`
- `testTheAnomalyLogCarriesNoSessionIdOrData`
