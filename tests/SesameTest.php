<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\MfaResult;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\RevocationStore;
use Waynestate\Sesame\Revocation\InMemoryRevocationStore;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\ArrayLogger;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\RecordingDriver;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;
use Waynestate\Sesame\Tests\Support\RefreshingResolver;

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

    /**
     * @param array<string, mixed> $overrides
     */
    private function config(array $overrides = []): Config
    {
        return new Config(...[
            'driver' => 'fake',
            'environment' => 'testing',
            'fakeUsername' => 'jdoe',
            'fakeAllowed' => true,
            ...$overrides,
        ]);
    }

    /**
     * A facade over the recording driver with its own session and the given
     * clock, optionally wired to the given revocation store.
     *
     * @return array{0: Sesame, 1: RecordingSession}
     */
    private function appWithStore(FakeClock $clock, ?RevocationStore $store): array
    {
        $log = new EventLog();
        $session = new RecordingSession($log);

        return [
            new Sesame(
                new RecordingDriver(new Authenticated($this->identity()), null, $log),
                new RecordingResolver(),
                $session,
                $this->config(),
                $clock,
                null,
                null,
                $store,
            ),
            $session,
        ];
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

        self::assertSame(
            [
                'login',
                'regenerate',
                'put:sesame.user',
                'put:sesame.identity',
                'put:sesame.signed_in_at',
                'put:sesame.last_seen',
                'put:sesame.session_ref',
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

        self::assertSame($now, $session->store['sesame.signed_in_at']);
        self::assertSame($now, $session->store['sesame.last_seen']);
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

        self::assertSame($identity, $resolver->identity);
        self::assertSame('the-user', $user);
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

        self::assertNull($user);
        self::assertSame(['login'], $log->events);
        self::assertSame([], $session->store);
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

        $raw = $session->store['sesame.identity'];

        // Plain data, never the object, so a JSON session keeps it (0.10.1).
        self::assertIsArray($raw);
        $stored = Identity::fromArray($raw);
        self::assertSame($identity->username, $stored->username);
        self::assertSame($identity->driver, $stored->driver);
        self::assertEquals($identity->authenticatedAt, $stored->authenticatedAt);
        self::assertSame([], $stored->attributes);
        self::assertSame([], $raw['attributes']);
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

        self::assertSame($redirect, $result);
        self::assertSame(['login'], $log->events);
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

        self::assertSame('the-user', $sesame->user());
        self::assertSame($clock->now()->getTimestamp(), $session->store['sesame.last_seen']);
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

        self::assertNull($sesame->user());
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

        self::assertNull($sesame->user());
        self::assertSame(
            [
                'get:sesame.user',
                'get:sesame.signed_in_at',
                'get:sesame.last_seen',
                // Every check reads the identity, so a tampered one signs
                // out (0.10.1).
                'get:sesame.identity',
                'forget:sesame.user',
                'forget:sesame.identity',
                'invalidate',
            ],
            $log->events,
        );
        self::assertSame([], $session->store);
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

        self::assertSame('the-user', $sesame->user());
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
            self::assertSame('the-user', $sesame->user(), 'Request ' . ($i + 1) . ' should still be signed in.');
        }

        $clock->advance(10 * 60);

        self::assertNull($sesame->user());
        self::assertSame([], $session->store);
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

        self::assertNull($sesame->user());
        self::assertSame([], $session->store);
    }

    public function testARefreshingResolverSeesAChangedUserFromTheNextRequest(): void
    {
        $stored = new class {
            public string $key = 'jdoe';
            public string $role = 'member';
        };
        $resolver = new RefreshingResolver($stored);
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            $resolver,
            $session,
            $this->config(),
        );

        self::assertSame($stored, $sesame->login(new ServerRequest('GET', 'http://app.local/login')));

        $changed = new class {
            public string $key = 'jdoe';
            public string $role = 'admin';
        };
        $resolver->replace($changed);

        self::assertSame($changed, $sesame->user());
        self::assertSame($changed, $session->store['sesame.user']);
    }

    public function testARefreshingResolverSignsOutWhenTheUserIsDeleted(): void
    {
        $stored = new class {
            public string $key = 'jdoe';
            public string $role = 'member';
        };
        $resolver = new RefreshingResolver($stored);
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            $resolver,
            $session,
            $this->config(),
        );

        $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        $resolver->forget('jdoe');

        self::assertNull($sesame->user());
        self::assertSame([], $session->store);
        self::assertNull($sesame->user(), 'The sign-out sticks.');
    }

    public function testAResolverWithoutTheRefreshInterfaceKeepsHandingBackTheStoredCopy(): void
    {
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver('the-user'),
            $session,
            $this->config(),
        );

        $sesame->login(new ServerRequest('GET', 'http://app.local/login'));
        $log->events = [];

        $first = $sesame->user();
        self::assertSame('the-user', $first);

        $second = $sesame->user();
        self::assertSame($first, $second, 'The stored copy is handed back on every request.');
        self::assertNotContains('put:sesame.user', $log->events, 'The stored copy is never rewritten.');
    }

    public function testTheLogoutTokenIsStableForASessionAndChecked(): void
    {
        $session = new RecordingSession(new EventLog());
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, new EventLog()),
            new RecordingResolver(),
            $session,
            $this->config(),
        );

        self::assertFalse($sesame->isLogoutToken('anything'), 'A session without a token accepts nothing.');
        self::assertFalse($sesame->isLogoutToken(''));

        $token = $sesame->logoutToken();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertSame($token, $sesame->logoutToken());
        self::assertSame($token, $session->store[Sesame::LOGOUT_TOKEN_KEY]);
        self::assertTrue($sesame->isLogoutToken($token));
        self::assertFalse($sesame->isLogoutToken(strtoupper($token)));
        self::assertFalse($sesame->isLogoutToken(null));
        self::assertFalse($sesame->isLogoutToken([$token]));

        $sesame->logout('http://app.local/');

        self::assertFalse($sesame->isLogoutToken($token), 'Logout spends the token with the session.');
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

        self::assertSame('http://idp.local/logout', $url);
        self::assertSame(
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

        self::assertNull($sesame->logout('http://app.local/'));
    }

    public function testLoggingOutOfOneAppEndsTheOtherAppsSessionThroughTheSharedStore(): void
    {
        // Two apps on one domain, each with its own session, sharing one
        // revocation store: logging out of one ends the other's session too.
        $clock = new FakeClock();
        $store = new InMemoryRevocationStore();
        [$appA, $sessionA] = $this->appWithStore($clock, $store);
        [$appB, $sessionB] = $this->appWithStore($clock, $store);
        $request = new ServerRequest('GET', 'http://app.local/login');

        self::assertSame('the-user', $appA->login($request));
        self::assertSame('the-user', $appB->login($request));
        $signedInAt = $clock->now()->getTimestamp();

        $appA->logout('http://app.local/');

        self::assertNull($appA->user());
        self::assertSame([], $sessionA->store);
        self::assertSame($signedInAt, $store->revokedBefore('jdoe'), 'The logout records the cutoff for the username.');
        self::assertNull($appB->user(), 'B signed in before the cutoff, so B is signed out on its next request.');
        self::assertSame([], $sessionB->store, 'B\'s session is cleared.');

        // A sign-in after the cutoff is untouched.
        $clock->advance(60);
        self::assertSame('the-user', $appB->login($request));
        self::assertSame('the-user', $appB->user());
    }

    public function testWithoutAStoreALogoutLeavesTheOtherAppSignedIn(): void
    {
        $clock = new FakeClock();
        [$appA] = $this->appWithStore($clock, null);
        [$appB] = $this->appWithStore($clock, null);
        $request = new ServerRequest('GET', 'http://app.local/login');

        self::assertSame('the-user', $appA->login($request));
        self::assertSame('the-user', $appB->login($request));

        $appA->logout('http://app.local/');

        self::assertNull($appA->user());
        self::assertSame('the-user', $appB->user(), 'Without a shared store, A\'s logout touches only A\'s session.');
    }

    public function testATimeoutSignOutWritesNoCutoff(): void
    {
        // Timeouts end only the session that timed out: they must not write
        // a cutoff, or a quiet user would be signed out of every app.
        $clock = new FakeClock();
        $store = new InMemoryRevocationStore();
        [$appA] = $this->appWithStore($clock, $store);
        [$appB] = $this->appWithStore($clock, $store);
        $request = new ServerRequest('GET', 'http://app.local/login');

        self::assertSame('the-user', $appA->login($request));
        self::assertSame('the-user', $appB->login($request));

        // B stays active while A goes quiet past the 120-minute idle timeout.
        $clock->advance(60 * 60);
        self::assertSame('the-user', $appB->user());
        $clock->advance(61 * 60);

        self::assertNull($appA->user(), 'A has been idle for 121 minutes, past the timeout.');
        self::assertNull($store->revokedBefore('jdoe'), 'The timeout wrote no cutoff.');
        self::assertSame('the-user', $appB->user(), 'B was seen 61 minutes ago and is still within the timeout.');
    }

    public function testSignOutLocalWritesACutoffLikeLogoutDoes(): void
    {
        $clock = new FakeClock();
        $store = new InMemoryRevocationStore();
        [$appA] = $this->appWithStore($clock, $store);
        [$appB] = $this->appWithStore($clock, $store);
        $request = new ServerRequest('GET', 'http://app.local/login');

        self::assertSame('the-user', $appA->login($request));
        self::assertSame('the-user', $appB->login($request));

        $appA->signOutLocal();

        self::assertSame($clock->now()->getTimestamp(), $store->revokedBefore('jdoe'));
        self::assertNull($appB->user());
    }

    public function testAThrowingStoreDoesNotBreakLogout(): void
    {
        // A store that throws must never change what logout() returns or
        // throws: the failure is logged once, without the store's message.
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $store = new class implements RevocationStore {
            public function revokeBefore(string $username, int $at): void
            {
                throw new RuntimeException('cache down: password=hunter2');
            }

            public function revokedBefore(string $username): ?int
            {
                throw new RuntimeException('cache down: password=hunter2');
            }
        };
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), 'http://idp.local/logout', $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
            $logger,
            null,
            $store,
        );

        self::assertSame('the-user', $sesame->login(new ServerRequest('GET', 'http://app.local/login')));

        self::assertSame('http://idp.local/logout', $sesame->logout('http://app.local/'));
        self::assertNull($sesame->user());

        $failures = $logger->withMessage('sesame.revocation_failed');
        self::assertCount(1, $failures);
        self::assertSame('warning', $failures[0]['level']);
        self::assertSame([
            'event' => 'revocation_failed',
            'driver' => 'fake',
            'at' => $clock->now()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ], $failures[0]['context']);
        self::assertStringNotContainsString('hunter2', (string) json_encode($logger->records), 'The store\'s message never reaches the log.');
    }

    public function testAThrowingStoreDoesNotBreakTheUserCheck(): void
    {
        // A store whose read throws counts as no cutoff: the session stays
        // signed in, and the failure is logged once.
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $store = new class implements RevocationStore {
            public function revokeBefore(string $username, int $at): void
            {
                throw new RuntimeException('cache down: password=hunter2');
            }

            public function revokedBefore(string $username): ?int
            {
                throw new RuntimeException('cache down: password=hunter2');
            }
        };
        $log = new EventLog();
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            new RecordingSession($log),
            $this->config(),
            $clock,
            $logger,
            null,
            $store,
        );

        self::assertSame('the-user', $sesame->login(new ServerRequest('GET', 'http://app.local/login')));
        self::assertSame('the-user', $sesame->user(), 'A failed read counts as no cutoff.');

        self::assertCount(1, $logger->withMessage('sesame.revocation_failed'));
        self::assertStringNotContainsString('hunter2', (string) json_encode($logger->records), 'The store\'s message never reaches the log.');
    }

    public function testCheckingTheSessionNeverBuildsTheDriver(): void
    {
        // The facade takes a factory as well as a driver, so an app can
        // defer the driver's construction until it is needed.
        $log = new EventLog();
        $session = new RecordingSession($log);
        $built = 0;
        $factory = function () use (&$built, $log): RecordingDriver {
            $built++;

            return new RecordingDriver(new Authenticated($this->identity()), 'http://idp.local/logout', $log);
        };
        $sesame = new Sesame($factory, new RecordingResolver(), $session, $this->config());

        self::assertNull($sesame->user());
        self::assertSame(0, $built, 'Checking a session never builds the driver.');

        // Logout still points at the identity provider for an empty session,
        // because the single sign-on session may still be alive.
        self::assertSame('http://idp.local/logout', $sesame->logout('http://app.local/'));
        self::assertSame(1, $built, 'Logging out builds the driver.');

        self::assertSame('the-user', $sesame->login(new ServerRequest('GET', 'http://app.local/login')));
        self::assertSame(1, $built, 'The built driver is kept.');
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

        self::assertFalse($result->satisfied);
        self::assertSame(MfaResult::NO_AUTH_TIME, $result->reason);
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

        self::assertFalse($result->satisfied);
        self::assertSame(MfaResult::NO_AUTH_TIME, $result->reason);
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

        self::assertTrue($result->satisfied);
        self::assertSame(MfaResult::SATISFIED, $result->reason);
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

        self::assertFalse($result->satisfied);
        self::assertSame(MfaResult::TOO_OLD, $result->reason);
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

        $raw = $session->store['sesame.identity'];

        self::assertIsArray($raw);
        $stored = Identity::fromArray($raw);
        self::assertEquals($identity->authTime, $stored->authTime);
        self::assertSame($identity->amr, $stored->amr);
    }

    public function testLoginStoresTheAuthContextsInTheSessionIdentity(): void
    {
        $identity = new Identity(
            'jdoe',
            ['cn' => ['John Doe']],
            'gateway',
            new DateTimeImmutable('2026-10-27T12:00:00+00:00'),
            new DateTimeImmutable('2026-10-27T11:59:00+00:00'),
            ['mfa'],
            ['c1', 'c10'],
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

        $raw = $session->store['sesame.identity'];

        self::assertIsArray($raw);
        self::assertSame(['c1', 'c10'], Identity::fromArray($raw)->authContexts());
    }

    public function testHasAuthContextIsFalseWhenThereIsNoUser(): void
    {
        $log = new EventLog();
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            new RecordingSession($log),
            $this->config(),
        );

        self::assertFalse($sesame->hasAuthContext('c1'));
    }

    public function testHasAuthContextIsFalseWhenTheSessionHasNoIdentity(): void
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

        self::assertFalse($sesame->hasAuthContext('c1'));
    }

    public function testHasAuthContextIsTrueWhenTheIdentityHasTheContext(): void
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
            ['c2', 'c10'],
        ));
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
        );

        self::assertTrue($sesame->hasAuthContext('c2'));
        self::assertTrue($sesame->hasAuthContext('c10'));
        // c1 is a prefix of c10, but the check is membership, not a prefix
        // match.
        self::assertFalse($sesame->hasAuthContext('c1'));
    }

    public function testSessionStatusIsSignedOutWithoutAUser(): void
    {
        $log = new EventLog();
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            new RecordingSession($log),
            $this->config(),
        );

        self::assertSame(['signedIn' => false], $sesame->sessionStatus());
    }

    public function testSessionStatusListsTheSessionFactsWhenSignedIn(): void
    {
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
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

        $status = $sesame->sessionStatus();

        if ($status['signedIn'] !== true) {
            self::fail('The session is signed in.');
        }

        self::assertSame(
            [
                'signedIn',
                'username',
                'driver',
                'authenticatedAt',
                'authTime',
                'amr',
                'idleExpiresAt',
                'absoluteExpiresAt',
                'logoutToken',
                'user',
            ],
            array_keys($status),
        );
        self::assertTrue($status['signedIn']);
        self::assertSame('jdoe', $status['username']);
        self::assertSame('fake', $status['driver']);
        self::assertSame('2026-10-27T12:00:00+00:00', $status['authenticatedAt']);
        self::assertNull($status['authTime']);
        self::assertSame([], $status['amr']);
        self::assertNull($status['user']);
    }

    public function testSessionStatusReportsTimesInUtc(): void
    {
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        // The provider reported the sign-in in its own offset; the answer
        // must still be UTC.
        $identity = new Identity(
            'jdoe',
            [],
            'fake',
            new DateTimeImmutable('2026-10-27T17:30:00+05:00'),
            new DateTimeImmutable('2026-10-27T17:29:00+05:00'),
        );
        $session->put('sesame.identity', $identity);
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($identity), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
        );

        $status = $sesame->sessionStatus();

        if ($status['signedIn'] !== true) {
            self::fail('The session is signed in.');
        }

        self::assertSame('2026-10-27T12:30:00+00:00', $status['authenticatedAt']);
        self::assertSame('2026-10-27T12:29:00+00:00', $status['authTime']);
    }

    public function testSessionStatusReportsTheExpiryTimesFromTheClock(): void
    {
        $clock = new FakeClock();
        $start = $clock->now()->getTimestamp();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
        $session->put('sesame.signed_in_at', $start);
        $session->put('sesame.last_seen', $start);
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(['sessionIdleMinutes' => 30, 'sessionAbsoluteMinutes' => 240]),
            $clock,
        );

        // The poll itself counts as activity, so the idle expiry runs from
        // the poll, while the absolute expiry still runs from the sign-in.
        $clock->advance(90);

        $status = $sesame->sessionStatus();

        if ($status['signedIn'] !== true) {
            self::fail('The session is signed in.');
        }

        self::assertSame(
            (new DateTimeImmutable('@' . ($start + 90 + 30 * 60)))->format(DATE_ATOM),
            $status['idleExpiresAt'],
        );
        self::assertSame(
            (new DateTimeImmutable('@' . ($start + 240 * 60)))->format(DATE_ATOM),
            $status['absoluteExpiresAt'],
        );
    }

    public function testSessionStatusNeverContainsRawAttributes(): void
    {
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $session->put('sesame.session_ref', '0123456789abcdef');
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
        );

        $json = json_encode($sesame->sessionStatus());

        self::assertIsString($json);
        self::assertStringNotContainsString('John Doe', $json);
        self::assertStringNotContainsString('0123456789abcdef', $json);
    }

    public function testSessionStatusAppliesTheDescribeUserClosure(): void
    {
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
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

        $received = null;
        $status = $sesame->sessionStatus(function (mixed $user) use (&$received): array {
            $received = $user;

            return ['name' => 'John Doe', 'admin' => true];
        });

        if ($status['signedIn'] !== true) {
            self::fail('The session is signed in.');
        }

        self::assertSame('the-user', $received);
        self::assertSame(['name' => 'John Doe', 'admin' => true], $status['user']);
    }

    public function testSessionStatusRejectsADescribeUserClosureThatReturnsANonArray(): void
    {
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
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

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The describeUser closure must return an array.');

        $sesame->sessionStatus(fn(): string => 'not-an-array');
    }

    public function testSessionStatusSignsOutAnExpiredSession(): void
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

        self::assertSame(['signedIn' => false], $sesame->sessionStatus());
        self::assertArrayNotHasKey('sesame.user', $session->store);
        self::assertSame([], $session->store);
    }

    public function testTmpMissingIdentityAnswersSignedOutAndLeavesTheSessionAlone(): void
    {
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $log->events = [];
        $logger = new ArrayLogger();
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
            $logger,
        );

        self::assertSame(['signedIn' => false], $sesame->sessionStatus());
        self::assertSame('the-user', $session->store['sesame.user'], 'The session is left alone.');
        self::assertNotContains('invalidate', $log->events);
        self::assertNotContains('forget:sesame.user', $log->events);
        self::assertSame('missing_identity', $logger->withMessage('sesame.session_anomaly')[0]['context']['reason']);
    }

    public function testTmpAStoredValueThatIsNotAnIdentitySignsTheSessionOut(): void
    {
        // Until 0.10.1 a value that was not an Identity object read as "no
        // identity" and the session stayed signed in with every identity
        // check skipped. Now anything that is not exactly the shape login()
        // writes signs the session out as bad_identity.
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', 'not-an-identity');
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $log->events = [];
        $logger = new ArrayLogger();
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
            $logger,
        );

        self::assertSame(['signedIn' => false], $sesame->sessionStatus());
        self::assertSame([], $session->store);
        self::assertContains('invalidate', $log->events);
        self::assertSame('bad_identity', $logger->withMessage('sesame.session_anomaly')[0]['context']['reason']);
        self::assertSame('bad_identity', $logger->withMessage('sesame.logout')[0]['context']['reason']);
    }
}
