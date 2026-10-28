<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Exception;

use RuntimeException;

/**
 * The fake driver was asked to run in production.
 *
 * It refuses rather than fall back: a fake identity in production is a hole,
 * not a convenience.
 */
final class FakeDriverInProduction extends RuntimeException {}
