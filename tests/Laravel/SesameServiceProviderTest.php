<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Laravel\LaravelSession;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Laravel\Support\TestUserResolver;
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
}
