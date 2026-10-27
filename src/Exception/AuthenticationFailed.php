<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Exception;

use RuntimeException;

/**
 * The identity could not be verified.
 *
 * The ticket was malformed, the provider refused it, or the provider's
 * answer could not be read. The message never carries the provider's raw
 * body.
 */
final class AuthenticationFailed extends RuntimeException
{
}
