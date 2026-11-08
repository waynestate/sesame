---
title: "Decision: the 60 second regenerate grace | SESAME docs"
description: "Why SESAME keeps the old session for 60 seconds after it regenerates the session ID, and how to turn that off."
url: https://sesame.apps.wayne.edu/docs/reference/regenerate-grace.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Decision: the 60 second regenerate grace[#](https://sesame.apps.wayne.edu/docs/reference/regenerate-grace.html#decision-the-60-second-regenerate-grace)

When SESAME regenerates the session ID at login, it does not delete the old session at once. It keeps the old session for a short grace, so a user on a slow network does not lose the login, and a reuse after the grace is treated as a possible hijack.

## Decision[#](https://sesame.apps.wayne.edu/docs/reference/regenerate-grace.html#decision)

`NativeSession::regenerate()` marks the old session with a "destroyed at" time and the new ID, keeps it for a 60 second grace, and copies the data to the new session. The constructor takes `graceSeconds`, and 60 is the default.

## Why[#](https://sesame.apps.wayne.edu/docs/reference/regenerate-grace.html#why)

The [PHP manual](https://www.php.net/manual/en/function.session-regenerate-id.php) says `session_regenerate_id()` does not handle an unstable network well. When the old ID is deleted at once, a user whose browser is still sending the old ID can lose the session when the response with the new ID never arrives. Deleting at once also removes the chance to see a stolen ID being replayed. The manual's example keeps the old session for 300 seconds; that is the example's choice, not a rule. The full reasoning is in `BEST_PRACTICES.md`, section S43.

## Turning it off[#](https://sesame.apps.wayne.edu/docs/reference/regenerate-grace.html#turning-it-off)

Pass `graceSeconds: 0` to the `NativeSession` constructor to restore the immediate delete. A negative value is refused with an `InvalidArgumentException`.

## After the grace[#](https://sesame.apps.wayne.edu/docs/reference/regenerate-grace.html#after-the-grace)

A use of the old ID after the grace is treated as a possible hijack: the session is retired, a clean one starts, and `sesame.session_anomaly` is logged as a warning with `reason` `destroyed_session_reused` when a logger is set. The log carries no session ID or session data.

## Not covered[#](https://sesame.apps.wayne.edu/docs/reference/regenerate-grace.html#not-covered)

The Laravel adapter uses the framework's own regenerate and is unchanged. Logout still deletes the session at once (S7).

## Tests[#](https://sesame.apps.wayne.edu/docs/reference/regenerate-grace.html#tests)

`NativeSessionTest` covers the behavior:

- `testRegenerateKeepsTheOldSessionDuringTheGrace`
- `testTheOldSessionIsStillUsableWithinTheGrace`
- `testTheOldSessionIsRejectedAfterTheGraceAndLogged`
- `testZeroGraceDeletesTheOldSessionAtOnce`
- `testANegativeGraceIsRefused`
- `testTheAnomalyLogCarriesNoSessionIdOrData`
