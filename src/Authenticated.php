<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

/**
 * The identity is verified; this is who the user is.
 */
final class Authenticated extends LoginStep
{
    public function __construct(
        public readonly Identity $identity,
    ) {}
}
