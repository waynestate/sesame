<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Psr\Log\LoggerInterface;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\ArrayLogger;

/**
 * The Laravel adapter wires the app's PSR-3 logger into the core.
 *
 * These tests drive the real /login route with the fake driver and assert
 * that a bound logger receives the sign-in event, that sesame.log=false
 * keeps the logger quiet, and that the app still signs in when no logger
 * is bound at all.
 */
final class SesameLoggingTest extends LaravelTestCase
{
    public function testABoundLoggerReceivesTheLoginEvent(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $logger = new ArrayLogger();
        $app->instance(LoggerInterface::class, $logger);

        $this->get('/login')->assertRedirect('/');

        self::assertCount(1, $logger->records);
        $record = $logger->records[0];
        self::assertSame('info', $record['level']);
        self::assertSame('sesame.login', $record['message']);
        self::assertSame('login', $record['context']['event']);
        self::assertSame('fake', $record['context']['driver']);
        self::assertSame('jdoe', $record['context']['username']);
        self::assertArrayHasKey('at', $record['context']);
    }

    public function testTurningLoggingOffKeepsTheBoundLoggerQuiet(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $app['config']->set('sesame.log', false);
        $logger = new ArrayLogger();
        $app->instance(LoggerInterface::class, $logger);

        $this->get('/login')->assertRedirect('/');

        self::assertCount(0, $logger->records);
        // The sign-in itself still happened; only the log was skipped.
        self::assertSame('the-user', $app->make(Sesame::class)->user());
    }

    public function testTheAppStillSignsInWhenNoLoggerIsBound(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        // The framework aliases LoggerInterface to the log manager in its
        // core container aliases, so bound() reads true out of the box;
        // drop the alias and any binding so this test exercises the
        // no-logger path.
        $app->offsetUnset(LoggerInterface::class);
        $aliases = (new \ReflectionProperty($app, 'aliases'))->getValue($app);
        unset($aliases[LoggerInterface::class]);
        (new \ReflectionProperty($app, 'aliases'))->setValue($app, $aliases);
        self::assertFalse($app->bound(LoggerInterface::class));

        $this->get('/login')->assertRedirect('/');

        self::assertSame('the-user', $app->make(Sesame::class)->user());
    }
}
