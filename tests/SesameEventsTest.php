<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\LoginStep;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\ArrayLogger;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\RecordingDriver;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;
use Waynestate\Sesame\Tests\Support\ThrowingLogger;

final class SesameEventsTest extends TestCase
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
            ...$overrides,
        ]);
    }

    private function atom(FakeClock $clock): string
    {
        return $clock->now()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    public function testLoginIsLogged(): void
    {
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
            $logger,
        );

        $user = $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        self::assertSame('the-user', $user);
        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('sesame.login', $logger->records[0]['message']);
        $ref = $logger->records[0]['context']['session_ref'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $ref);
        self::assertSame([
            'event' => 'login',
            'driver' => 'fake',
            'username' => 'jdoe',
            'at' => $this->atom($clock),
            'session_ref' => $ref,
        ], $logger->records[0]['context']);
    }

    public function testARefusedIdentityIsLogged(): void
    {
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(null),
            $session,
            $this->config(),
            $clock,
            $logger,
        );

        $user = $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        self::assertNull($user);
        self::assertSame([], $session->store);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('sesame.login_refused', $logger->records[0]['message']);
        self::assertSame([
            'event' => 'login_refused',
            'driver' => 'fake',
            'username' => 'jdoe',
            'at' => $this->atom($clock),
        ], $logger->records[0]['context']);
    }

    public function testAFailedLoginIsLoggedWithoutTheFailureMessage(): void
    {
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $log = new EventLog();
        $driver = new class ($log) implements Driver {
            public function __construct(private readonly EventLog $log) {}

            public function name(): string
            {
                return 'recording';
            }

            public function login(ServerRequestInterface $request): LoginStep
            {
                $this->log->record('login');

                throw new AuthenticationFailed('INVALID_TICKET ST-abc123 the provider refused the ticket', 7);
            }

            public function logoutUrl(string $returnTo): ?string
            {
                return null;
            }

            public function serviceUrl(): ?string
            {
                return null;
            }
        };
        $sesame = new Sesame(
            $driver,
            new RecordingResolver(),
            new RecordingSession($log),
            $this->config(),
            $clock,
            $logger,
        );

        try {
            $sesame->login(new ServerRequest('GET', 'http://app.local/login'));
            self::fail('The failed login must rethrow the exception.');
        } catch (AuthenticationFailed $caught) {
            self::assertSame('INVALID_TICKET ST-abc123 the provider refused the ticket', $caught->getMessage());
            self::assertSame(7, $caught->getCode());
        }

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('sesame.login_failed', $logger->records[0]['message']);
        // The context is exactly event, driver and at: no message, no code.
        self::assertSame([
            'event' => 'login_failed',
            'driver' => 'fake',
            'at' => $this->atom($clock),
        ], $logger->records[0]['context']);

        $everywhere = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('INVALID_TICKET', $everywhere);
        self::assertStringNotContainsString('ST-abc123', $everywhere);
        self::assertStringNotContainsString('refused the ticket', $everywhere);
        self::assertStringNotContainsString('"code"', $everywhere);
        self::assertStringNotContainsString('"message"', $everywhere);
    }

    public function testLogoutIsLoggedWithItsReason(): void
    {
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), 'http://idp.local/logout', $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
            $logger,
        );

        $url = $sesame->logout('http://app.local/');

        self::assertSame('http://idp.local/logout', $url);
        self::assertSame([], $session->store);
        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('sesame.logout', $logger->records[0]['message']);
        self::assertSame([
            'event' => 'logout',
            'reason' => 'logout',
            'driver' => 'fake',
            'username' => 'jdoe',
            'at' => $this->atom($clock),
        ], $logger->records[0]['context']);
    }

    public function testTimeoutsAreLoggedWithTheirReason(): void
    {
        // The idle timeout.
        $clock = new FakeClock();
        $logger = new ArrayLogger();
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
            $logger,
        );

        $clock->advance(120 * 60 + 1);

        self::assertNull($sesame->user());
        self::assertSame([], $session->store);
        self::assertCount(1, $logger->records);
        self::assertSame('sesame.logout', $logger->records[0]['message']);
        self::assertSame('idle_timeout', $logger->records[0]['context']['reason']);
        self::assertSame('jdoe', $logger->records[0]['context']['username']);
        self::assertSame('fake', $logger->records[0]['context']['driver']);

        // The absolute lifetime: the user is active every 10 minutes, so the
        // idle timeout never fires and only the absolute lifetime ends the
        // session.
        $clock = new FakeClock();
        $logger = new ArrayLogger();
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
            $this->config(['sessionAbsoluteMinutes' => 60]),
            $clock,
            $logger,
        );

        for ($i = 0; $i < 6; $i++) {
            $clock->advance(10 * 60);
            self::assertSame('the-user', $sesame->user(), 'Request ' . ($i + 1) . ' should still be signed in.');
        }

        $clock->advance(10 * 60);

        self::assertNull($sesame->user());
        self::assertSame([], $session->store);
        self::assertCount(1, $logger->records);
        self::assertSame('sesame.logout', $logger->records[0]['message']);
        self::assertSame('absolute_timeout', $logger->records[0]['context']['reason']);
    }

    public function testAnEmptySessionLogsNoLogout(): void
    {
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $log = new EventLog();
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            new RecordingSession($log),
            $this->config(),
            $clock,
            $logger,
        );

        self::assertNull($sesame->user());
        self::assertNull($sesame->logout('http://app.local/'));

        self::assertSame([], $logger->records);
    }

    public function testAUsernameCannotForgeALogLine(): void
    {
        // A newline and a control character early in the username, then more
        // than 128 characters of padding: the forged line is replaced with ?
        // and the whole thing is cut at 128.
        $forged = "ab\n\x01" . str_repeat('a', 200);
        $identity = new Identity(
            $forged,
            [],
            'fake',
            new DateTimeImmutable('2026-10-27T12:00:00+00:00'),
        );
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $log = new EventLog();
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($identity), null, $log),
            new RecordingResolver(),
            new RecordingSession($log),
            $this->config(),
            $clock,
            $logger,
        );

        $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        self::assertCount(1, $logger->records);
        $username = $logger->records[0]['context']['username'];
        self::assertSame('ab??' . str_repeat('a', 124), $username);
        self::assertSame(128, strlen($username));
        self::assertStringNotContainsString("\n", $username);
        self::assertStringNotContainsString("\x01", $username);
    }

    public function testALoggerThatThrowsChangesNothing(): void
    {
        $clock = new FakeClock();
        $logger = new ThrowingLogger();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), 'http://idp.local/logout', $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
            $logger,
        );

        $user = $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        self::assertSame('the-user', $user);
        self::assertSame('the-user', $session->store['sesame.user']);
        self::assertSame('sesame.login', $logger->messages[0]);

        $url = $sesame->logout('http://app.local/');

        self::assertSame('http://idp.local/logout', $url);
        self::assertSame([], $session->store);
        self::assertSame(['sesame.login', 'sesame.logout'], $logger->messages);
    }

    public function testNoLoggerIsFine(): void
    {
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), 'http://idp.local/logout', $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
        );

        $user = $sesame->login(new ServerRequest('GET', 'http://app.local/login'));

        self::assertSame('the-user', $user);
        self::assertSame('the-user', $sesame->user());
        self::assertSame('http://idp.local/logout', $sesame->logout('http://app.local/'));
        self::assertSame([], $session->store);
    }

    public function testLoginAndLogoutShareASessionRef(): void
    {
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), 'http://idp.local/logout', $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
            $logger,
        );

        $sesame->login(new ServerRequest('GET', 'http://app.local/login'));
        $sesame->logout('http://app.local/');

        self::assertCount(2, $logger->records);
        $loginRef = $logger->records[0]['context']['session_ref'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $loginRef);
        // The ref is stored in the session at login and cleared at logout.
        self::assertSame([], $session->store);
        self::assertSame('sesame.login', $logger->records[0]['message']);
        self::assertSame('sesame.logout', $logger->records[1]['message']);
        self::assertSame($loginRef, $logger->records[1]['context']['session_ref']);
    }

    public function testATimeoutLogoutCarriesTheSessionRef(): void
    {
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $session->put('sesame.session_ref', 'deadbeefcafebabe');
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
            $logger,
        );

        $clock->advance(120 * 60 + 1);

        self::assertNull($sesame->user());
        self::assertCount(1, $logger->records);
        self::assertSame('sesame.logout', $logger->records[0]['message']);
        self::assertSame('idle_timeout', $logger->records[0]['context']['reason']);
        self::assertSame('deadbeefcafebabe', $logger->records[0]['context']['session_ref']);
    }

    public function testTwoSignInsGetDifferentSessionRefs(): void
    {
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
            $logger,
        );

        $sesame->login(new ServerRequest('GET', 'http://app.local/login'));
        $firstRef = $logger->records[0]['context']['session_ref'];
        $sesame->login(new ServerRequest('GET', 'http://app.local/login'));
        $secondRef = $logger->records[1]['context']['session_ref'];

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $firstRef);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $secondRef);
        self::assertNotSame($firstRef, $secondRef);
        self::assertSame($secondRef, $session->store['sesame.session_ref']);
    }

    public function testFailedAndRefusedLoginsCarryNoSessionRef(): void
    {
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $log = new EventLog();
        $driver = new class ($log) implements Driver {
            public function __construct(private readonly EventLog $log) {}

            public function name(): string
            {
                return 'recording';
            }

            public function login(ServerRequestInterface $request): LoginStep
            {
                $this->log->record('login');

                throw new AuthenticationFailed('INVALID_TICKET ST-abc123');
            }

            public function logoutUrl(string $returnTo): ?string
            {
                return null;
            }

            public function serviceUrl(): ?string
            {
                return null;
            }
        };
        $sesame = new Sesame(
            $driver,
            new RecordingResolver(),
            new RecordingSession($log),
            $this->config(),
            $clock,
            $logger,
        );

        try {
            $sesame->login(new ServerRequest('GET', 'http://app.local/login'));
            self::fail('The failed login must rethrow the exception.');
        } catch (AuthenticationFailed) {
            // Expected.
        }

        self::assertCount(1, $logger->records);
        self::assertSame('sesame.login_failed', $logger->records[0]['message']);
        self::assertArrayNotHasKey('session_ref', $logger->records[0]['context']);

        $refused = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(null),
            new RecordingSession($log),
            $this->config(),
            $clock,
            $logger,
        );

        self::assertNull($refused->login(new ServerRequest('GET', 'http://app.local/login')));

        self::assertCount(2, $logger->records);
        self::assertSame('sesame.login_refused', $logger->records[1]['message']);
        self::assertArrayNotHasKey('session_ref', $logger->records[1]['context']);
    }

    public function testATamperedSessionRefIsNotLogged(): void
    {
        foreach (['not hex at all', 'DEADBEEFCAFEFACE', 'deadbeefcafe', 'deadbeefcafebabe\nforged line', 12345] as $tampered) {
            $clock = new FakeClock();
            $logger = new ArrayLogger();
            $log = new EventLog();
            $session = new RecordingSession($log);
            $session->put('sesame.user', 'the-user');
            $session->put('sesame.identity', $this->identity());
            $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
            $session->put('sesame.last_seen', $clock->now()->getTimestamp());
            $session->put('sesame.session_ref', $tampered);
            $log->events = [];
            $sesame = new Sesame(
                new RecordingDriver(new Authenticated($this->identity()), null, $log),
                new RecordingResolver(),
                $session,
                $this->config(),
                $clock,
                $logger,
            );

            self::assertNull($sesame->logout('http://app.local/'));

            self::assertCount(1, $logger->records);
            self::assertSame('sesame.logout', $logger->records[0]['message']);
            self::assertArrayNotHasKey('session_ref', $logger->records[0]['context']);
        }
    }

    public function testMissingTimestampsLogsAnAnomalyBeforeTheLogout(): void
    {
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
        // No sesame.signed_in_at or sesame.last_seen: a session made
        // before the timeouts existed.
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
            $logger,
        );

        self::assertNull($sesame->user());
        self::assertSame([], $session->store);
        self::assertCount(2, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('sesame.session_anomaly', $logger->records[0]['message']);
        self::assertSame([
            'event' => 'session_anomaly',
            'reason' => 'missing_timestamps',
            'driver' => 'fake',
            'username' => 'jdoe',
            'at' => $this->atom($clock),
        ], $logger->records[0]['context']);
        self::assertSame('info', $logger->records[1]['level']);
        self::assertSame('sesame.logout', $logger->records[1]['message']);
        self::assertSame('no_timestamps', $logger->records[1]['context']['reason']);
    }

    public function testABadSessionRefLogsAnAnomalyWithoutSigningOut(): void
    {
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $session->put('sesame.session_ref', "evil\nforged line");
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
            $logger,
        );

        self::assertSame('the-user', $sesame->user());
        self::assertSame('the-user', $session->store['sesame.user']);
        self::assertSame("evil\nforged line", $session->store['sesame.session_ref']);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('sesame.session_anomaly', $logger->records[0]['message']);
        self::assertSame('bad_session_ref', $logger->records[0]['context']['reason']);
        self::assertArrayNotHasKey('session_ref', $logger->records[0]['context']);
        $everywhere = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('forged', $everywhere);
    }

    public function testNoSessionRefIsNotAnAnomaly(): void
    {
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        // No sesame.session_ref: a session started before the feature.
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
            $logger,
        );

        self::assertSame('the-user', $sesame->user());
        self::assertSame([], $logger->records);
    }

    public function testAnAnomalyEventIsWrittenOncePerCallAndNeverWithoutALogger(): void
    {
        // Without a logger nothing is written and user() behaves as before.
        $clock = new FakeClock();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $session->put('sesame.session_ref', "evil\nforged line");
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
        );

        self::assertSame('the-user', $sesame->user());
        self::assertSame('the-user', $session->store['sesame.user']);

        // With a logger, each user() call on a bad reference logs exactly
        // one anomaly.
        $clock = new FakeClock();
        $logger = new ArrayLogger();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
        $session->put('sesame.signed_in_at', $clock->now()->getTimestamp());
        $session->put('sesame.last_seen', $clock->now()->getTimestamp());
        $session->put('sesame.session_ref', "evil\nforged line");
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
            $logger,
        );

        $first = $sesame->user();
        $second = $sesame->user();

        self::assertSame('the-user', $first);
        self::assertSame('the-user', $second);

        self::assertCount(2, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('sesame.session_anomaly', $logger->records[0]['message']);
        self::assertSame('bad_session_ref', $logger->records[0]['context']['reason']);
        self::assertSame('warning', $logger->records[1]['level']);
        self::assertSame('sesame.session_anomaly', $logger->records[1]['message']);
        self::assertSame('bad_session_ref', $logger->records[1]['context']['reason']);
    }

    public function testAThrowingLoggerDoesNotChangeTheAnomalyPath(): void
    {
        $clock = new FakeClock();
        $logger = new ThrowingLogger();
        $log = new EventLog();
        $session = new RecordingSession($log);
        $session->put('sesame.user', 'the-user');
        $session->put('sesame.identity', $this->identity());
        // No sesame.signed_in_at or sesame.last_seen.
        $log->events = [];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver(),
            $session,
            $this->config(),
            $clock,
            $logger,
        );

        self::assertNull($sesame->user());
        self::assertSame([], $session->store);
        self::assertSame(['sesame.session_anomaly', 'sesame.logout'], $logger->messages);
    }
}
