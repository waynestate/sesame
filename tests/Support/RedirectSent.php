<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use RuntimeException;

/**
 * Thrown by TestableWayneAuth where the real bridge sends a Location header
 * and exits, so a test can read where the browser would have gone.
 */
final class RedirectSent extends RuntimeException
{
    public function __construct(
        public readonly string $url,
    ) {
        parent::__construct('Redirect to ' . $url);
    }
}
