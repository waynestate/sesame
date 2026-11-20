<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Cake;

use Authentication\Authenticator\ResultInterface;
use Authentication\Identifier\IdentifierCollection;
use Cake\Http\Exception\RedirectException;
use Cake\Http\Session;
use DateTimeImmutable;
use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Cake\CakeSession;
use Waynestate\Sesame\Cake\SesameAuthenticator;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Drivers\FakeDriver;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\RecordingDriver;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;

/**
 * The cakephp/authentication authenticator, tested against a real SESAME.
 *
 * A signed-in session authenticates with the stored user; a guest is sent to
 * the login route with a redirect parameter, unless already on it or the app
 * gave no login route. clearIdentity() ends the local sign-in the way an app
 * action calling Sesame::signOutLocal() would. The last two tests run a full
 * sign-in through the fake driver on a real Cake session, the way an app's
 * login action does, and read it back through a fresh Sesame built by the
 * closure from the request's session.
 */
final class SesameAuthenticatorTest extends TestCase
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

    private function config(): Config
    {
        return new Config(...[
            'driver' => 'fake',
            'environment' => 'testing',
            'fakeUsername' => 'jdoe',
        ]);
    }

    /**
     * @return array{0: Sesame, 1: RecordingSession}
     */
    private function app(): array
    {
        $log = new EventLog();
        $session = new RecordingSession($log);

        return [
            new Sesame(
                new RecordingDriver(new Authenticated($this->identity()), null, $log),
                new RecordingResolver(),
                $session,
                $this->config(),
                new FakeClock(),
            ),
            $session,
        ];
    }

    /**
     * A Sesame over the given Cake session, built the way an app builds one per
     * request: the fake driver, the recording resolver, a CakeSession port.
     */
    private function sesameOver(Session $session): Sesame
    {
        return new Sesame(
            new FakeDriver('testing', 'jdoe', ['cn' => ['John Doe']]),
            new RecordingResolver(),
            new CakeSession($session),
            $this->config(),
            new FakeClock(),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function authenticator(Sesame $sesame, array $overrides = []): SesameAuthenticator
    {
        return new SesameAuthenticator(new IdentifierCollection(), [
            'sesame' => static fn(): Sesame => $sesame,
            'loginUrl' => '/login',
            ...$overrides,
        ]);
    }

    public function testSignedInUserAuthenticates(): void
    {
        [$sesame] = $this->app();
        $sesame->login(new ServerRequest('GET', 'https://app.local/login'));
        $authenticator = $this->authenticator($sesame);

        $result = $authenticator->authenticate(new ServerRequest('GET', 'https://app.local/dashboard'));

        self::assertTrue($result->isValid());
        self::assertSame(ResultInterface::SUCCESS, $result->getStatus());
        // The recording resolver answers with a string, which the framework's
        // Result cannot carry, so it arrives wrapped.
        self::assertSame(['user' => 'the-user'], $result->getData());
    }

    public function testAnArrayUserPassesThroughUnchanged(): void
    {
        $log = new EventLog();
        $session = new RecordingSession($log);
        $user = ['username' => 'jdoe', 'name' => 'John Doe'];
        $sesame = new Sesame(
            new RecordingDriver(new Authenticated($this->identity()), null, $log),
            new RecordingResolver($user),
            $session,
            $this->config(),
            new FakeClock(),
        );
        $sesame->login(new ServerRequest('GET', 'https://app.local/login'));
        $authenticator = $this->authenticator($sesame);

        $result = $authenticator->authenticate(new ServerRequest('GET', 'https://app.local/dashboard'));

        self::assertTrue($result->isValid());
        self::assertSame($user, $result->getData());
    }

    public function testGuestIsSentToTheLoginRouteWithThePageTheyAskedFor(): void
    {
        [$sesame] = $this->app();
        $authenticator = $this->authenticator($sesame);

        $this->expectException(RedirectException::class);
        $this->expectExceptionCode(302);
        $this->expectExceptionMessage('/login?redirect=%2Fdashboard');

        $authenticator->authenticate(new ServerRequest('GET', 'https://app.local/dashboard'));
    }

    public function testGuestOnTheLoginRouteIsNotRedirectedAgain(): void
    {
        [$sesame] = $this->app();
        $authenticator = $this->authenticator($sesame);

        $result = $authenticator->authenticate(new ServerRequest('GET', 'https://app.local/login'));

        self::assertFalse($result->isValid());
        self::assertSame(ResultInterface::FAILURE_CREDENTIALS_MISSING, $result->getStatus());
    }

    public function testWithoutALoginRouteAGuestSimplyDoesNotAuthenticate(): void
    {
        [$sesame] = $this->app();
        $authenticator = $this->authenticator($sesame, ['loginUrl' => null]);

        $result = $authenticator->authenticate(new ServerRequest('GET', 'https://app.local/dashboard'));

        self::assertFalse($result->isValid());
        self::assertSame(ResultInterface::FAILURE_CREDENTIALS_MISSING, $result->getStatus());
    }

    public function testClearIdentityEndsTheLocalSignIn(): void
    {
        [$sesame, $session] = $this->app();
        $sesame->login(new ServerRequest('GET', 'https://app.local/login'));
        self::assertNotNull($session->get('sesame.user'));
        $authenticator = $this->authenticator($sesame);

        $result = $authenticator->clearIdentity(
            new ServerRequest('GET', 'https://app.local/logout'),
            new Response(),
        );

        self::assertNull($session->get('sesame.user'));
        self::assertArrayHasKey('request', $result);
        self::assertArrayHasKey('response', $result);
    }

    public function testPersistIdentityStoresNothingItself(): void
    {
        [$sesame, $session] = $this->app();
        $authenticator = $this->authenticator($sesame);
        $request = new ServerRequest('GET', 'https://app.local/');
        $response = new Response();

        $result = $authenticator->persistIdentity($request, $response, ['username' => 'jdoe']);

        self::assertSame($request, $result['request']);
        self::assertSame($response, $result['response']);
        self::assertNull($session->get('sesame.user'));
    }

    public function testMissingSesameConfigFailsLoudly(): void
    {
        $authenticator = new SesameAuthenticator(new IdentifierCollection(), ['loginUrl' => '/login']);

        $this->expectException(InvalidArgumentException::class);

        $authenticator->authenticate(new ServerRequest('GET', 'https://app.local/dashboard'));
    }

    public function testSesameClosureMustReturnASesame(): void
    {
        $authenticator = $this->authenticator(
            $this->app()[0],
            ['sesame' => static fn(): string => 'not a sesame'],
        );

        $this->expectException(InvalidArgumentException::class);

        $authenticator->authenticate(new ServerRequest('GET', 'https://app.local/dashboard'));
    }

    public function testFullSignInOnACakeSessionSurvivesToANewSesame(): void
    {
        $cakeSession = new Session();

        $this->sesameOver($cakeSession)->login(new ServerRequest('GET', 'https://app.local/login'));

        self::assertSame('the-user', $this->sesameOver($cakeSession)->user());
    }

    public function testTheClosureBuildsSesameFromTheRequestsSession(): void
    {
        $cakeSession = new Session();
        $this->sesameOver($cakeSession)->login(new ServerRequest('GET', 'https://app.local/login'));

        $authenticator = new SesameAuthenticator(new IdentifierCollection(), [
            'sesame' => function (ServerRequestInterface $request): Sesame {
                $session = $request->getAttribute('session');
                if (! $session instanceof Session) {
                    throw new RuntimeException('The request carries no session.');
                }

                return $this->sesameOver($session);
            },
        ]);

        $result = $authenticator->authenticate(
            (new ServerRequest('GET', 'https://app.local/dashboard'))->withAttribute('session', $cakeSession),
        );

        self::assertTrue($result->isValid());
        self::assertSame(['user' => 'the-user'], $result->getData());
    }
}
