<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use RuntimeException;

/**
 * Thrown by TestableWayneAuth where the real bridge sends its 401 with
 * {"signedIn":false} and exits, so a test can see that the script request
 * got the 401 and not a redirect.
 */
final class UnauthorizedSent extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('401 {"signedIn":false}');
    }
}
