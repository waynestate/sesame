<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

/**
 * A shared, ordered record of what the test doubles were asked to do.
 */
final class EventLog
{
    /** @var list<string> */
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }
}
