---
title: "Single-page apps | SESAME docs"
description: "A single-page app reads a JSON answer to see who is signed in: sesame_status() in raw PHP and GET /sesame/status in Laravel, 200 with the session facts or 401 when signed out, never cacheable."
url: https://sesame.apps.wayne.edu/docs/guides/single-page-apps.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Single-page apps[#](https://sesame.apps.wayne.edu/docs/guides/single-page-apps.html#single-page-apps)

A single-page app that renders its own signed-in state needs a JSON answer to "who is signed in?". This page is about that answer: where it comes from, what it contains, and how to keep it honest. When you have wired it up, see [Session timeouts](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html), which explains the two expiries the answer carries.

## The two endpoints[#](https://sesame.apps.wayne.edu/docs/guides/single-page-apps.html#the-two-endpoints)

SESAME answers the question two ways: `sesame_status()` in raw PHP and the `GET /sesame/status` route in Laravel. Both send the session facts with a 200 when someone is signed in, and `{"signedIn":false}` with a 401 when no one is:

```json
{
    "signedIn": true,
    "username": "jdoe",
    "driver": "cas",
    "authenticatedAt": "2026-11-03T09:15:00+00:00",
    "authTime": null,
    "amr": [],
    "idleExpiresAt": "2026-11-03T11:15:00+00:00",
    "absoluteExpiresAt": "2026-11-04T09:15:00+00:00",
    "logoutToken": "3f7a1c9e8b2d4a6f",
    "user": null
}
```

and, when signed out:

```json
{"signedIn":false}
```

The times are always UTC. Every answer carries `Cache-Control: no-store`, `Content-Type: application/json; charset=utf-8` and `X-Content-Type-Options: nosniff`, so a browser or proxy never caches who is signed in.

## In raw PHP[#](https://sesame.apps.wayne.edu/docs/guides/single-page-apps.html#in-raw-php)

Put the function at the end of a small endpoint script, after the same bootstrap a protected page uses:

```php
// status.php
require __DIR__.'/bootstrap.php'; // your usual session_start() and sesame($sesame)

sesame_status();
```

`sesame_status()` stops the script after the answer, so nothing after it runs in that request. Only GET (and HEAD) ask the question; any other method answers 405 (`Allow: GET, HEAD`) and leaves the session alone.

## In Laravel[#](https://sesame.apps.wayne.edu/docs/guides/single-page-apps.html#in-laravel)

The route is registered with the login and logout routes (in the `web` group, so the session is available; a GET needs no CSRF token), and `SESAME_ROUTES=false` takes it away with them. The app's SPA reads `/sesame/status` directly.

## With Vue[#](https://sesame.apps.wayne.edu/docs/guides/single-page-apps.html#with-vue)

This repo ships a thin Vue adapter for the answer: the [`vue/` folder](https://git.wayne.edu/enterprise-apps/sesame/-/blob/main/vue/README.md) gives you `useSesame()` (or `createSesame()` as an app-wide plugin) with reactive `signedIn`, `user` and `expired`, a `refresh()` for real user actions, and a `logout()` that posts the sign-out form. It never polls, for the reason below.

## Read it on page load, not on a timer[#](https://sesame.apps.wayne.edu/docs/guides/single-page-apps.html#read-it-on-page-load-not-on-a-timer)

A poll counts as activity: reading the status moves the session's `last_seen`, so polling on a timer would keep a session alive past the idle timeout. Read it when the app loads and after a sign-in, and let the `idleExpiresAt` and `absoluteExpiresAt` in the answer tell you how long the state can be trusted.

## Adding your own user fields[#](https://sesame.apps.wayne.edu/docs/guides/single-page-apps.html#adding-your-own-user-fields)

Pass a closure that returns an array of extra fields for the app's user: `sesame_status(fn ($user) => ['name' => $user->name])` in raw PHP, or bind the closure as `sesame.describe_user` in Laravel. Its array fills the answer's `user` field (null without a closure); the session facts stay untouched, and the identity provider's raw attributes are never part of the answer.

## Signing out[#](https://sesame.apps.wayne.edu/docs/guides/single-page-apps.html#signing-out)

The answer's `logoutToken` is the token the sign-out form posts: the same POST-only, CSRF-checked logout the rest of the app uses, nothing new to wire up.

## Related[#](https://sesame.apps.wayne.edu/docs/guides/single-page-apps.html#related)

- [Session timeouts](https://sesame.apps.wayne.edu/docs/guides/session-timeouts.html)
- [Raw PHP](https://sesame.apps.wayne.edu/docs/guides/raw-php.html)
- [Laravel](https://sesame.apps.wayne.edu/docs/guides/laravel.html)
