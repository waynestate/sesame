<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Drivers\FakeDriver;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;

final class FunctionsTest extends TestCase
{
    public function testSesameReturnsTheInstanceItWasGiven(): void
    {
        $instance = new Sesame(
            new FakeDriver('local', 'jdoe', []),
            new RecordingResolver(),
            new RecordingSession(new EventLog()),
        );

        $this->assertSame($instance, sesame($instance));
        $this->assertSame($instance, sesame());
    }
}
