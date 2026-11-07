---
title: Moving from wayne-cas
description: Apps that use wayne-cas can move to SESAME without rewriting their auth, through Booster's cas-to-sesame hop or by hand.
---

# Moving from wayne-cas

Apps that use wayne-cas can move to SESAME without rewriting their auth. Open sesame, indeed: the same door, a new lock. The easy way is Booster's `cas-to-sesame` hop; the by-hand path is below it.

> [!NOTE]
> Still on the older Wayne Auth SSO cookie (`new WayneAuth(...)`, `authenticateSSO()`) rather than wayne-cas? See [Moving off Wayne Auth](moving-off-wayne-auth.md): a bridge keeps those calls and moves the sign-in to CAS with a one-line change.

## The easy way: Booster's `cas-to-sesame` hop

Booster plans the hop with `--sesame`, and leaves it out with a warning when the plan ends below PHP 8.3, which SESAME needs. It lands just before the hop from Laravel 10 to 11, and while PHP is still below 8.3 the PHP hops go first. What the hop does:

- Writes `config/sesame.php` from the app's `config/wayne-cas.php` when the old `root` is a plain string (the old root becomes the fallback for `cas.base_url`), or from SESAME's published defaults when the app has no wayne-cas config at all. `user_resolver` defaults to the compat resolver.
- Moves the CAS middleware off the global `web` group and onto the app's web routes, with the `wayne.cas` alias registered against SESAME's `WayneAuthenticate`.
- Points the web guard at the `sesame` driver and the users provider at `eloquent`, adding a model key when the provider has none.
- Rewrites user models that use `WayneCasable` as a trait to `implements WayneCasable` plus `use ReadOnlyAttributes`.
- Swaps the Composer requirement to `waynestate/sesame:^0.4` (SESAME is on Packagist, so no extra repository is needed; Booster 1.10.0 pins the 0.4 line, so take the [0.5.0 steps](../upgrading.md) after the hop) and drops the `wayne-cas` package, any `wayne-cas` path entry and its VCS repository entries.
- Removes `config/wayne-cas.php` when nothing in the app reads its config keys any more; while anything still does, the file stays and a manual note names the readers.
- Flags the dead `DUO_*` settings in example env files.

> [!NOTE]
> It never runs app code: the old files are read with conservative patterns, and anything it cannot move safely comes back as a manual note that names the file.

## By hand

> [!WARNING]
> The shim keeps the wayne-cas names and behavior, and it says once (in the log) that it is a deprecated bridge.

### The mapping

The mapping:

| wayne-cas | SESAME |
|---|---|
| `wayne-cas.root` | `sesame.cas.base_url` |
| `auth.providers.users.model` | `auth.providers.users.model` (unchanged) |
| `WayneCasable` trait | `implements WayneCasable` + `use ReadOnlyAttributes` |
| `WayneAuthenticate` middleware | the `wayne.cas` middleware alias |
| `auth()->user()` through the `wayneCas` guard | `auth()->user()` with the guard pointed at `sesame`, or `app(Sesame::class)->user()` |
| `use2FA` | not supported; the shim logs a warning when it is on |

### The model

The model. wayne-cas found the user through the model's static `findOrImportUser`, with the verified username as the access ID. The shim keeps that, and the model keeps its wayne-cas shape:

```php
class User extends Model implements WayneCasable
{
    use Notifiable, ReadOnlyAttributes;

    public static function findOrImportUser($accessId)
    {
        return static::firstOrCreate(['username' => $accessId]);
    }

    public function getReadOnlyAttributes($persistedAttributes = []): array
    {
        return [
            'cn' => $persistedAttributes['cn'] ?? null,
        ];
    }
}
```

The one change a wayne-cas model needs: `getReadOnlyAttributes` goes from protected to public, keeping its `$persistedAttributes` parameter. The interface and the trait share that signature, so a model that satisfies one satisfies both.

The `WayneCasable` interface and the `ReadOnlyAttributes` trait are in the `Waynestate\Sesame\WayneCas` namespace.

### The middleware

The middleware. wayne-cas's `WayneAuthenticate` middleware is kept as the `wayne.cas` alias. It checks the SESAME session instead of the Laravel auth guard, and it sends unauthenticated users to the `sesame.login` route. The AJAX 403 is kept, because wayne-cas returned it. It is safe in the global `web` group (it lets the `sesame.login`, `sesame.callback` and `sesame.logout` routes straight through), though putting it on the app's routes is still the recommended setup; an app that takes the routes over with `sesame.routes = false` must keep those names or exclude its routes.

### The guard

The guard. wayne-cas apps that read the user through `auth()->user()` can keep that call: point the app's guard at the `sesame` driver (see [Laravel](laravel.md)) and `auth()->user()`, `Auth::check()` and `auth()->logout()` all work against the SESAME session. Without the guard, the equivalent is `app(Sesame::class)->user()`.

### The service URL

The service URL. wayne-cas built the service URL from the current request. SESAME takes `sesame.cas.service_url` from config; the Laravel adapter defaults it to the app's `/login` route when it is not set.

### `use2FA`

`use2FA`. wayne-cas's `use2FA` runs a Duo challenge the shim does not carry. When the app still has `wayne-cas.use2FA` switched on, the shim logs a warning once and leaves the 2FA to the app.

## What Booster leaves for you

- Duo 2FA is not carried. If the app had `use2FA` enabled, re-enable two-factor in the app itself, and drop the `DUO_HOSTNAME`, `DUO_IKEY`, `DUO_SKEY` and `DUO_AKEY` settings: they do nothing under SESAME.
- Log in through the pre-production host before you ship, and check the session timeout, the Access ID attribute and Duo there.
- `auth()->user()` now comes from SESAME's guard and is reloaded every request (the compat resolver looks the user up again through `auth.providers.users.model`), so check that signed-in pages and any Gate or policy checks still work.

## Related

- [Laravel](laravel.md)
- [Which driver for my app?](which-driver.md)
- [The cas driver](driver-cas.md)
- [Running off Citadel](running-off-citadel.md)
- [Moving off Wayne Auth](moving-off-wayne-auth.md)
