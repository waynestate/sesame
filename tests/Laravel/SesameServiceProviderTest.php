<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Psr\Clock\ClockInterface;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Laravel\LaravelSession;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Laravel\Support\TestUserResolver;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\UserResolver;

/**
 * The service provider's wiring.
 *
 * These tests assert that the provider merges its config, registers the
 * routes, aliases the middleware and binds the container, so an app that
 * publishes nothing still gets a working SESAME.
 */
final class SesameServiceProviderTest extends LaravelTestCase
{
    public function testItMergesTheConfig(): void
    {
        $this->assertSame('fake', config('sesame.driver'));
        $this->assertSame('jdoe', config('sesame.fake.username'));
        $this->assertSame(TestUserResolver::class, config('sesame.user_resolver'));
        $this->assertTrue(config('sesame.routes'));
        $this->assertSame(120, config('sesame.session.idle_minutes'));
        $this->assertSame(720, config('sesame.session.absolute_minutes'));
    }

    public function testItRegistersTheLoginAndLogoutRoutes(): void
    {
        $routes = $this->app['router']->getRoutes();

        $this->assertTrue($routes->hasNamedRoute('sesame.login'));
        $this->assertTrue($routes->hasNamedRoute('sesame.logout'));
    }

    public function testItAliasesTheMiddleware(): void
    {
        $aliases = $this->app['router']->getMiddleware();

        $this->assertArrayHasKey('auth.sesame', $aliases);
        $this->assertArrayHasKey('wayne.cas', $aliases);
    }

    public function testItBindsTheContainer(): void
    {
        $this->assertInstanceOf(Sesame::class, $this->app->make(Sesame::class));
        $this->assertInstanceOf(Driver::class, $this->app->make(Driver::class));
        $this->assertInstanceOf(TestUserResolver::class, $this->app->make(UserResolver::class));
        $this->assertInstanceOf(LaravelSession::class, $this->app->make(LaravelSession::class));
    }

    public function testItUsesTheAppsClockWhenThereIsOne(): void
    {
        // A clock set far from "now" proves the binding was used: the system
        // clock would store a different timestamp.
        $clock = new FakeClock('2020-01-01T00:00:00+00:00');
        $this->app->instance(ClockInterface::class, $clock);

        $this->get('/login')->assertRedirect('/');

        $this->assertSame(
            $clock->now()->getTimestamp(),
            $this->app->make(LaravelSession::class)->get('sesame.signed_in_at'),
        );
    }
}
