---
title: Which driver for my app?
description: A table that matches your situation to a driver and tells you the Entra registration it needs.
---

# Which driver for my app?

One lock, any key: this page matches your situation to the right SESAME driver and tells you whether the app needs its own Entra registration. Pick the row that fits, then set that driver in your config.

## Match your situation

| Situation | Driver | Entra registration |
|---|---|---|
| App on Citadel today | `cas` | None. |
| App on Citadel once Drawbridge is live | `gateway` | None per app (Drawbridge holds it once). |
| App off Citadel or an API with its own audience | `oidc` or `oauth2` | Its own registration. |
| Local development | `fake` | None. |

## The recommended path on Citadel

The recommended path on Citadel is the `gateway` driver behind Drawbridge. Drawbridge is Wayne State's auth gateway: the browser signs in once at the gateway, which passes the app a signed assertion that the `gateway` driver verifies. Drawbridge is not live yet, so there is no production gateway to point at today. Until it is, CAS (the `cas` driver) is today's option for a Citadel app, and it needs no per-app Entra registration.

## Related

- [WSU values (Playbook)](./wsu-values.md)
- [Raw PHP](./raw-php.md)
