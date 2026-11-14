<?php

declare(strict_types=1);

namespace Waynestate\Sesame\WayneCas;

use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Laravel\ModelUserResolver;

/**
 * The wayne-cas user resolver.
 *
 * It finds the user the way wayne-cas did: the model named in
 * auth.providers.users.model, through its static findOrImportUser, with the
 * verified username as the access ID. When the model has no user for the
 * access ID, it throws the same AuthorizationException wayne-cas threw.
 *
 * It now wraps ModelUserResolver and goes away in 0.8.0.
 */
class UserResolver extends ModelUserResolver
{
    public function findOrImportUser(Identity $identity): mixed
    {
        Deprecation::logOnce();

        return parent::findOrImportUser($identity);
    }

    /**
     * @param  mixed  $user  The user the session holds.
     * @return mixed The current user, or null when it no longer exists.
     */
    public function refreshUser(mixed $user): mixed
    {
        Deprecation::logOnce();

        return parent::refreshUser($user);
    }

    /**
     * Who the error messages are about: the shim, word for word as before.
     */
    protected function subject(): string
    {
        return 'The wayne-cas shim';
    }
}
