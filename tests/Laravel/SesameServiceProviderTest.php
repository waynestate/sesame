<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Drivers\Psr16ReplayGuard;
use Waynestate\Sesame\Drivers\ReplayGuard;
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
        self::assertSame('fake', config('sesame.driver'));
        self::assertSame('jdoe', config('sesame.fake.username'));
        self::assertSame(TestUserResolver::class, config('sesame.user_resolver'));
        self::assertTrue(config('sesame.routes'));
        self::assertSame(120, config('sesame.session.idle_minutes'));
        self::assertSame(720, config('sesame.session.absolute_minutes'));
        self::assertSame(28800, config('sesame.mfa.max_age'));
        self::assertSame('deny', config('sesame.mfa.on_failure'));
    }

    public function testItRegistersTheLoginAndLogoutRoutes(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $routes = $app['router']->getRoutes();

        self::assertTrue($routes->hasNamedRoute('sesame.login'));
        self::assertTrue($routes->hasNamedRoute('sesame.logout'));
    }

    public function testItAliasesTheMiddleware(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $aliases = $app['router']->getMiddleware();

        self::assertArrayHasKey('auth.sesame', $aliases);
        self::assertArrayHasKey('sesame.mfa', $aliases);
        self::assertArrayHasKey('wayne.cas', $aliases);
    }

    public function testItBindsTheContainer(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        self::assertInstanceOf(Sesame::class, $app->make(Sesame::class));
        self::assertInstanceOf(Driver::class, $app->make(Driver::class));
        self::assertInstanceOf(TestUserResolver::class, $app->make(UserResolver::class));
        self::assertInstanceOf(LaravelSession::class, $app->make(LaravelSession::class));
    }

    public function testItUsesTheAppsClockWhenThereIsOne(): void
    {
        // A clock set far from "now" proves the binding was used: the system
        // clock would store a different timestamp.
        $clock = new FakeClock('2020-01-01T00:00:00+00:00');
        $app = $this->app;
        self::assertNotNull($app);
        $app->instance(ClockInterface::class, $clock);

        $this->get('/login')->assertRedirect('/');

        self::assertSame(
            $clock->now()->getTimestamp(),
            $app->make(LaravelSession::class)->get('sesame.signed_in_at'),
        );
    }

    public function testItResolvesAReplayGuard(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $app['config']->set('cache.default', 'array');

        self::assertInstanceOf(Psr16ReplayGuard::class, $app->make(ReplayGuard::class));
    }

    public function testItUsesTheConfiguredReplayStore(): void
    {
        // The default store is pointed elsewhere; the guard must still write
        // to the store named in sesame.gateway.replay_store.
        $app = $this->app;
        self::assertNotNull($app);
        $app['config']->set('cache.default', 'file');
        $app['config']->set('sesame.gateway.replay_store', 'array');

        $guard = $app->make(ReplayGuard::class);
        self::assertFalse($guard->seen('jti-1', (new DateTimeImmutable())->modify('+240 seconds')));

        $key = 'sesame.jti.' . hash('sha256', 'jti-1');
        self::assertTrue($app['cache']->store('array')->has($key));
    }
}
