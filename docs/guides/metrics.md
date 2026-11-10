---
title: Counting sign-ins (metrics)
description: SESAME can count every finished sign-in as sesame_logins_total, by driver and outcome, in HELMET's metrics store, so it shows on HELMET's /metrics. Off by default.
---

# Counting sign-ins (metrics)

This page is for the opt-in sign-in counter: what it counts, how to turn it on in a Laravel or raw PHP app, and the
queries worth keeping. Read [HELMET's metrics guide](https://helmet.apps.wayne.edu/docs/guides/metrics.html) first:
SESAME writes the counter, HELMET serves it.

## What it counts

One counter, `sesame_logins_total`, with two labels and nothing else:

| Label | Values |
|---|---|
| `driver` | `cas`, `fake`, `gateway`, `oauth2`, `oidc`, `saml`, else `other` |
| `outcome` | `success`, `failed`, `refused`, `unavailable`, else `other` |

| Outcome | When | The log event next to it |
|---|---|---|
| `success` | The identity was verified and the app's resolver returned a user. | `sesame.login` |
| `failed` | The driver refused the sign-in: a bad ticket, token or assertion. | `sesame.login_failed` |
| `refused` | The identity was verified, but the resolver returned null (the app said no). | `sesame.login_refused` |
| `unavailable` | The identity provider could not be reached, timed out or answered with a 5xx (`ProviderUnavailable`). | `sesame.login_failed` |

A trip to the identity provider is not counted: only the sign-ins that finish. No label ever carries a username, an IP,
a session ID or a URL, and both labels are checked against the lists above before they reach the store, so the
counter has at most 35 series no matter what the internet sends.

`unavailable` is the one to alert on: a jump in it means CAS (or Entra, or the IdP) is down for this app, not that
people typed the wrong password.

## How it reaches `/metrics`

SESAME serves no endpoint, holds no token and adds no route. It writes the counter into a
[promphp](https://github.com/PromPHP/prometheus_client_php) store, and HELMET's `/metrics` renders every metric
family in its store. So when SESAME writes to the store HELMET's request metrics use (APCu, Redis or SQLite), the
counter shows up on the scrape next to `helmet_http_request_duration_seconds`, behind HELMET's bearer token or
allow list. One endpoint per app, one place to gate it.

PHP shares nothing between requests, so a counter needs a store every worker can reach: that is why the counter
needs HELMET's request metrics on (`HELMET_METRICS_STORAGE` set), not just its endpoint.

## Turn it on

### Laravel

Turn on HELMET's metrics with a store, then SESAME's counter:

```dotenv
HELMET_METRICS_ENABLED=true
HELMET_METRICS_TOKEN=<a long random string, from your secret store>
HELMET_METRICS_STORAGE=apcu
SESAME_METRICS_ENABLED=true
```

The adapter counts into the app's `Prometheus\Storage\Adapter` binding when there is one (bind it to share another
promphp store), else into the store HELMET's `helmet.metrics` config names. The store is opened on the first
sign-in of a request, never on a request that signs nobody in.

### Raw PHP

Give the facade a `PrometheusLoginMetrics` as its seventh argument, on HELMET's store:

```php
use Waynestate\Helmet\Metrics\MetricsStorage;
use Waynestate\Sesame\Metrics\PrometheusLoginMetrics;
use Waynestate\Sesame\Sesame;

use function Waynestate\Helmet\helmet_metrics_env;
use function Waynestate\Sesame\sesame;

$helmet = helmet_metrics_env(); // HELMET_METRICS_* and HELMET_POLICY_PATH

$metrics = new PrometheusLoginMetrics(static fn () => MetricsStorage::create(
    $helmet['storage'] ?? null,
    $helmet['redis_url'] ?? null,
    $helmet['sqlite_path'] ?? null,
));

sesame(new Sesame($driver, $resolver, $session, $config, null, $logger, $metrics));
```

The factory runs on the first sign-in only. Any `Prometheus\Storage\Adapter` works in its place, if the app keeps
its own promphp store. A Wayne Auth app passes the same object as the last argument of `WayneAuth::boot()`.

## Check it

Sign in once, then scrape:

```bash
curl -s -H "Authorization: Bearer $HELMET_METRICS_TOKEN" https://your-app.example.test/metrics | grep sesame_
```

Expect `sesame_logins_total{driver="cas",outcome="success"} 1`. Nothing there? Look in the log for
`sesame.metrics_failed` (see [Troubleshooting](../troubleshooting.md)).

## Graph it in Grafana

The sign-ins dashboard ships in
[`docs/grafana/sesame.json`](https://git.wayne.edu/enterprise-apps/sesame/-/blob/main/docs/grafana/sesame.json).
In Grafana, open Dashboards, New, Import, upload the file, and pick your Prometheus data source for the
`DS_PROMETHEUS` input. It sits next to HELMET's fleet dashboard and has two rows:

- **Sign-ins**: sign-ins in the time range, the success rate, how many hit an unreachable identity provider (red
  above zero), how many the app refused, and sign-ins per minute by outcome and by driver.
- **When it goes wrong**: identity provider outages by app (one app alone points at its own network or HTTP client,
  every app at once points at the provider), the failed share by driver (a jump after a deploy is usually a changed
  URL, audience or key), and a table of sign-ins by app, driver and outcome.

`job` and `instance` variables at the top narrow every panel. The dashboard was checked against a local Prometheus
and Grafana with two simulated apps (one `cas`, one `saml` with an identity provider outage) signing in through
SESAME into HELMET's SQLite store and scraped from HELMET's `/metrics`.

## Queries worth keeping

```promql
# Sign-ins per minute, by outcome
sum by (outcome) (rate(sesame_logins_total[5m])) * 60

# Share of sign-ins that hit an unreachable identity provider, per app
sum by (instance) (rate(sesame_logins_total{outcome="unavailable"}[5m]))
  / sum by (instance) (rate(sesame_logins_total[5m]))
```

Counters that reset on a restart are fine: `rate()` and `increase()` handle resets. WSU has no Prometheus server yet,
so this graphs as soon as one exists and scrapes HELMET's `/metrics`.

## When the store breaks

Counting is best effort. A store that is down, or a counter turned on with no store to write to, never changes a
sign-in: the user is signed in (or refused) exactly as before, and the first count that fails logs one
`sesame.metrics_failed` warning with `event`, `driver` and `at`. The store's own error is never logged, because it
can carry a Redis URL with its password; when the cause is SESAME's own setup (no store, promphp not installed), the
record adds `reason` saying what to turn on.

## Related

- [Laravel](laravel.md)
- [Raw PHP](raw-php.md)
- [Running off Citadel](running-off-citadel.md)
- [Configuration](../reference/configuration.md)
