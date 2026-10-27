<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\EventLog;
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

    public function testLoginRegeneratesBeforeItStores(): void
    {
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
        );

        $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        $this->assertSame(
            ['login', 'regenerate', 'put:sesame.user', 'put:sesame.identity'],
            $log->events,
        );
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
        );

        $user = $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        $this->assertSame($identity, $resolver->identity);
        $this->assertSame('the-user', $user);
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
        );

        $result = $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        $this->assertSame($redirect, $result);
        $this->assertSame(['login'], $log->events);
    }

    public function testUserReturnsTheStoredUser(): void
    {
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
        );

        $this->assertSame('the-user', $sesame->user());
    }

    public function testUserReturnsNullWhenNothingIsStored(): void
    {
        $log = new EventLog();
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            new RecordingSession($log),
        );

        $this->assertNull($sesame->user());
    }

    public function testLogoutInvalidatesBeforeTheIdpUrlIsBuilt(): void
    {
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
        $log->events = [];
        $driver = new RecordingDriver(new Authenticated($this->identity()), 'http://idp.local/logout', $log);
        $sesame = new Sesame($driver, new RecordingResolver(), $session);

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
        );

        $this->assertNull($sesame->logout('http://app.local/'));
    }
}
