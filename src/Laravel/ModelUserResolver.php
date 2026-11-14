<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Laravel;

use Illuminate\Auth\Access\AuthorizationException;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\RefreshesUser;
use Waynestate\Sesame\UserResolver;

/**
 * SESAME's own user resolver, for a model with a static findOrImportUser.
 *
 * It finds the user through the model named in auth.providers.users.model,
 * calling the model's static findOrImportUser with the verified username as
 * the access ID. When the model has no user for the access ID, it throws an
 * AuthorizationException. Open sesame, with nothing else in the door.
 */
class ModelUserResolver implements UserResolver, RefreshesUser
{
    public function findOrImportUser(Identity $identity): mixed
    {
        $model = config('auth.providers.users.model');

        if (! is_string($model) || $model === '' || ! class_exists($model) || ! method_exists($model, 'findOrImportUser')) {
            throw new InvalidConfig($this->subject() . ' needs auth.providers.users.model to be a class with a static findOrImportUser method.');
        }

        $user = $model::findOrImportUser($identity->username);

        if ($user === null) {
            throw new AuthorizationException();
        }

        return $user;
    }

    /**
     * Look the stored user up again, through the same model that found it.
     * A plain find, never findOrImportUser: a refresh must not import a user
     * back who was deleted after the sign-in.
     *
     * When the session hands the user back as an array (Laravel 13's JSON
     * session serialization), the array is looked up by its primary key
     * through the Eloquent model named in auth.providers.users.model, and
     * null comes back when the account is gone or the array carries no key.
     * An array whose model is not an Eloquent model is passed through
     * unchanged, as is any other non-object input.
     *
     * An Eloquent model is looked up through its query builder
     * (query()->find() on the model), because find() is not a method
     * declared on the model: it reaches the builder through __callStatic, so
     * method_exists() cannot see it. Any other model must declare a static
     * find().
     *
     * @param  mixed  $user  The user the session holds.
     * @return mixed The current user, or null when it no longer exists.
     */
    public function refreshUser(mixed $user): mixed
    {
        $model = config('auth.providers.users.model');

        if (is_array($user)) {
            if (is_string($model) && $model !== '' && class_exists($model) && is_subclass_of($model, 'Illuminate\Database\Eloquent\Model')) {
                $keyName = (new $model())->getKeyName();
                $key = $user[$keyName] ?? null;

                if ((is_string($key) && $key !== '') || is_int($key)) {
                    return $model::query()->find($key);
                }

                return null;
            }

            return $user;
        }

        if (! is_object($user) || ! method_exists($user, 'getKey')) {
            return $user;
        }

        $message = $this->subject() . ' needs auth.providers.users.model to be an Eloquent model or a class with a static find method.';

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

    /**
     * Who the error messages are about.
     */
    protected function subject(): string
    {
        return 'The SESAME model resolver';
    }
}
