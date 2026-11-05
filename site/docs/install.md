---
title: "Install | SESAME docs"
description: "What SESAME needs, the one composer line, and how to prove it took."
url: https://sesame.apps.wayne.edu/docs/install.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Install[#](https://sesame.apps.wayne.edu/docs/install.html#install)

## Requirements[#](https://sesame.apps.wayne.edu/docs/install.html#requirements)

- PHP 8.3 or newer (`^8.3`).
- The `simplexml` extension (`ext-simplexml`).
- A PSR-18 HTTP client for the `cas`, `oauth2` and `oidc` drivers: Guzzle or symfony/http-client. SESAME suggests one, it does not require one.

## Install[#](https://sesame.apps.wayne.edu/docs/install.html#install-1)

```
composer require waynestate/sesame
```

Note

The `oidc` driver also needs `league/oauth2-client`, which SESAME suggests but does not require: `composer require league/oauth2-client`. Without it, building the driver fails with: `The oidc driver needs league/oauth2-client. Install it with: composer require league/oauth2-client`.

## Verify[#](https://sesame.apps.wayne.edu/docs/install.html#verify)

The quickest proof that SESAME is active is a sign-in. In local development, configure the `fake` driver (the [Quick start](https://sesame.apps.wayne.edu/docs/guides/quick-start.html) walks through it), then call:

```php
$user = sesame_login();
```

Expected: when the login has not completed, SESAME sends the redirect to the identity provider and stops the script; when the user is already signed in and the request has no ticket, it returns the user. With the `fake` driver that is the user your resolver made from the configured username, `jdoe` in the Quick start.

## Next steps[#](https://sesame.apps.wayne.edu/docs/install.html#next-steps)

- [Quick start](https://sesame.apps.wayne.edu/docs/guides/quick-start.html): a first sign-in, one step at a time.
- [Which driver for my app?](https://sesame.apps.wayne.edu/docs/guides/which-driver.html): pick the key for your door.
- [Raw PHP](https://sesame.apps.wayne.edu/docs/guides/raw-php.html) and [Laravel](https://sesame.apps.wayne.edu/docs/guides/laravel.html): the two ways to wire it in.
