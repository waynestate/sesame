<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Illuminate\Auth\AuthManager;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Drivers\CasDriver;
use Waynestate\Sesame\Laravel\SesameGuard;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Laravel\Support\GuardTestUser;
use Waynestate\Sesame\Tests\Laravel\Support\GuardTestUserProvider;
use Waynestate\Sesame\Tests\Laravel\Support\GuardTestUserResolver;

/**
 * The sesame guard driver, registered under auth.driver = "sesame".
 *
 * A user Laravel's own guard already knows wins for the request; when there
 * is none, the answer comes from the SESAME session.
 */
final class SesameGuardTest extends LaravelTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];

        // The web guard becomes the sesame guard, over a provider the
        // framework does not know (the package ships no user provider of
        // its own) and the resolver that turns an identity into one of the
        // two test users.
        $users = [
            new GuardTestUser(1, 'jdoe'),
            new GuardTestUser(2, 'asmith'),
        ];

        $config->set('auth.guards.web.driver', 'sesame');
        $config->set('auth.providers.users.driver', 'guard-test');
        $config->set('auth.providers.users.users', $users);
        $config->set('sesame.user_resolver', GuardTestUserResolver::class);

        $app->resolving(AuthManager::class, static function (AuthManager $auth) use ($users): void {
            $auth->provider('guard-test', static fn(): GuardTestUserProvider => new GuardTestUserProvider($users));
        });
    }

    public function testAGuestReadsAsNull(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        self::assertInstanceOf(SesameGuard::class, $app['auth']->guard('web'));
        self::assertNull($app['auth']->user());
    }

    public function testASesameSignInReadsThroughAuthUser(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        $this->get('/login')->assertRedirect('/');

        $user = $app['auth']->user();

        self::assertInstanceOf(GuardTestUser::class, $user);
        self::assertSame(1, $user->id);
        self::assertSame('jdoe', $user->username);
    }

    public function testTheResolvedUserIsCachedForTheRequest(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        $this->get('/login')->assertRedirect('/');

        $first = $app['auth']->user();

        self::assertInstanceOf(GuardTestUser::class, $first);

        /** @var \Illuminate\Contracts\Session\Session $store */
        $store = $app['session.store'];

        // Wipe SESAME's keys behind the guard's back: the second read must
        // come from the guard's copy, not the session.
        $store->forget('sesame.user');
        $store->forget('sesame.identity');
        $store->forget('sesame.signed_in_at');
        $store->forget('sesame.last_seen');

        self::assertSame($first, $app['auth']->user());
    }

    public function testALaravelLoginWinsForTheRequest(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        $this->get('/login')->assertRedirect('/');

        self::assertInstanceOf(GuardTestUser::class, $app['auth']->onceUsingId(2));

        $user = $app['auth']->user();

        self::assertInstanceOf(GuardTestUser::class, $user);
        self::assertSame(2, $user->id);
        self::assertSame('asmith', $user->username);
    }

    public function testLogoutClearsBothSessions(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        $this->get('/login')->assertRedirect('/');

        self::assertInstanceOf(GuardTestUser::class, $app['auth']->user());

        $app['auth']->guard('web')->logout();

        self::assertNull($app['auth']->user());
        self::assertNull($app->make(Sesame::class)->user());
    }

    public function testTheGuardBootsWithTheCasDriver(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        config([
            'sesame.driver' => 'cas',
            'sesame.cas.base_url' => 'https://cas.test/cas',
            'sesame.cas.service_url' => 'https://app.test/login',
        ]);

        self::assertInstanceOf(CasDriver::class, $app->make(Driver::class));
        self::assertInstanceOf(SesameGuard::class, $app['auth']->guard('web'));
        self::assertNull($app['auth']->user());
    }
}
