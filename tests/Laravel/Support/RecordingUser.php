<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel\Support;

/**
 * A stand-in for the app's user model, for the wayne-cas shim tests.
 *
 * The shim calls the model's static findOrImportUser() with the identity's
 * username. This model records the call and always returns a user.
 */
class RecordingUser
{
    /** @var list<string> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public static function findOrImportUser($accessId)
    {
        self::$calls[] = $accessId;

        return 'the-imported-user';
    }
}
