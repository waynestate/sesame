<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel\Support;

use Waynestate\Sesame\Identity;
use Waynestate\Sesame\UserResolver;

/**
 * The app's user resolver for the guard tests.
 *
 * It resolves every identity to the GuardTestUser whose id is 1 and whose
 * username is the identity's, so the tests can tell the resolved model apart
 * from a user the guard learned some other way.
 */
class GuardTestUserResolver implements UserResolver
{
    public function findOrImportUser(Identity $identity): mixed
    {
        return new GuardTestUser(1, $identity->username);
    }
}
