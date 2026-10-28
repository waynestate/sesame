<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Exception;

use RuntimeException;

/**
 * The identity is signed in, but it does not have the required
 * authentication context.
 *
 * Thrown by sesame_require_context() when the check fails. It carries the
 * context id, so the app can react to the specific context that is missing.
 */
final class AuthContextNotSatisfied extends RuntimeException
{
    public function __construct(
        public readonly string $context,
    ) {
        parent::__construct(self::messageFor($context));
    }

    /**
     * The human form of the failure, shared by the raw PHP exception and the
     * Laravel middleware so the two surfaces never drift apart.
     */
    public static function messageFor(string $context): string
    {
        return sprintf(
            'The signed-in identity does not have the required authentication context "%s".',
            $context,
        );
    }
}
