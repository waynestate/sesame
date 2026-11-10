<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Exception\ProviderUnavailable;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\LoginStep;
use Waynestate\Sesame\Metrics\LoginMetrics;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\ArrayLogger;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\RecordingDriver;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;

/**
 * The facade counts every finished sign-in on the optional LoginMetrics,
 * by driver and outcome, and a counter that throws never changes the
 * sign-in (ADR-0011).
 */
final class SesameMetricsTest extends TestCase
{
    /**
     * @return array{0: Sesame, 1: object{calls: list<array{string, string}>}, 2: ArrayLogger}
     */
    private function sesame(LoginStep|\Throwable $step, mixed $user = 'the-user', ?LoginMetrics $metrics = null, string $driver = 'cas'): array
    {
        $counter = new class implements LoginMetrics {
            /** @var list<array{string, string}> */
            public array $calls = [];

            public function login(string $driver, string $outcome): void
            {
                $this->calls[] = [$driver, $outcome];
            }
        };
        $logger = new ArrayLogger();
        $log = new EventLog();

        $driverObject = $step instanceof \Throwable
            ? new class ($step) implements Driver {
                public function __construct(private readonly \Throwable $failure) {}

                public function name(): string
                {
                    return 'throwing';
                }

                public function login(\Psr\Http\Message\ServerRequestInterface $request): LoginStep
                {
                    throw $this->failure;
                }

                public function logoutUrl(string $returnTo): ?string
                {
                    return null;
                }

                public function serviceUrl(): ?string
                {
                    return null;
                }
            }
        : new RecordingDriver($step, null, $log);

        $config = $driver === 'fake'
            ? new Config(driver: 'fake', environment: 'testing', fakeUsername: 'jdoe')
            : new Config(...['driver' => $driver, 'environment' => 'testing']);

        $sesame = new Sesame(
            $driverObject,
            new RecordingResolver($user),
            new RecordingSession($log),
            $config,
            new FakeClock(),
            $logger,
            $metrics ?? $counter,
        );

        return [$sesame, $counter, $logger];
    }

    private function authenticated(): Authenticated
    {
        return new Authenticated(new Identity('jdoe', [], 'cas', new DateTimeImmutable('2026-11-10T12:00:00+00:00')));
    }

    private function request(): ServerRequest
    {
        return new ServerRequest('GET', 'https://app.example.test/login');
    }

    public function testASuccessfulSignInIsCounted(): void
    {
        [$sesame, $counter] = $this->sesame($this->authenticated());

        self::assertSame('the-user', $sesame->login($this->request()));
        self::assertSame([['cas', LoginMetrics::SUCCESS]], $counter->calls);
    }

    public function testARefusedSignInIsCounted(): void
    {
        [$sesame, $counter] = $this->sesame($this->authenticated(), null);

        self::assertNull($sesame->login($this->request()));
        self::assertSame([['cas', LoginMetrics::REFUSED]], $counter->calls);
    }

    public function testAFailedSignInIsCountedAndStillThrown(): void
    {
        $failure = new AuthenticationFailed('bad ticket ST-secret');
        [$sesame, $counter] = $this->sesame($failure);

        try {
            $sesame->login($this->request());
            self::fail('The failure must be rethrown.');
        } catch (AuthenticationFailed $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame([['cas', LoginMetrics::FAILED]], $counter->calls);
    }

    public function testAnUnreachableProviderIsCountedAsUnavailable(): void
    {
        $failure = new ProviderUnavailable('CAS did not answer (cas.unavailable)');
        [$sesame, $counter] = $this->sesame($failure);

        try {
            $sesame->login($this->request());
            self::fail('The failure must be rethrown.');
        } catch (ProviderUnavailable $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame([['cas', LoginMetrics::UNAVAILABLE]], $counter->calls);
    }

    public function testARedirectToTheProviderIsNotCounted(): void
    {
        [$sesame, $counter] = $this->sesame(new Redirect('https://cas.example.test/cas/login'));

        self::assertInstanceOf(Redirect::class, $sesame->login($this->request()));
        self::assertSame([], $counter->calls);
    }

    public function testTheDriverLabelIsTheConfiguredDriver(): void
    {
        [$sesame, $counter] = $this->sesame($this->authenticated(), 'the-user', null, 'fake');

        $sesame->login($this->request());

        self::assertSame([['fake', LoginMetrics::SUCCESS]], $counter->calls);
    }

    public function testAThrowingCounterNeverChangesTheSignInAndIsLoggedWithoutItsMessage(): void
    {
        $broken = new class implements LoginMetrics {
            public function login(string $driver, string $outcome): void
            {
                throw new RuntimeException('redis://:hunter2@metrics.example.test refused the connection');
            }
        };
        [$sesame, , $logger] = $this->sesame($this->authenticated(), 'the-user', $broken);

        self::assertSame('the-user', $sesame->login($this->request()));

        $failed = $logger->withMessage('sesame.metrics_failed');
        self::assertCount(1, $failed);
        self::assertSame('warning', $failed[0]['level']);
        self::assertSame(['event', 'driver', 'at'], array_keys($failed[0]['context']));
        self::assertSame('metrics_failed', $failed[0]['context']['event']);
        self::assertStringNotContainsString('hunter2', (string) json_encode($logger->records));
    }

    public function testAConfigErrorFromTheCounterIsLoggedWithItsReason(): void
    {
        $misconfigured = new class implements LoginMetrics {
            public function login(string $driver, string $outcome): void
            {
                throw new InvalidConfig('Turn on HELMET\'s request metrics.');
            }
        };
        [$sesame, , $logger] = $this->sesame($this->authenticated(), null, $misconfigured);

        self::assertNull($sesame->login($this->request()));

        $failed = $logger->withMessage('sesame.metrics_failed');
        self::assertCount(1, $failed);
        self::assertSame('Turn on HELMET\'s request metrics.', $failed[0]['context']['reason']);
    }

    public function testWithoutACounterNothingIsCountedOrLogged(): void
    {
        $logger = new ArrayLogger();
        $log = new EventLog();
        $sesame = new Sesame(
            new RecordingDriver($this->authenticated(), null, $log),
            new RecordingResolver(),
            new RecordingSession($log),
            new Config(...['driver' => 'cas', 'environment' => 'testing']),
            new FakeClock(),
            $logger,
        );

        self::assertSame('the-user', $sesame->login($this->request()));
        self::assertSame(['sesame.login'], $logger->messages());
    }
}
