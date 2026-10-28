<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel\Support;

/**
 * A stand-in for the app's user model that has no user for the identity.
 *
 * The shim must turn this into an AuthorizationException.
 */
class NullUser
{
    /**
     * @param  string  $accessId
     * @return mixed
     */
    public static function findOrImportUser($accessId)
    {
        return null;
    }
}
