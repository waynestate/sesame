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

- Writes `config/sesame.php` from the app's `config/wayne-cas.php` when the old `root` is a plain string (the old root becomes the fallback for `cas.base_url`), or from SESAME's published defaults when the app has no wayne-cas config at all. `user_resolver` defaults to `\Waynestate\Sesame\Laravel\ModelUserResolver::class`.
- Moves the CAS middleware off the global `web` group and onto the app's web routes, with the `auth.sesame` middleware.
- Points the web guard at the `sesame` driver and the users provider at `eloquent`, adding a model key when the provider has none.
- Rewrites user models so they keep their static `findOrImportUser` and use the app's own copy of the `ReadOnlyAttributes` trait.
- Swaps the Composer requirement to `waynestate/sesame:^0.7` (SESAME is on Packagist, so no extra repository is needed; Booster 1.14.0 and later write `^0.7`. Booster 1.12.0 and 1.13.0 wrote `^0.6`, so an app they hopped takes the [0.7.0 step](../upgrading.md) to move to `^0.7`, and an app hopped by an earlier Booster got `^0.4`, so take the 0.5.0, 0.6.0 and 0.7.0 steps) and drops the `wayne-cas` package, any `wayne-cas` path entry and its VCS repository entries.
- Removes `config/wayne-cas.php` when nothing in the app reads its config keys any more; while anything still does, the file stays and a manual note names the readers.
- Flags the dead `DUO_*` settings in example env files.

> [!NOTE]
> It never runs app code: the old files are read with conservative patterns, and anything it cannot move safely comes back as a manual note that names the file.

## By hand

SESAME 0.8.0 has no compatibility layer, so the app moves straight to SESAME's own names.

### The mapping

The mapping:

| wayne-cas | SESAME 0.8.0 |
|---|---|
| `wayne-cas.root` | `sesame.cas.base_url` |
| `auth.providers.users.model` | `auth.providers.users.model` (unchanged) |
| the model's `WayneCasable` trait | the model keeps its static `findOrImportUser`; `config/sesame.php` sets `user_resolver` to `\Waynestate\Sesame\Laravel\ModelUserResolver::class` |
| the model's read-only attributes | the app's own copy of the `ReadOnlyAttributes` trait (`app/Models/Concerns/ReadOnlyAttributes.php`) |
| `WayneAuthenticate` middleware | `auth.sesame` on the app's route groups (a guest gets a redirect to the login route, a JSON request a 401, not wayne-cas's 403) |
| `auth()->user()` through the `wayneCas` guard | `auth()->user()` with the guard pointed at `sesame`, or `app(Sesame::class)->user()` |
| `use2FA` | not carried; require MFA with the `sesame.mfa` middleware or leave it to Duo at the IdP |

### The model

The model. wayne-cas found the user through the model's static `findOrImportUser`, with the verified username as the access ID. The model keeps that method, and `config/sesame.php` points `user_resolver` at `\Waynestate\Sesame\Laravel\ModelUserResolver::class`, which finds the user through it:

```php
use App\Models\Concerns\ReadOnlyAttributes;

class User extends Model
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

The read-only attributes come from the app's own copy of the trait, in `app/Models/Concerns/ReadOnlyAttributes.php` ([The ReadOnlyAttributes trait](#the-readonlyattributes-trait) below). `getReadOnlyAttributes` is public, keeping its `$persistedAttributes` parameter.

`ModelUserResolver` also looks the signed-in user up again on every request (a plain find by primary key, never an import, so a deleted account stays deleted). An Eloquent model works as it is, with or without JSON session serialization, so the app needs no resolver of its own.

### The middleware

The middleware. Protect the app's route groups with the `auth.sesame` middleware. It checks the SESAME session instead of the Laravel auth guard, and it sends unauthenticated users to the `sesame.login` route: a guest gets a redirect to the login route, and a JSON request gets a 401 (wayne-cas returned a 403). It belongs on the app's route groups, not the global `web` group, because it does not let the `sesame.login`, `sesame.callback` and `sesame.logout` routes through; an app that takes the routes over with `sesame.routes = false` must keep those names or exclude its routes.

### The guard

The guard. Apps that read the user through `auth()->user()` keep that call: point the app's guard at the `sesame` driver (see [Laravel](laravel.md)) and `auth()->user()`, `Auth::check()` and `auth()->logout()` all work against the SESAME session. Without the guard, the equivalent is `app(Sesame::class)->user()`.

### The service URL

The service URL. wayne-cas built the service URL from the current request. SESAME takes `sesame.cas.service_url` from config; the Laravel adapter defaults it to the app's `/login` route when it is not set.

### `use2FA`

`use2FA`. wayne-cas's `use2FA` runs a Duo challenge that SESAME does not carry. Require MFA on the routes that need it with the `sesame.mfa` middleware (see [Requiring MFA](requiring-mfa.md)), or leave 2FA to Duo at the identity provider. Drop the `DUO_HOSTNAME`, `DUO_IKEY`, `DUO_SKEY` and `DUO_AKEY` settings: they do nothing under SESAME.

<a id="leaving-the-shim"></a>

## Leaving the shim (apps on 0.7.x)

SESAME 0.8.0 has removed the shim: the `Waynestate\Sesame\WayneCas` namespace (`UserResolver`, `WayneAuthenticate`, `WayneCasable`, `ReadOnlyAttributes` and `Deprecation`) and the `wayne.cas` middleware alias are gone. An app still on the shim does these steps while it is on 0.7.x, before `composer update` to 0.8.0:

1. **`config/sesame.php`.** Make `user_resolver` `\Waynestate\Sesame\Laravel\ModelUserResolver::class`. It does exactly what the shim did: it finds the user through `auth.providers.users.model` via its static `findOrImportUser`, and it looks the stored user up again on every request.
2. **Routes.** Swap the middleware from `'wayne.cas'` to `'auth.sesame'`. Two differences: a guest gets a redirect to the login route (a JSON request a 401) instead of the 403 wayne-cas returned, and `auth.sesame` belongs on the app's route groups, not the global `web` group, because it does not let the `sesame.*` routes through.
3. **The model.** Keep its static `findOrImportUser`, and drop the old `WayneCasable` interface from the class declaration.
4. **`ReadOnlyAttributes`.** An app that uses the `ReadOnlyAttributes` trait copies it from [The ReadOnlyAttributes trait](#the-readonlyattributes-trait) below into the app (for example `app/Models/Concerns/ReadOnlyAttributes.php`) before taking 0.8.0, which removes it with the rest of the namespace.
5. **Verify.** Sign in, and the log no longer carries the deprecation line.

## The ReadOnlyAttributes trait

SESAME 0.8.0 no longer ships the `ReadOnlyAttributes` trait, so an app keeps this copy of it.

```php
<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Class ReadOnlyAttributes
 * @package App\Models\Concerns
 *
 * This trait manages the "extra" attributes that the User model will have, typically fields retrieved
 * from the Banner database that are immutable.  To use this trait, you must implement the
 * abstract method `getReadOnlyAttributes` which should return a key=>value pair array of attributes and
 * attributeValues that will be set as read-only on the model.  How you determine the readOnly attributes
 * is entirely up to you.
 */
trait ReadOnlyAttributes
{
    /** @var array<int, string> */
    private $readOnlyAttributes = [];

    /**
     * Get an array of attribute => attributeValue pairs that will be read-only for the Eloquent model.
     * Attributes can be native to the model (i.e. part of the database table).
     *
     * The same signature the WayneCasable interface declares, so a model
     * that implements the interface and uses this trait satisfies both.
     *
     * @param array<string, mixed> $persistedAttributes
     * @return array<string, mixed>
     */
    abstract public function getReadOnlyAttributes($persistedAttributes = []): array;

    /**
     * Get the attributes that have been changed since last sync.
     * We exclude the read-only attributes so they won't trigger a database call if changed.
     *
     * @return array<string, mixed>
     */
    public function getDirty()
    {
        $dirty = [];

        $attributes = array_filter($this->attributes, function ($key) {
            return ! in_array($key, (array) $this->readOnlyAttributes, true); // @phpstan-ignore cast.useless (defensive cast: the property is written through an untyped setter)
        }, ARRAY_FILTER_USE_KEY);

        foreach ($attributes as $key => $value) {
            if (! array_key_exists($key, $this->original)) {
                $dirty[$key] = $value;
            } elseif ($value !== $this->original[$key]) {
                if ((method_exists($this, 'originalIsNumericallyEquivalent') && ! $this->originalIsNumericallyEquivalent($key))
                    || (method_exists($this, 'originalIsEquivalent') && ! $this->originalIsEquivalent($key))) { // @phpstan-ignore function.alreadyNarrowedType (guard kept for older Laravel versions without originalIsEquivalent)
                    $dirty[$key] = $value;
                }
            }
        }

        return $dirty;
    }

    /**
     * @param Builder<static> $query
     * @param array<string, mixed> $attributes
     * @return void
     */
    protected function insertAndSetId(Builder $query, $attributes)
    {
        // We exclude the read-only attributes so they aren't persisted
        $attributes = array_diff($attributes, $this->readOnlyAttributes);

        // Then we let the parent method do all the hard work
        parent::insertAndSetId($query, $attributes);

        // parent::insertAndSetId() has just set the newly generated key on the
        // instance. Carry it into $attributes, because setRawAttributes(..., true)
        // below replaces the whole attribute set and would otherwise discard it,
        // leaving the model with a null key immediately after save().
        //
        // This is latent for the usage the README documents, where the access ID
        // is the primary key and is assigned before save, so it is already in
        // $attributes. It bites whenever the key is generated by the database,
        // which is the ordinary Eloquent case.
        $attributes[$this->getKeyName()] = $this->getKey();

        // Now we refresh the read-only attributes and values
        $readOnlyAttributes = $this->getReadOnlyAttributes($attributes);

        // merge them with the native attributes
        $attributes = array_merge($attributes, $readOnlyAttributes);

        // set them on the instance
        $this->setRawAttributes($attributes, true);

        // and finally register the read-only attributes to prevent persistence in future database calls
        $this->setReadOnlyAttributes(array_keys($readOnlyAttributes));
    }

    /**
     * @param array<string, mixed> $attributes
     * @param string|\UnitEnum|null $connection
     * @return static
     */
    public function newFromBuilder($attributes = [], $connection = null)
    {
        // Here we grab the attribute => attributeValue pairs the developer defines for this model
        $readOnlyAttributes = $this->getReadOnlyAttributes((array) $attributes); // @phpstan-ignore cast.useless (defensive cast: the parameter is untyped at runtime)

        // merge them with the native attributes
        $attributes = array_merge((array) $attributes, $readOnlyAttributes); // @phpstan-ignore cast.useless (defensive cast: the parameter is untyped at runtime)

        // let the parent method do the work of hydrating an instance with them
        $model = parent::newFromBuilder($attributes, $connection);

        // and finally register the read-only attributes to prevent persistence in future database calls
        $model->setReadOnlyAttributes(array_keys($readOnlyAttributes));

        // oh yeah, we also have to return the freshly hydrated model. duh!
        return $model;
    }

    /**
     * @param array<int, string> $readOnlyAttributes
     * @return void
     */
    public function setReadOnlyAttributes($readOnlyAttributes)
    {
        $this->readOnlyAttributes = $readOnlyAttributes;
    }

}
```

## What Booster leaves for you

- Duo 2FA is not carried. If the app had `use2FA` enabled, re-enable two-factor in the app itself, and drop the `DUO_HOSTNAME`, `DUO_IKEY`, `DUO_SKEY` and `DUO_AKEY` settings: they do nothing under SESAME.
- Log in through the pre-production host before you ship, and check the session timeout, the Access ID attribute and Duo there.
- `auth()->user()` now comes from SESAME's guard and is reloaded every request (`ModelUserResolver` looks the user up again through `auth.providers.users.model`), so check that signed-in pages and any Gate or policy checks still work.

## Related

- [Laravel](laravel.md)
- [Which driver for my app?](which-driver.md)
- [The cas driver](driver-cas.md)
- [Running off Citadel](running-off-citadel.md)
- [Moving off Wayne Auth](moving-off-wayne-auth.md)
