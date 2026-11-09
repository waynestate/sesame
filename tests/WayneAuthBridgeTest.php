<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Drivers\Cas\PendingLogin;
use Waynestate\Sesame\Drivers\CasDriver;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Exception\ProviderUnavailable;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\FakeHttpClient;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;
use Waynestate\Sesame\Tests\Support\RedirectSent;
use Waynestate\Sesame\Tests\Support\TestableWayneAuth;
use Waynestate\Sesame\Tests\Support\UnauthorizedSent;
use Waynestate\Sesame\Tests\Support\UnreachableHttpClient;
use Waynestate\Sesame\WayneAuth\WayneAuth;

/**
 * The Wayne Auth bridge, against the real cas driver with the fake HTTP
 * client and an in-memory session: the old three calls (new WayneAuth,
 * authenticateSSO, getAccessID) sign a visitor in through CAS.
 */
final class WayneAuthBridgeTest extends TestCase
{
    private const BASE = 'https://cas.test/cas';

    private const SERVICE = 'https://app.test/';

    /** The legacy config a Wayne Auth app passes; the bridge must ignore it. */
    private const LEGACY = ['sso_host' => 'sso.example.test', 'sso_port' => '10144', 'timeout' => '10'];

    private FakeClock $clock;

    private FakeHttpClient $http;

    private RecordingSession $session;

    private Sesame $sesame;

    protected function setUp(): void
    {
        WayneAuth::resetForTests();

        $this->clock = new FakeClock();
        $this->http = new FakeHttpClient();
        $this->session = new RecordingSession(new EventLog());
        $this->sesame = $this->sesame(new RecordingResolver('the-app-user'));
    }

    protected function tearDown(): void
    {
        WayneAuth::resetForTests();
    }

    private function sesame(RecordingResolver $resolver): Sesame
    {
        $driver = new CasDriver(self::BASE, self::SERVICE, $this->http, new Psr17Factory(), '2.0', false, false, $this->clock, $this->session);

        return new Sesame(
            $driver,
            $resolver,
            $this->session,
            new Config(driver: 'cas', environment: 'production', casBaseUrl: self::BASE, casServiceUrl: self::SERVICE),
            $this->clock,
        );
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function bridge(array $query = [], ?string $onGuest = null, ?string $accessIdCase = null, ?Sesame $sesame = null, array $headers = []): TestableWayneAuth
    {
        $request = (new ServerRequest('GET', self::SERVICE . ($query === [] ? '' : '?' . http_build_query($query)), $headers))
            ->withQueryParams($query);

        return new TestableWayneAuth($request, $this->clock->now()->getTimestamp(), self::LEGACY, $sesame ?? $this->sesame, $onGuest, $accessIdCase);
    }

    private function queueCas(string $fixture): void
    {
        $body = (string) file_get_contents(__DIR__ . '/fixtures/cas/' . $fixture);
        $this->http->queue((new Response())->withStatus(200)->withBody(Stream::create($body)));
    }

    private function redirectOf(WayneAuth $bridge): string
    {
        try {
            $bridge->authenticateSSO();
        } catch (RedirectSent $sent) { // @phpstan-ignore catch.neverThrown (TestableWayneAuth::redirect() throws it)
            return $sent->url;
        }

        self::fail('Expected authenticateSSO() to redirect.');
    }

    /**
     * Run the whole sign-in: the guest is sent to CAS, CAS sends the browser
     * back with a ticket, and the ticket is validated and stored.
     */
    private function signIn(): void
    {
        $this->redirectOf($this->bridge());
        $this->queueCas('success.xml');
        self::assertSame(self::SERVICE, $this->redirectOf($this->bridge(['ticket' => 'ST-1-bridge'])));
    }

    public function testAGuestIsSentToTheCasSignInWithTheServiceUrl(): void
    {
        $url = $this->redirectOf($this->bridge());

        self::assertSame(self::BASE . '/login?service=' . rawurlencode(self::SERVICE), $url);
        self::assertArrayHasKey(PendingLogin::SESSION_KEY, $this->session->store, 'The sign-in is bound to this browser.');
        self::assertSame([], $this->http->requests, 'No CAS call before the ticket comes back.');
    }

    public function testAGuestGetsFalseAndNoRedirectWhenTheBridgeDenies(): void
    {
        $bridge = $this->bridge([], WayneAuth::ON_GUEST_DENY);

        self::assertFalse($bridge->authenticateSSO());
        self::assertNull($bridge->getAccessID());
        self::assertArrayNotHasKey(PendingLogin::SESSION_KEY, $this->session->store);
    }

    public function testATicketSignsTheUserInAndSendsTheBrowserOnWithoutTheTicket(): void
    {
        $this->signIn();

        self::assertSame('the-app-user', $this->session->store['sesame.user']);
        self::assertCount(1, $this->http->requests);
        self::assertStringContainsString('ticket=ST-1-bridge', (string) $this->http->requests[0]->getUri());
    }

    public function testTheOldThreeCallsAnswerAfterTheSignIn(): void
    {
        $this->signIn();

        $wayne_auth = $this->bridge();

        self::assertTrue($wayne_auth->authenticateSSO());
        self::assertSame('ab1234', $wayne_auth->getAccessID(), 'The AccessID is the CAS username, not the app user.');
        self::assertSame('ab1234', $wayne_auth->accessid, 'The old public property is set too.');
        self::assertSame(self::LEGACY, $wayne_auth->config, 'The legacy config is kept as given.');
    }

    public function testTheAccessIdCaseCanBeFixedForAppsThatCompareInOneCase(): void
    {
        $this->signIn();

        self::assertSame('AB1234', $this->accessIdAfterCheck($this->bridge([], null, 'upper')));
        self::assertSame('ab1234', $this->accessIdAfterCheck($this->bridge([], null, 'lower')));
    }

    private function accessIdAfterCheck(WayneAuth $bridge): ?string
    {
        self::assertTrue($bridge->authenticateSSO());

        return $bridge->getAccessID();
    }

    public function testATicketThatDoesNotValidateThrowsInsteadOfLooping(): void
    {
        $this->redirectOf($this->bridge());
        $this->queueCas('failure-invalid-ticket.xml');

        try {
            $this->bridge(['ticket' => 'ST-1-bad'])->authenticateSSO();
            self::fail('Expected AuthenticationFailed.');
        } catch (AuthenticationFailed) {
            self::assertArrayNotHasKey('sesame.user', $this->session->store);
        }
    }

    public function testATicketThisBrowserNeverAskedForIsRefusedBeforeAnyCasCall(): void
    {
        $this->expectException(AuthenticationFailed::class);

        try {
            $this->bridge(['ticket' => 'ST-1-forged'])->authenticateSSO();
        } finally {
            self::assertSame([], $this->http->requests);
        }
    }

    public function testARefusedIdentityAnswersFalse(): void
    {
        $sesame = $this->sesame(new RecordingResolver(null));
        $this->redirectOf($this->bridge([], null, null, $sesame));
        $this->queueCas('success.xml');

        $bridge = $this->bridge(['ticket' => 'ST-1-refused'], null, null, $sesame);

        self::assertFalse($bridge->authenticateSSO());
        self::assertNull($bridge->getAccessID());
        self::assertArrayNotHasKey('sesame.user', $this->session->store);
    }

    public function testAnExpiredSessionIsAGuestAgain(): void
    {
        $this->signIn();
        $this->clock->advance(121 * 60);

        self::assertStringStartsWith(self::BASE . '/login?', $this->redirectOf($this->bridge()));
    }

    public function testAGuestsXhrGetsA401InsteadOfTheRedirectToCas(): void
    {
        try {
            $this->bridge([], null, null, null, ['X-Requested-With' => 'XMLHttpRequest'])->authenticateSSO();
            self::fail('Expected the 401 for a script request.');
        } catch (UnauthorizedSent) {
            self::assertArrayNotHasKey(PendingLogin::SESSION_KEY, $this->session->store, 'No sign-in is started for a script.');
        }
    }

    public function testAFetchThatAsksForJsonGetsA401Too(): void
    {
        $this->expectException(UnauthorizedSent::class);

        $this->bridge([], null, null, null, ['Accept' => 'application/json'])->authenticateSSO();
    }

    public function testAnXhrAfterTheIdleTimeoutGetsA401AndTheSessionIsSignedOut(): void
    {
        $this->signIn();
        $this->clock->advance(121 * 60);

        try {
            $this->bridge([], null, null, null, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*'])->authenticateSSO();
            self::fail('Expected the 401 for a script request after the timeout.');
        } catch (UnauthorizedSent) {
            self::assertArrayNotHasKey('sesame.user', $this->session->store);
        }
    }

    public function testAnXhrFromASignedInVisitorStillPasses(): void
    {
        $this->signIn();

        self::assertTrue($this->bridge([], null, null, null, ['X-Requested-With' => 'XMLHttpRequest'])->authenticateSSO());
    }

    public function testAPjaxRequestOrAnHtmlNavigationIsStillRedirected(): void
    {
        self::assertStringStartsWith(self::BASE . '/login?', $this->redirectOf(
            $this->bridge([], null, null, null, ['X-Requested-With' => 'XMLHttpRequest', 'X-PJAX' => 'true']),
        ));
        self::assertStringStartsWith(self::BASE . '/login?', $this->redirectOf(
            $this->bridge([], null, null, null, ['Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9']),
        ));
    }

    public function testADeniedGuestsXhrStillGetsFalse(): void
    {
        self::assertFalse($this->bridge([], WayneAuth::ON_GUEST_DENY, null, null, ['Accept' => 'application/json'])->authenticateSSO());
    }

    public function testCasUnreachableDuringTheTicketCheckThrowsProviderUnavailable(): void
    {
        $http = new UnreachableHttpClient();
        $driver = new CasDriver(self::BASE, self::SERVICE, $http, new Psr17Factory(), '2.0', false, false, $this->clock, $this->session);
        $sesame = new Sesame(
            $driver,
            new RecordingResolver('the-app-user'),
            $this->session,
            new Config(driver: 'cas', environment: 'production', casBaseUrl: self::BASE, casServiceUrl: self::SERVICE),
            $this->clock,
        );

        $this->redirectOf($this->bridge([], null, null, $sesame));

        try {
            $this->bridge(['ticket' => 'ST-1-down'], null, null, $sesame)->authenticateSSO();
            self::fail('Expected ProviderUnavailable.');
        } catch (ProviderUnavailable $down) {
            self::assertInstanceOf(AuthenticationFailed::class, $down, 'Apps that catch AuthenticationFailed still catch it.');
            self::assertStringContainsString('cas.unavailable', $down->getMessage());
            self::assertStringNotContainsString('ST-1-down', $down->getMessage(), 'The ticket never reaches the message.');
            self::assertNull($down->getPrevious(), 'The client exception, which holds the ticket URL, is not chained.');
            self::assertCount(1, $http->requests);
            self::assertArrayNotHasKey('sesame.user', $this->session->store, 'Nobody is signed in.');
        }
    }

    public function testVerifyReportsTheAccessIdAndTheTimeLeftWithoutRedirecting(): void
    {
        $this->signIn();

        $result = $this->bridge()->_parseSSOCookie('v');

        self::assertSame(['verified' => true, 'accessid' => 'ab1234', 'timeout' => 120 * 60], $result);
    }

    public function testVerifyAnswersNotVerifiedForAGuestAndNeverRedirects(): void
    {
        self::assertSame(['verified' => false], $this->bridge()->_parseSSOCookie('v'));
    }

    public function testThePasswordMethodHasNoEquivalent(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('CAS never hands an app the password');

        $this->bridge()->_parseSSOCookie('d');
    }

    public function testAnUnknownMethodIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->bridge()->_parseSSOCookie('x');
    }

    public function testABadOptionIsRefused(): void
    {
        $this->expectException(InvalidConfig::class);

        $this->bridge([], 'maybe');
    }

    public function testTheDeprecationIsSaidOncePerProcess(): void
    {
        $said = [];
        set_error_handler(static function (int $level, string $message) use (&$said): bool {
            $said[] = [$level, $message];

            return true;
        });

        try {
            $this->bridge()->_parseSSOCookie('v');
            $this->bridge()->_parseSSOCookie('v');
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $said);
        self::assertSame(E_USER_DEPRECATED, $said[0][0]);
        self::assertStringContainsString('Moving off Wayne Auth', $said[0][1]);
    }

    public function testBootRegistersSesameAndTheOldGlobalName(): void
    {
        $sesame = WayneAuth::boot(
            ['driver' => 'fake', 'environment' => 'local', 'fake' => ['username' => 'jdoe']],
            options: ['on_guest' => 'deny', 'accessid_case' => 'upper'],
        );

        self::assertSame($sesame, sesame());
        self::assertTrue(class_exists('WayneAuth', false));
        self::assertTrue(is_a('WayneAuth', WayneAuth::class, true));

        // boot()'s options become the defaults for the bridges made after it.
        $request = new ServerRequest('GET', self::SERVICE);
        $bridge = new TestableWayneAuth($request, 0, self::LEGACY, $this->sesame);
        self::assertFalse($bridge->authenticateSSO(), 'Denied, not redirected: boot() said deny.');
    }

    public function testBootRefusesABadOption(): void
    {
        $this->expectException(InvalidConfig::class);

        WayneAuth::boot(
            ['driver' => 'fake', 'environment' => 'local', 'fake' => ['username' => 'jdoe']],
            options: ['accessid_case' => 'title'],
        );
    }

    public function testTheGlobalNameIsNeverTakenFromAnotherClass(): void
    {
        WayneAuth::aliasGlobal('WayneAuthBridgeTestAlias');
        WayneAuth::aliasGlobal('WayneAuthBridgeTestAlias');
        self::assertTrue(is_a('WayneAuthBridgeTestAlias', WayneAuth::class, true), 'A second call is a no-op.');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Remove the line that loads the old Wayne Auth class');

        // ArrayObject stands in for the old WayneAuth class still loaded.
        WayneAuth::aliasGlobal('ArrayObject');
    }
}
