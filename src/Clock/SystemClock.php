<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * The system clock, for when the app has no clock of its own.
 *
 * An app that wants a different clock (a test clock, a clock it can set)
 * passes one to Sesame instead.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
