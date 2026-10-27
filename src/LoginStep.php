<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

/**
 * The result of a driver's login attempt.
 *
 * Sealed by convention: the only two shapes are Redirect and Authenticated,
 * and both are final.
 */
abstract class LoginStep
{
}
