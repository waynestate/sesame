<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Exception;

use RuntimeException;
use Waynestate\Sesame\MfaResult;

/**
 * The identity is signed in, but its MFA is not good enough.
 *
 * Thrown by sesame_require_mfa() when the check fails. The result it carries
 * holds the stable reason and the human message, so the app can react to the
 * specific cause.
 */
final class MfaNotSatisfied extends RuntimeException
{
    public function __construct(
        public readonly MfaResult $result,
    ) {
        parent::__construct($result->message());
    }
}
