---
title: "SESAME: The way in."
description: "SESAME is Wayne State's protocol-neutral auth library for PHP: a verified identity for your app, app-native sessions, and drivers behind one config switch. Raw PHP, Laravel/Blade and Inertia/Vue."
url: https://sesame.apps.wayne.edu/
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

![SESAME mark: an arched door standing open, with a gold wedge of light between its leaves](https://sesame.apps.wayne.edu/brand/sesame-avatar-512.png)

Keys cut while you wait · `waynestate/sesame` 0.2

# SESAME

Single Entry, Sessions App-Managed Everywhere.

The way in. One lock, any key.

Wayne State's protocol-neutral auth library for PHP. It hands your app a verified identity and leaves the session to the app: every driver ends in the same place, your app's `findOrImportUser(Identity)`. Switching driver is a config change, not a code change. Open sesame.

`$ composer require waynestate/sesame`

**Already included in [WELD](https://weld.apps.wayne.edu/) and in every [Sourdough](https://sourdough.apps.wayne.edu/) app.** Use SESAME on its own to add sign-in to an app you already have.

[Try a key](https://sesame.apps.wayne.edu/#keys) [Watch one get cut](https://sesame.apps.wayne.edu/#duplicator)

Service counter. Same door for every key.

The pegboard

## One blank per protocol

Six blanks hang on the board, one for each driver. The heads differ; the cut does not. Every key that's cut is cut to the same lock, your app's `findOrImportUser(Identity)`. Pick one and try the door.

.env

`SESAME_DRIVER=cas`

**cas:** CAS 2.0 by default. The CAS server sends the user back with a ticket; SESAME validates it once, then drops it from the address bar. The door opens.

The duplicator

## Swap the blank. Keep the door.

Re-keying an app from `cas` to `gateway`: the machine traces the cut on the key you have and copies it onto a new blank. Scroll, and it runs.

A key duplicator copies the cut of a cas key onto a gateway blank. As it cuts, the config line SESAME_DRIVER=gateway is typed under the new key. The new key goes into the same door, the door opens, and ab1234 is in.

1. **Clamp a gateway blank.** A new head; no cut yet.
2. **Trace the cut.** The tracer follows the cas key and the cutter copies it. Same lock, same cut.
3. **The cut is one config line.** `SESAME_DRIVER=gateway`, plus the gateway's key, issuer, audience and trusted proxies. No code changes.
4. **Open sesame.** Same door, same `findOrImportUser(Identity)`. ab1234 is in.

Three doorways

## Same lock, any frame

Raw PHP, Laravel with Blade, or Inertia with Vue. Install it once; the door is the same.

Install Windows, macOS and Linux: the same line

`$ composer require waynestate/sesame`

Job 01

### Raw PHP

The app owns the session: start it yourself, and let HELMET set the cookie flags. Then ask SESAME who it is.

```
session_start();
// build $sesame (see the README), then:
sesame($sesame);
$user = sesame_login();
```

Protected pages read `sesame_user()`. Login and logout stop the script after their redirect.

Job 02

### Laravel + Blade

The service provider is discovered on its own. Publish the config, then guard a route.

```
php artisan vendor:publish --tag=sesame-config

Route::get('/dashboard', fn () => view('dashboard'))
    ->middleware('auth.sesame');
```

Guests go to `sesame.login`; a JSON guest gets a 401.

Job 03

### Laravel + Inertia/Vue

The same Laravel adapter. The middleware guards the route before any Vue page is sent.

```
Route::get('/vault', fn () => Inertia::render('Vault'))
    ->middleware(['auth.sesame', 'sesame.mfa']);
```

`sesame.mfa` also asks that the user's MFA is still good.

### No PHP on the machine?

Docker runs the suite on every supported PHP. The lines work as written in PowerShell, bash and zsh.

```
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

Work orders

## Have your agent cut it

Five work orders, ready for the coding agent you already use. Copy one, or run it straight in OpenCode, Claude Code, Codex or ChatGPT. Each one says which lock it is for, what the agent may not touch, and to ask at the counter before it guesses.

### Get started with SESAME

Which doorway the app is, which key it needs, and the smallest plan to sign people in with a verified identity.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent helping a Wayne State University developer add sign-in to the PHP app in this folder with SESAME. SESAME is the auth library of WELD, the Wayne State application stack: it hands the app a verified identity and leaves the session to the app. Every driver (cas, gateway, oauth2, oidc, and fake for local work) ends in the app's findOrImportUser(Identity), and switching driver is a config change.

Read first:
- https://sesame.apps.wayne.edu/llms.txt, the index of SESAME's pages, each one as Markdown. Fetch the pages you need; do not work from memory.
- This app's composer.json and composer.lock, its entry scripts or routes, and how it handles users and sessions today.
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs for sesame, and list_stack_rules for the auth chapter. If it is not connected, say so and work from the pages. How to add it: https://advisor.apps.wayne.edu/llms.txt

Task:
1. Say which kind of app this is: raw PHP, Laravel with Blade, or Laravel with Inertia and Vue. If it uses wayne-cas today, say so: SESAME has a shim for that.
2. Pick the driver: cas for browser sign-in against Academica, oidc for Entra sign-in, oauth2 for an API that takes bearer tokens, gateway only behind the auth gateway. Say why.
3. List the settings that driver needs and where each value comes from: the environment, or the Playbook.
4. Write the plan as numbered steps, starting with `composer require waynestate/sesame`. Change nothing yet.

Constraints:
- The app owns the session. SESAME never starts one and never sets a cookie; never ask it to.
- Fail closed: no default user, no fallback login, no bare header trusted as an identity.
- The fake driver is for local work only; it refuses to boot in production.
- Playbook is private on git.wayne.edu. If you get a 404, ask the Enterprise Applications team for read access. SESAME runs without it, from the environment alone.
- Never put a secret, a token or a key in a committed file.
- Ask me before you guess, and before you touch anything that deploys.

Output: the app kind, the driver and why, a settings table (setting, where it comes from, value or ask), the plan, then your questions.
```

### Or pick your doorway

### SESAME in a raw PHP app

The core wired by hand: config, the driver, a user resolver, the app's own session, and a logout that only a POST can ring.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent adding sign-in with SESAME to a raw PHP app (no framework) at Wayne State University. SESAME is the auth library of WELD, the Wayne State application stack.

Read first:
- https://sesame.apps.wayne.edu/llms.txt, then the README's Raw PHP section and the section for the driver this app needs.
- This app's composer.json, its front controller or entry scripts, and where it calls session_start().
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs for sesame, and list_stack_rules with permutation raw-php for the auth rules. If it is not connected, say so and work from the pages.

Task:
1. Install with `composer require waynestate/sesame`.
2. Build Config, the driver from DriverFactory, a UserResolver whose findOrImportUser(Identity) returns this app's user, and NativeSession, as the README shows; then call sesame() once.
3. Sign in with sesame_login(), read the user on protected pages with sesame_user(), and sign out with a POST form that carries sesame_logout_token().
4. Show me each file you would change before you write it.

Constraints:
- Start the session yourself, before SESAME. Set session.gc_maxlifetime to at least SESAME's idle timeout (7200 for the default 120 minutes), or PHP ends sessions after 24 quiet minutes.
- Logout is POST only, with the token. Never make it a GET link.
- Read the CAS base URL and the other settings from the environment, or with Setting::get; never hard-code them.
- Ask me before you guess, and before you touch anything that deploys.

Output: the changes as a diff per file, then how to try it locally with the fake driver, then your questions.
```

### SESAME in a Laravel app with Blade

The adapter the Laravel way: published config, a user resolver, the auth.sesame middleware and a CSRF-guarded sign-out.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent adding sign-in with SESAME to a Laravel app that renders its pages with Blade, at Wayne State University. SESAME is the auth library of WELD, the Wayne State application stack; its Laravel adapter is a discovered service provider that holds no rules of its own.

Read first:
- https://sesame.apps.wayne.edu/llms.txt, then the README's Laravel section and the section for the driver this app needs.
- This app's composer.json, config/, routes/web.php, its User model, .env.example and config/session.php.
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs for sesame, and list_stack_rules with permutation laravel-blade for the auth rules. If it is not connected, say so and work from the pages.

Task:
1. Install with `composer require waynestate/sesame` and publish the config with `php artisan vendor:publish --tag=sesame-config`.
2. Write the user resolver class and name it in SESAME_USER_RESOLVER. Add every setting the driver needs to .env.example, with no real values.
3. Guard the app's routes with auth.sesame (and sesame.mfa where MFA is required), and give the layout a sign-out form that posts to /logout with @csrf.
4. Show me the whole diff before you write it.

Constraints:
- Keep the adapter's sesame.login route name, even if the app takes the routes over.
- The session store must be shared (database or redis), never local files.
- The cas and oauth2 drivers need a PSR-18 client: Guzzle if the app has it, or a bound client.
- Ask me before you guess, and before you touch migrations or anything that deploys.

Output: the diff, the .env.example lines, then your questions.
```

### SESAME in a Laravel app with Inertia and Vue

The same adapter. The middleware guards each route before any Vue page is sent, and the user reaches Vue as a prop.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent adding sign-in with SESAME to a Laravel app with Inertia and Vue, at Wayne State University. SESAME is the auth library of WELD, the Wayne State application stack. The Laravel adapter guards routes on the server, so a page that needs a signed-in user is never sent to a guest.

Read first:
- https://sesame.apps.wayne.edu/llms.txt, then the README's Laravel section and the section for the driver this app needs.
- This app's composer.json, config/, routes/web.php, its User model, .env.example, and the Inertia middleware that shares props (often HandleInertiaRequests).
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs for sesame, and list_stack_rules with permutation laravel-inertia-vue for the auth rules. If it is not connected, say so and work from the pages.

Task:
1. Install with `composer require waynestate/sesame` and publish the config with `php artisan vendor:publish --tag=sesame-config`.
2. Write the user resolver class and name it in SESAME_USER_RESOLVER. Add every setting the driver needs to .env.example, with no real values.
3. Guard the Inertia routes with auth.sesame (and sesame.mfa where MFA is required). Share only the user fields the Vue pages need, never the whole identity.
4. Sign out with a POST to /logout that carries Laravel's CSRF token. Check how this app's Inertia setup sends that token before you rely on it.
5. Show me the whole diff before you write it.

Constraints:
- Authorization lives on the server. A Vue component may hide a button; it never decides who gets in.
- Keep the adapter's sesame.login route name. The session store must be shared (database or redis).
- Ask me before you guess, and before you touch migrations, the build, or anything that deploys.

Output: the diff, the .env.example lines, then your questions.
```

### Move a Laravel app from wayne-cas to SESAME

The shim keeps the old names, so the move is a mapping, not a rewrite. The agent lists every part before it swaps one.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent moving a Laravel app at Wayne State University from wayne-cas to SESAME, the auth library of WELD, the Wayne State application stack. SESAME ships a wayne-cas shim that keeps the old names and behaviour and logs once that it is a deprecated bridge.

Read first:
- https://sesame.apps.wayne.edu/llms.txt, then the README's Moving from wayne-cas section and its mapping table.
- This app's composer.json, config/wayne-cas.php if there is one, the User model, the routes and middleware that use WayneAuthenticate, and every call to auth()->login().
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs for sesame. If it is not connected, say so and work from the pages.

Task:
1. List every wayne-cas part the app uses, with the file and line, and the SESAME part that replaces it from the mapping table.
2. Plan the move: install with `composer require waynestate/sesame`, set sesame.cas.base_url from wayne-cas.root, make the User model implement WayneCasable with the ReadOnlyAttributes trait, and swap the middleware for the wayne.cas alias.
3. Say whether the app turns use2FA on. The shim does not carry it; it logs a warning and leaves 2FA to the app. Ask me what should replace it.
4. Change nothing yet.

Constraints:
- Keep the model's static findOrImportUser: the shim finds the user the way wayne-cas did.
- Remove wayne-cas only after the app signs in through SESAME, never in the same step.
- Ask me before you guess, and before you touch anything that deploys.

Output: the parts table (wayne-cas part, file and line, SESAME part), the plan as numbered steps, then your questions.
```

The agent reads the same pages you do: [llms.txt](https://sesame.apps.wayne.edu/llms.txt) lists them, [llms-full.txt](https://sesame.apps.wayne.edu/llms-full.txt) holds them in one file, and this page has a [Markdown twin](https://sesame.apps.wayne.edu/index.md). Nothing here phones home; the buttons only copy text or open the tool you picked. No key ever gets to keep the door, and no agent does either.

Posted by the door

## Shop rules

1. **The app owns the session.** SESAME never starts one and never touches a cookie.
2. **A new session ID at login,** before anything is stored in it.
3. **Fail closed.** A bad answer, bad XML or an empty user ends the login. Nothing falls back to a default user.
4. **No bare headers.** The gateway driver reads one signed assertion, from a trusted proxy, and each one works once.
5. **The fake key stays home.** It refuses to boot in production.
6. **No default locks.** Required settings have no defaults, so nobody is pointed at a server the app owner never chose.
7. **MFA fails closed.** No sign-in time, no MFA method, or one that's too old: the request is denied.
8. **Logout shuts the door.** `Cache-Control: no-store` and `Clear-Site-Data: "cache", "storage"`, so the back button shows nothing.

[Every practice, with its sources](https://github.com/waynestate/sesame/blob/main/BEST_PRACTICES.md)

### Hours

- **Idle**: 120 min
- **Absolute**: 720 min
- **MFA good for**: 8 h

Both session clocks are checked on every `user()` call. A stale session finds the door locked.

Cross-reference

## Re-key from wayne-cas

Coming from wayne-cas? You don't rewrite your auth. The shim keeps the old names and behaviour, and says once, in the log, that it's a bridge.

**wayne-cas names and the SESAME names that replace them**

| Old part (wayne-cas) | Fits (SESAME) |
| --- | --- |
| `wayne-cas.root` | `sesame.cas.base_url` |
| `auth.providers.users.model` | `auth.providers.users.model`, unchanged |
| `WayneCasable` trait | `implements WayneCasable` + `use ReadOnlyAttributes` |
| `WayneAuthenticate` middleware | the `wayne.cas` middleware alias |
| `auth()->login($user)` | `app(Sesame::class)->user()` |
| `use2FA` | not carried; the shim logs a warning once when it's on |

On the key ring

## SESAME in WELD

One part of Wayne State's app stack. Here's where it sits in a signed-in request, and who else is on the ring.

1. [**Drawbridge**](https://drawbridge.apps.wayne.edu/) signs the assertion at the edge
2. **SESAME** checks it and hands over an identity
3. [**HELMET**](https://helmet.apps.wayne.edu/) hardens the session cookie
4. [**WayneUI**](https://wayneui.apps.wayne.edu/) renders the signed-in page

- [WayneUI](https://wayneui.apps.wayne.edu/)the interface
- [CREED](https://creed.apps.wayne.edu/)the written standards
- [PROCTOR](https://proctor.apps.wayne.edu/)checks an app against them
- [Preflight](https://preflight.apps.wayne.edu/)shared CI
- SESAMEsigns the user in · you are here
- [HELMET](https://helmet.apps.wayne.edu/)headers and hardening
- [Pinstripe](https://pinstripe.apps.wayne.edu/)the coding standard
- [Sourdough](https://sourdough.apps.wayne.edu/)starters you feed
- [WELD](https://weld.apps.wayne.edu/)every part, already joined
- [Drawbridge](https://drawbridge.apps.wayne.edu/)the Citadel sign-in gateway
- [Squire](https://squire.apps.wayne.edu/)carries the release to Citadel
- [Rollcall](https://rollcall.apps.wayne.edu/)every app answers “present”
- [ADVISOR](https://advisor.apps.wayne.edu/)what applies to your app
- [Booster](https://booster.apps.wayne.edu/)keeps your PHP current
