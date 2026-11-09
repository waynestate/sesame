<?php

declare(strict_types=1);

namespace Waynestate\Sesame\WayneCas;

use Illuminate\Auth\Access\AuthorizationException;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Identity;

/**
 * The wayne-cas user resolver.
 *
 * It finds the user the way wayne-cas did: the model named in
 * auth.providers.users.model, through its static findOrImportUser, with the
 * verified username as the access ID. When the model has no user for the
 * access ID, it throws the same AuthorizationException wayne-cas threw.
 *
 * The interface is named in full in the implements clause, because this
 * class keeps the wayne-cas short name and PHP will not let a class share a
 * short name with a use import.
 */
class UserResolver implements \Waynestate\Sesame\UserResolver, \Waynestate\Sesame\RefreshesUser
{
    public function findOrImportUser(Identity $identity): mixed
    {
        Deprecation::logOnce();

        $model = config('auth.providers.users.model');

        if (! is_string($model) || $model === '' || ! class_exists($model) || ! method_exists($model, 'findOrImportUser')) {
            throw new InvalidConfig('The wayne-cas shim needs auth.providers.users.model to be a class with a static findOrImportUser method.');
        }

        $user = $model::findOrImportUser($identity->username);

        if ($user === null) {
            throw new AuthorizationException();
        }

        return $user;
    }

    /**
     * Look the stored user up again, through the same model wayne-cas found
     * it in. A plain find, never findOrImportUser: a refresh must not import
     * a user back who was deleted after the sign-in.
     *
     * An Eloquent model is looked up through its query builder (the shim
     * calls query()-> find() on the model), because find() is not a method
     * declared on the model: it reaches the builder through __callStatic, so
     * method_exists() cannot see it. Any other model must declare a static
     * find().
     *
     * @param  mixed  $user  The user the session holds.
     * @return mixed The current user, or null when it no longer exists.
     */
    public function refreshUser(mixed $user): mixed
    {
        Deprecation::logOnce();

        if (! is_object($user) || ! method_exists($user, 'getKey')) {
            return $user;
        }

        $model = config('auth.providers.users.model');

        $message = 'The wayne-cas shim needs auth.providers.users.model to be an Eloquent model or a class with a static find method.';

        if (! is_string($model) || $model === '' || ! class_exists($model)) {
            throw new InvalidConfig($message);
        }

        if (is_subclass_of($model, 'Illuminate\Database\Eloquent\Model')) {
            return $model::query()->find($user->getKey());
        }

        if (method_exists($model, 'find')) {
            return $model::find($user->getKey());
        }

        throw new InvalidConfig($message);
    }
}
