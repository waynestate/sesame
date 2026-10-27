<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Waynestate\Sesame\Identity;
use Waynestate\Sesame\UserResolver;

/**
 * A UserResolver that remembers the identity it was given and answers with a
 * fixed user.
 */
final class RecordingResolver implements UserResolver
{
    public ?Identity $identity = null;

    public function __construct(
        private readonly mixed $user = 'the-user',
    ) {
    }

    public function findOrImportUser(Identity $identity): mixed
    {
        $this->identity = $identity;

        return $this->user;
    }
}
