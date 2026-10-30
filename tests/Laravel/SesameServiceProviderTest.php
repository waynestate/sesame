<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Drivers\OAuth2Driver;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Drivers\Psr16ReplayGuard;
use Waynestate\Sesame\Drivers\ReplayGuard;
use Waynestate\Sesame\Laravel\LaravelSession;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Laravel\Support\TestUserResolver;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\FakeHttpClient;
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

    public function testTheMissingResolverErrorNamesTheWayneCasResolver(): void
    {
        // A wayne-cas app moving to SESAME gets told which class to set.
        $app = $this->app;
        self::assertNotNull($app);
        $app['config']->set('sesame.user_resolver', null);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage(
            'Set sesame.user_resolver to the class that resolves a verified identity to the app\'s user (wayne-cas apps: \Waynestate\Sesame\WayneCas\UserResolver::class).',
        );

        $app->make(UserResolver::class);
    }

    public function testResolvingSesameAndCheckingTheSessionNeverBuildsTheDriver(): void
    {
        // An app whose cas.service_url is not https cannot build its driver.
        // Resolving the facade and checking the session must not try to, so
        // such an app can still boot and answer signed-out requests.
        $app = $this->app;
        self::assertNotNull($app);
        $app['config']->set('sesame.driver', 'cas');
        $app['config']->set('sesame.cas.base_url', 'https://cas.local/cas');
        $app['config']->set('sesame.cas.service_url', 'http://app.local/login');
        $app->instance(ClientInterface::class, new FakeHttpClient());

        $sesame = $app->make(Sesame::class);

        self::assertNull($sesame->user());
    }

    public function testStartingALoginStillRaisesTheHttpsCheck(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $app['config']->set('sesame.driver', 'cas');
        $app['config']->set('sesame.cas.base_url', 'https://cas.local/cas');
        $app['config']->set('sesame.cas.service_url', 'http://app.local/login');
        $app->instance(ClientInterface::class, new FakeHttpClient());

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage(
            'The config key "cas.service_url" must be an absolute https:// URL, got "http://app.local/login".',
        );

        $app->make(Sesame::class)->login(new ServerRequest('GET', 'http://app.local/login'));
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

    public function testItResolvesAnOAuth2DriverWithTheConfiguredKeySetStore(): void
    {
        // The default store is pointed elsewhere; the driver must still keep
        // its key set in the store named in sesame.oauth2.jwks_store.
        $app = $this->app;
        self::assertNotNull($app);
        $app['config']->set('cache.default', 'file');
        $app['config']->set('sesame.driver', 'oauth2');
        $app['config']->set('sesame.oauth2.issuer', 'https://login.microsoftonline.com/00000000-0000-0000-0000-000000000000/v2.0');
        $app['config']->set('sesame.oauth2.jwks_url', 'https://login.microsoftonline.com/00000000-0000-0000-0000-000000000000/discovery/v2.0/keys');
        $app['config']->set('sesame.oauth2.audience', '00000000-0000-0000-0000-000000000000');
        $app['config']->set('sesame.oauth2.jwks_store', 'array');
        $app->instance(ClientInterface::class, new FakeHttpClient());

        self::assertInstanceOf(OAuth2Driver::class, $app->make(Driver::class));
    }
}
