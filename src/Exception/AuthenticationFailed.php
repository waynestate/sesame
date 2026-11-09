<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Exception;

use RuntimeException;

/**
 * The identity could not be verified.
 *
 * The ticket was malformed, the provider refused it, or the provider's
 * answer could not be read. The message never carries the provider's raw
 * body. When the provider could not be reached at all, the exception is the
 * ProviderUnavailable subclass, so an app can tell "try again in a minute"
 * from "this sign-in was refused" and still catch both here.
 */
class AuthenticationFailed extends RuntimeException {}
