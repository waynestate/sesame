<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel\Support;

use Waynestate\Sesame\Identity;
use Waynestate\Sesame\UserResolver;

/**
 * The app's user resolver for the Laravel tests.
 *
 * It always resolves to the same user, so the tests can assert on it without
 * a database.
 */
class TestUserResolver implements UserResolver
{
    public function findOrImportUser(Identity $identity): mixed
    {
        return 'the-user';
    }
}
