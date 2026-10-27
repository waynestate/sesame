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
class UserResolver implements \Waynestate\Sesame\UserResolver
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
}
