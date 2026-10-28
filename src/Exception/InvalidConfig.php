<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Exception;

use RuntimeException;

/**
 * The config is missing a key, or a key holds the wrong shape.
 */
final class InvalidConfig extends RuntimeException {}
