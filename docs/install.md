---
title: Install
description: What SESAME needs, the one composer line, and how to prove it took.
---

# Install

## Requirements

- PHP 8.3 or newer (`^8.3`).
- The `simplexml` extension (`ext-simplexml`).
- A PSR-18 HTTP client for the `cas`, `oauth2` and `oidc` drivers (and `saml` with a metadata URL): Guzzle or
  symfony/http-client. SESAME suggests one, it does not require one.

## Install

```
composer require waynestate/sesame
```

> [!NOTE]
> The `oidc` driver also needs `league/oauth2-client`, which SESAME suggests
> but does not require: `composer require league/oauth2-client`. Without it,
> building the driver fails with: `The oidc driver needs league/oauth2-client.
> Install it with: composer require league/oauth2-client`.

> [!NOTE]
> The `saml` driver (opt-in) needs `onelogin/php-saml` 4.3.2 or later, which
> SESAME suggests but does not require: `composer require onelogin/php-saml`.
> Older releases are refused, by `composer.json` and when the driver is built.

## Verify

The quickest proof that SESAME is active is a sign-in. In local development,
configure the `fake` driver (the [Quick start](guides/quick-start.md) walks
through it), then call:

```php
$user = sesame_login();
```

Expected: when the login has not completed, SESAME sends the redirect to the
identity provider and stops the script; when the user is already signed in
and the request has no ticket, it returns the user. With the `fake` driver
that is the user your resolver made from the configured username, `jdoe` in
the Quick start.

## Next steps

- [Quick start](guides/quick-start.md): a first sign-in, one step at a time.
- [Which driver for my app?](guides/which-driver.md): pick the key for your door.
- [Raw PHP](guides/raw-php.md) and [Laravel](guides/laravel.md): the two ways to wire it in.
