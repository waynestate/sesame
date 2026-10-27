<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

/**
 * Send the user to this URL to continue the login.
 */
final class Redirect extends LoginStep
{
    public function __construct(
        public readonly string $url,
    ) {
    }
}
