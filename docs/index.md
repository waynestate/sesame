---
title: Overview
description: Wayne State's protocol-neutral auth library: it returns a verified identity and leaves the session to the app.
---

<div align="center">
  <a href="../index.html"><img src="readme/hero.jpg" width="100%" alt="SESAME's front page: the headline 'The way in. One lock, any key.' and the composer require command beside a pegboard where cas, gateway and SAML keys hang around SESAME's open-door mark, over a green service counter that reads 'Same door for every key.'"></a>
</div>

# SESAME

<p>
  <img src="readme/badges/php.svg" alt="php ^8.3">
  <img src="readme/badges/license.svg" alt="license MIT">
  <img src="readme/badges/drivers.svg" alt="drivers: fake, cas, gateway, oauth2, oidc">
  <a href="https://github.com/waynestate/sesame/actions/workflows/ci.yml"><img src="https://github.com/waynestate/sesame/actions/workflows/ci.yml/badge.svg" alt="GitHub CI"></a>
</p>

**The way in. One lock, any key.** Single Entry, Sessions App-Managed Everywhere: keys cut while you wait, and no
key ever gets to keep the door.

SESAME is Wayne State's protocol-neutral auth library. It returns a verified
identity and leaves the session to the app: every driver ends in the same
place, the app's `findOrImportUser(Identity)`, and the protocol never holds
the session. Switching driver is a config change with no code change. Open
sesame.

## Where the code lives

The canonical repository is [enterprise-apps/sesame](https://git.wayne.edu/enterprise-apps/sesame) on git.wayne.edu; issues and merge requests open there, and tags are made there, by the maintainer, with an approver. GitHub ([waynestate/sesame](https://github.com/waynestate/sesame)) is a read-only push mirror; nobody pushes to it, and Packagist reads from it. CI runs on git.wayne.edu.

## Install

```
composer require waynestate/sesame
```

## First use

The quickest way to see it work is the `fake` driver in local development: no
server to point at, no registration to make.

```php
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\UserResolver;

session_start();

$config = Config::fromArray([
    'driver' => 'fake',
    'environment' => 'local',
    'fake' => [
        'username' => 'jdoe',
        'attributes' => ['cn' => ['John Doe']],
    ],
]);

$sesame = new Sesame(
    DriverFactory::make($config),
    new class implements UserResolver {
        public function findOrImportUser(Identity $identity): mixed
        {
            return $identity->username;
        }
    },
    new NativeSession(),
    $config,
);

sesame($sesame);

// When the login has not completed, sends the redirect to the identity
// provider and stops the script. When a ticket completes it, sends the
// redirect to the driver's service URL and stops the script, so the ticket
// never stays in the address bar. When the user is already signed in and
// the request has no ticket, returns the user.
$user = sesame_login();
```

Every piece named, and what the app owns and what SESAME does:
[Raw PHP](guides/raw-php.md).

## Pick your path

- [Quick start](guides/quick-start.md): sign a user in from scratch, one step at a time.
- [Install](install.md): what SESAME needs, the composer line, and how to prove it took.
- [Reference](reference/drivers.md): the drivers, the configuration, the API, and the proof.

Status: version 0.4.0, MIT license. The changelog's top heading is [Unreleased].

Agents: read [llms.txt](https://sesame.apps.wayne.edu/llms.txt) first; it maps these docs for language models.
