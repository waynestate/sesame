<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A clock that starts at a fixed moment and moves only when the test moves
 * it, so the session timeout tests never wait for real time.
 */
final class FakeClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $start = '2026-10-27T12:00:00+00:00')
    {
        $this->now = new DateTimeImmutable($start);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify('+'.$seconds.' seconds');
    }
}
