---
title: Running off Citadel
description: SESAME takes its config from the app and has no platform address in code, so a move to another host is a config change, not a code change.
---

# Running off Citadel

This page is the checklist for running an SESAME app off Citadel, on OpenShift, Coolify or raw Docker. Read the three topics below in order, because each one is a config change, not a code change.

Open sesame opens the door on any host, not just Citadel. SESAME takes its config from the app (env or `config/sesame.php`), keeps no files of its own, and has no platform address in code, so a move to OpenShift, Coolify or raw Docker is a config change, not a code change. What differs per host:

## The cas driver

The `cas` driver needs outbound HTTPS from the app to the CAS server, and `service_url` must be the public URL users see, behind any proxy.

## The gateway driver

The `gateway` driver trusts only a signed assertion, never a bare header. A source address is not enough on its own: on shared ingress (OpenShift's router, Coolify's Traefik) the same proxy carries routes with and without the auth step. The header name and the list of trusted auth hops are config.

Its replay guard remembers used `jti`s in a cache store, so that store must be shared too (Redis or a database), for the same reason as the session store: a local-file cache would let a replayed assertion pass on another replica.

> [!WARNING]
> A local-file replay store is unsafe on a multi-replica host: a replayed assertion would pass on another replica. Share the store (Redis or a database).

## The session store

The session store must be shared: Redis or a database, never local files. Every host overlaps old and new containers during a deploy, and most can run more than one replica, so a file session would drop users mid-login. In Laravel, set `SESSION_DRIVER` to `redis` or `database` (Laravel 13 already defaults to `database`); in raw PHP, set `session.save_handler` to a shared handler, for example Redis.

## Related

- [The cas driver](driver-cas.md)
- [The gateway driver](driver-gateway.md)
