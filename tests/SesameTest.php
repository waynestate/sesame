<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\MfaResult;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\RecordingDriver;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;

final class SesameTest extends TestCase
{
    private function identity(): Identity
    {
        return new Identity(
            'jdoe',
            ['cn' => ['John Doe']],
            'fake',
            new DateTimeImmutable('2026-10-27T12:00:00+00:00'),
        );
    }

    private function config(array $overrides = []): Config
    {
        return new Config(...[
            'driver' => 'fake',
            'environment' => 'testing',
            'fakeUsername' => 'jdoe',
            ...$overrides,
        ]);
    }

    public function testLoginRegeneratesBeforeItStores(): void
    {
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
        );

        $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        $this->assertSame(
            [
                'login',
                'regenerate',
                'put:sesame.user',
                'put:sesame.identity',
                'put:sesame.signed_in_at',
                'put:sesame.last_seen',
            ],
            $log->events,
        );
    }

    public function testLoginStoresTheSessionTimestamps(): void
    {
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
        );

        $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        $now = $clock->now()->getTimestamp();

        $this->assertSame($now, $session->store['sesame.signed_in_at']);
        $this->assertSame($now, $session->store['sesame.last_seen']);
    }

    public function testLoginPassesTheExactIdentityAndReturnsTheResolverResult(): void
    {
        $identity = $this->identity();
        $log = new EventLog();
        $resolver = new RecordingResolver('the-user');
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($identity), null, $log),
            $resolver,
            new RecordingSession($log),
            $this->config(),
        );

        $user = $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        $this->assertSame($identity, $resolver->identity);
        $this->assertSame('the-user', $user);
    }

    public function testLoginWithANullResolverResultStoresNothing(): void
    {
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(null),
            $session,
            $this->config(),
        );

        $user = $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        $this->assertNull($user);
        $this->assertSame(['login'], $log->events);
        $this->assertSame([], $session->store);
    }

    public function testLoginStoresTheIdentityWithoutItsRawAttributes(): void
    {
        $identity = $this->identity();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($identity), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
        );

        $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        $stored = $session->store['sesame.identity'];

        $this->assertInstanceOf(Identity::class, $stored);
        $this->assertSame($identity->username, $stored->username);
        $this->assertSame($identity->driver, $stored->driver);
        $this->assertEquals($identity->authenticatedAt, $stored->authenticatedAt);
        $this->assertSame([], $stored->attributes);
    }

    public function testLoginReturnsARedirectUnchanged(): void
    {
        $redirect = new Redirect('http://idp.local/login?service=x');
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver($redirect, null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
        );

        $result = $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        $this->assertSame($redirect, $result);
        $this->assertSame(['login'], $log->events);
    }

    public function testUserReturnsTheStoredUser(): void
    {
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
        );

        $clock->advance(60);

        $this->assertSame('the-user', $sesame->user());
        $this->assertSame($clock->now()->getTimestamp(), $session->store['sesame.last_seen']);
    }

    public function testUserReturnsNullWhenNothingIsStored(): void
    {
        $log = new EventLog();
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            new RecordingSession($log),
            $this->config(),
        );

        $this->assertNull($sesame->user());
    }

    public function testUserSignsOutWhenTheIdleTimeoutHasPassed(): void
    {
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
        );

        $clock->advance(120 * 60 + 1);

        $this->assertNull($sesame->user());
        $this->assertSame(
            [
                'get:sesame.user',
                'get:sesame.signed_in_at',
                'get:sesame.last_seen',
                'forget:sesame.user',
                'forget:sesame.identity',
                'invalidate',
            ],
            $log->events,
        );
        $this->assertSame([], $session->store);
    }

    public function testUserStaysSignedInAtTheIdleBoundary(): void
    {
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
        );

        $clock->advance(120 * 60);

        $this->assertSame('the-user', $sesame->user());
    }

    public function testUserSignsOutWhenTheAbsoluteLifetimeHasPassed(): void
    {
        // The idle timeout is 120 minutes and the absolute lifetime is 60,
        // and the user makes a request every 10 minutes, so the idle timeout
        // never fires: only the absolute lifetime can end the session.
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(['sessionAbsoluteMinutes' => 60]),
            $clock,
        );

        for ($i = 0; $i < 6; $i++) {
            $clock->advance(10 * 60);
            $this->assertSame('the-user', $sesame->user(), 'Request '.($i + 1).' should still be signed in.');
        }

        $clock->advance(10 * 60);

        $this->assertNull($sesame->user());
        $this->assertSame([], $session->store);
    }

    public function testUserSignsOutWhenTheSessionHasNoTimestamps(): void
    {
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
        );

        $this->assertNull($sesame->user());
        $this->assertSame([], $session->store);
    }

    public function testLogoutInvalidatesBeforeTheIdpUrlIsBuilt(): void
    {
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
        $log->events = [];
        $driver = new RecordingDriver(new Authenticated($this->identity()), 'http://idp.local/logout', $log);
        $sesame = new Sesame($driver, new RecordingResolver(), $session, $this->config());

        $url = $sesame->logout('http://app.local/');

        $this->assertSame('http://idp.local/logout', $url);
        $this->assertSame(
            ['forget:sesame.user', 'forget:sesame.identity', 'invalidate', 'logoutUrl'],
            $log->events,
        );
    }

    public function testLogoutReturnsNullWhenTheDriverHasNoIdpUrl(): void
    {
        $log = new EventLog();
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            new RecordingSession($log),
            $this->config(),
        );

        $this->assertNull($sesame->logout('http://app.local/'));
    }

    public function testRequireMfaReturnsNoAuthTimeWhenThereIsNoUser(): void
    {
        $log = new EventLog();
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            new RecordingSession($log),
            $this->config(),
        );

        $result = $sesame->requireMfa();

        $this->assertFalse($result->satisfied);
        $this->assertSame(MfaResult::NO_AUTH_TIME, $result->reason);
    }

    public function testRequireMfaReturnsNoAuthTimeWhenTheSessionHasNoIdentity(): void
    {
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
        );

        $result = $sesame->requireMfa();

        $this->assertFalse($result->satisfied);
        $this->assertSame(MfaResult::NO_AUTH_TIME, $result->reason);
    }

    public function testRequireMfaIsSatisfiedWhenTheIdentityHasRecentMfa(): void
    {
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $session->put('sesame.identity', new Identity(
            'jdoe',
            [],
            'gateway',
            $clock->now(),
            $clock->now(),
            ['mfa'],
        ));
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
        );

        $result = $sesame->requireMfa();

        $this->assertTrue($result->satisfied);
        $this->assertSame(MfaResult::SATISFIED, $result->reason);
    }

    public function testRequireMfaIsTooOldWhenTheClockMovesPastTheMaxAge(): void
    {
        // The session timeouts are raised so that only the MFA age can end the
        // check: the user is still signed in, but their last MFA sign-in is
        // older than the default eight-hour max age.
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $session->put('sesame.identity', new Identity(
            'jdoe',
            [],
            'gateway',
            $clock->now(),
            $clock->now(),
            ['mfa'],
        ));
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(['sessionIdleMinutes' => 1000, 'sessionAbsoluteMinutes' => 1000]),
            $clock,
        );

        $clock->advance(28800 + 1);

        $result = $sesame->requireMfa();

        $this->assertFalse($result->satisfied);
        $this->assertSame(MfaResult::TOO_OLD, $result->reason);
    }

    public function testLoginStoresTheMfaFactsInTheSessionIdentity(): void
    {
        $identity = new Identity(
            'jdoe',
            ['cn' => ['John Doe']],
            'gateway',
            new DateTimeImmutable('2026-10-27T12:00:00+00:00'),
            new DateTimeImmutable('2026-10-27T11:59:00+00:00'),
            ['mfa', 'password'],
        );
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($identity), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
        );

        $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        $stored = $session->store['sesame.identity'];

        $this->assertInstanceOf(Identity::class, $stored);
        $this->assertEquals($identity->authTime, $stored->authTime);
        $this->assertSame($identity->amr, $stored->amr);
    }
}
