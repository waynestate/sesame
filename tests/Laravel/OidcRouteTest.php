<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Drivers\OidcDriver;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\FakeOpenIdProvider;

/**
 * The oidc driver through the Laravel adapter: GET /login sends the user to
 * the provider, GET /login/callback finishes the sign-in, and the
 * sesame.mfa reauth path asks for a step-up.
 */
final class OidcRouteTest extends LaravelTestCase
{
    private FakeOpenIdProvider $op;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $clock = new FakeClock();
        $this->op = new FakeOpenIdProvider($clock);

        $app['config']->set('sesame.driver', 'oidc');
        $app['config']->set('sesame.oidc.issuer', FakeOpenIdProvider::ISSUER);
        $app['config']->set('sesame.oidc.client_id', FakeOpenIdProvider::CLIENT_ID);
        $app['config']->set('sesame.oidc.client_secret', FakeOpenIdProvider::CLIENT_SECRET);
        $app['config']->set('sesame.oidc.redirect_uri', FakeOpenIdProvider::REDIRECT_URI);
        $app['config']->set('cache.default', 'array');
        $app->instance(ClientInterface::class, $this->op->client());
        $app->instance(ClockInterface::class, $clock);
    }

    public function testTheCallbackRouteIsRegisteredForOidc(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        self::assertTrue($app['router']->getRoutes()->hasNamedRoute('sesame.callback'));
        self::assertInstanceOf(OidcDriver::class, $app->make(Driver::class));
        self::assertSame(FakeOpenIdProvider::REDIRECT_URI, $app->make(Driver::class)->serviceUrl());
    }

    public function testTheRedirectUriDefaultsToTheCallbackRoute(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $app['config']->set('sesame.oidc.redirect_uri', null);
        $app->forgetInstance(Driver::class);

        self::assertStringEndsWith('/login/callback', (string) $app->make(Driver::class)->serviceUrl());
    }

    public function testLoginAndCallbackSignTheUserIn(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        $start = $this->get('/login');
        $start->assertRedirect();
        $location = (string) $start->headers->get('Location');
        self::assertStringStartsWith(FakeOpenIdProvider::AUTHORIZE_URL . '?', $location);
        self::assertStringNotContainsString('prompt=', $location);
        self::assertStringNotContainsString('max_age=', $location);

        $callback = $this->op->authorize($location);

        $this->get('/login/callback?' . http_build_query($callback))->assertRedirect('/');

        $sesame = $app->make(Sesame::class);
        self::assertSame('the-user', $sesame->user());
        $identity = $app['session.store']->get('sesame.identity');
        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame('ab1234', $identity->username);
    }

    public function testACheckedReturnToIsHonoured(): void
    {
        $location = (string) $this->get('/login?return_to=' . rawurlencode('https://app.example.test/reports'))->headers->get('Location');

        $this->get('/login/callback?' . http_build_query($this->op->authorize($location)))
            ->assertRedirect('https://app.example.test/reports');
    }

    public function testAForeignReturnToIsIgnored(): void
    {
        $location = (string) $this->get('/login?return_to=' . rawurlencode('https://evil.example.test/'))->headers->get('Location');

        $this->get('/login/callback?' . http_build_query($this->op->authorize($location)))
            ->assertRedirect('/');
    }

    public function testMfaReauthAsksTheProviderForAStepUp(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $app['config']->set('sesame.mfa.on_failure', 'reauth');
        $app['router']->get('/protected', fn() => 'ok')->middleware(['web', 'sesame.mfa']);

        $this->get('/protected')->assertRedirect(route('sesame.login', ['step_up' => 1]));

        $location = (string) $this->get('/login?step_up=1')->headers->get('Location');
        self::assertStringContainsString('prompt=login', $location);
    }
}
