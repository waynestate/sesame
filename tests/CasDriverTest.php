<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Drivers\CasDriver;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\EventLog;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\FakeHttpClient;
use Waynestate\Sesame\Tests\Support\RecordingResolver;
use Waynestate\Sesame\Tests\Support\RecordingSession;

final class CasDriverTest extends TestCase
{
    private const BASE = 'https://cas.test/cas';

    private const SERVICE = 'https://app.test/login';

    private function driver(FakeHttpClient $http, string $protocol = '3.0', bool $renew = false, ?ClockInterface $clock = null): CasDriver
    {
        return new CasDriver(self::BASE, self::SERVICE, $http, new Psr17Factory(), $protocol, $renew, $clock);
    }

    private static function response(int $status, string $body): ResponseInterface
    {
        return (new Response())->withStatus($status)->withBody(Stream::create($body));
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/cas/'.$name);
    }

    /**
     * A CAS success response, with an optional authenticationDate attribute.
     */
    private static function successXml(?string $authenticationDate = null): string
    {
        $date = $authenticationDate === null ? '' : '<authenticationDate>'.$authenticationDate.'</authenticationDate>'."\n      ";

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<serviceResponse xmlns="http://www.yale.edu/tp/cas">'."\n"
            .'  <authenticationSuccess>'."\n"
            .'    <user>ab1234</user>'."\n"
            .'    <attributes>'."\n"
            .'      <mail>ab1234@wayne.edu</mail>'."\n"
            .$date
            .'    </attributes>'."\n"
            .'  </authenticationSuccess>'."\n"
            .'</serviceResponse>'."\n";
    }

    public function testNoTicketRedirectsToTheCasLogin(): void
    {
        $http = new FakeHttpClient();
        $driver = $this->driver($http);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE));

        $this->assertInstanceOf(Redirect::class, $step);
        $this->assertSame(
            self::BASE.'/login?service='.rawurlencode(self::SERVICE),
            $step->url,
        );
        $this->assertSame([], $http->requests);
    }

    public function testServiceUrlComesFromConfigOnly(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http);
        $headers = [
            'Host' => 'evil.test',
            'X-Forwarded-Proto' => 'http',
            'X-Forwarded-Host' => 'evil.test',
        ];

        $redirect = $driver->login(new ServerRequest('GET', self::SERVICE, $headers));

        $this->assertInstanceOf(Redirect::class, $redirect);
        $this->assertStringContainsString(rawurlencode(self::SERVICE), $redirect->url);
        $this->assertStringNotContainsString('evil.test', $redirect->url);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1', $headers));

        $this->assertInstanceOf(Authenticated::class, $step);

        parse_str($http->requests[0]->getUri()->getQuery(), $query);

        $this->assertSame(self::SERVICE, $query['service']);
        $this->assertSame('ST-1', $query['ticket']);
    }

    #[DataProvider('badTickets')]
    public function testRejectsABadTicketBeforeAnyHttpCall(string $ticket): void
    {
        $http = new FakeHttpClient();
        $driver = $this->driver($http);

        try {
            $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket='.rawurlencode($ticket)));
            $this->fail('Expected AuthenticationFailed.');
        } catch (AuthenticationFailed) {
            // Expected: the ticket fails the pattern before any HTTP call.
        }

        $this->assertSame([], $http->requests);
    }

    /**
     * Tickets that must be rejected before any HTTP call.
     *
     * @return array<string, array{string}>
     */
    public static function badTickets(): array
    {
        return [
            'a query string smuggled into the ticket' => ['ST-1&renew=true'],
            'a proxy ticket' => ['PT-1'],
            'an empty ticket' => [''],
            'a ticket that is too long' => ['ST-'.str_repeat('a', 254)],
        ];
    }

    public function testValidateUrlEncodesServiceAndTicket(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http);

        $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1.a_b-c'));

        $this->assertSame(
            self::BASE.'/p3/serviceValidate?service='.rawurlencode(self::SERVICE).'&ticket=ST-1.a_b-c',
            $http->requests[0]->getUri()->__toString(),
        );
    }

    public function testLoginRedirectCarriesRenewWhenEnabled(): void
    {
        $http = new FakeHttpClient();
        $driver = $this->driver($http, '3.0', true);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE));

        $this->assertInstanceOf(Redirect::class, $step);
        $this->assertSame(
            self::BASE.'/login?'.http_build_query([
                'service' => self::SERVICE,
                'renew' => 'true',
            ], '', '&', PHP_QUERY_RFC3986),
            $step->url,
        );
        $this->assertSame([], $http->requests);
    }

    public function testLoginRedirectHasNoRenewWhenDisabled(): void
    {
        $http = new FakeHttpClient();
        $driver = $this->driver($http);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE));

        $this->assertInstanceOf(Redirect::class, $step);
        $this->assertStringNotContainsString('renew', $step->url);
    }

    public function testValidateRequestCarriesRenewWhenEnabled(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http, '3.0', true);

        $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));

        parse_str($http->requests[0]->getUri()->getQuery(), $query);

        $this->assertSame(self::SERVICE, $query['service']);
        $this->assertSame('ST-1', $query['ticket']);
        $this->assertSame('true', $query['renew']);
    }

    public function testValidateRequestHasNoRenewWhenDisabled(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http);

        $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));

        parse_str($http->requests[0]->getUri()->getQuery(), $query);

        $this->assertArrayNotHasKey('renew', $query);
    }

    public function testRenewUrlsEncodeWithTheSameQueryBuilder(): void
    {
        $service = 'https://app.test/login?next=/a b&c=d';
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = new CasDriver(self::BASE, $service, $http, new Psr17Factory(), '3.0', true);

        $redirect = $driver->login(new ServerRequest('GET', 'https://app.test/'));

        $this->assertInstanceOf(Redirect::class, $redirect);
        $this->assertSame(
            self::BASE.'/login?'.http_build_query([
                'service' => $service,
                'renew' => 'true',
            ], '', '&', PHP_QUERY_RFC3986),
            $redirect->url,
        );

        $driver->login(new ServerRequest('GET', 'https://app.test/?ticket=ST-1'));

        $this->assertSame(
            self::BASE.'/p3/serviceValidate?'.http_build_query([
                'service' => $service,
                'renew' => 'true',
                'ticket' => 'ST-1',
            ], '', '&', PHP_QUERY_RFC3986),
            $http->requests[0]->getUri()->__toString(),
        );
    }

    public function testAuthenticatedAtComesFromAuthenticationDate(): void
    {
        $clock = new FakeClock();
        $http = new FakeHttpClient([self::response(200, self::successXml('2026-10-27T11:59:00Z'))]);
        $driver = $this->driver($http, '3.0', false, $clock);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame('2026-10-27T11:59:00+00:00', $step->identity->authenticatedAt->format('Y-m-d\TH:i:sP'));
    }

    public function testAuthenticatedAtParsesFractionalSeconds(): void
    {
        $clock = new FakeClock();
        $http = new FakeHttpClient([self::response(200, self::successXml('2026-10-27T11:59:00.123Z'))]);
        $driver = $this->driver($http, '3.0', false, $clock);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame('2026-10-27T11:59:00+00:00', $step->identity->authenticatedAt->format('Y-m-d\TH:i:sP'));
        $this->assertSame('123000', $step->identity->authenticatedAt->format('u'));
    }

    public function testAuthenticatedAtFallsBackToNowWhenTheDateIsMissing(): void
    {
        $clock = new FakeClock();
        $http = new FakeHttpClient([self::response(200, self::successXml())]);
        $driver = $this->driver($http, '3.0', false, $clock);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame('2026-10-27T12:00:00+00:00', $step->identity->authenticatedAt->format('Y-m-d\TH:i:sP'));
    }

    public function testAuthenticatedAtFallsBackToNowWhenTheDateIsGarbage(): void
    {
        $clock = new FakeClock();
        $http = new FakeHttpClient([self::response(200, self::successXml('not-a-date'))]);
        $driver = $this->driver($http, '3.0', false, $clock);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame('2026-10-27T12:00:00+00:00', $step->identity->authenticatedAt->format('Y-m-d\TH:i:sP'));
    }

    public function testAuthenticatedAtFallsBackToNowWhenTheDateIsTooFarInTheFuture(): void
    {
        $clock = new FakeClock();
        // The clock reads 2026-10-27T12:00:00+00:00; 12:06:00 is six minutes
        // ahead, past the five-minute guard.
        $http = new FakeHttpClient([self::response(200, self::successXml('2026-10-27T12:06:00Z'))]);
        $driver = $this->driver($http, '3.0', false, $clock);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame('2026-10-27T12:00:00+00:00', $step->identity->authenticatedAt->format('Y-m-d\TH:i:sP'));
    }

    public function testSuccessParsesUserAndAttributes(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame('ab1234', $step->identity->username);
        $this->assertSame('cas', $step->identity->driver);
        $this->assertSame(['ab1234@wayne.edu'], $step->identity->attributes['mail']);
        $this->assertCount(2, $step->identity->attributes['memberOf']);
    }

    public function testProtocolThreeUsesTheCas3ValidatePath(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http);

        $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));

        $this->assertSame('/cas/p3/serviceValidate', $http->requests[0]->getUri()->getPath());
    }

    public function testProtocolTwoUsesTheCas2ValidatePath(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http, '2.0');

        $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));

        $this->assertSame('/cas/serviceValidate', $http->requests[0]->getUri()->getPath());
    }

    public function testAReplayedTicketFailsOnTheSecondUse(): void
    {
        $http = new FakeHttpClient([
            self::response(200, self::fixture('success.xml')),
            self::response(200, self::fixture('failure-invalid-ticket.xml')),
        ]);
        $driver = $this->driver($http);
        $request = new ServerRequest('GET', self::SERVICE.'?ticket=ST-1');

        $first = $driver->login($request);

        $this->assertInstanceOf(Authenticated::class, $first);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('INVALID_TICKET');

        $driver->login($request);
    }

    public function testMalformedXmlThrowsAuthenticationFailed(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('malformed.xml'))]);
        $driver = $this->driver($http);

        $this->expectException(AuthenticationFailed::class);

        $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));
    }

    public function testXxeWithDoctypeThrowsAuthenticationFailed(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('xxe.xml'))]);
        $driver = $this->driver($http);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('DOCTYPE');

        $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));
    }

    public function testANestedSuccessIsNotAccepted(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('nested-success.xml'))]);
        $driver = $this->driver($http);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('neither a success nor a failure');

        $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));
    }

    public function testA500ResponseThrowsAuthenticationFailed(): void
    {
        $http = new FakeHttpClient([self::response(500, 'boom')]);
        $driver = $this->driver($http);

        $this->expectException(AuthenticationFailed::class);

        $driver->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));
    }

    public function testRejectsAnHttpBaseUrl(): void
    {
        $this->expectException(InvalidConfig::class);

        new CasDriver('http://cas.test/cas', self::SERVICE, new FakeHttpClient(), new Psr17Factory());
    }

    public function testRejectsAnHttpServiceUrl(): void
    {
        $this->expectException(InvalidConfig::class);

        new CasDriver(self::BASE, 'http://app.test/login', new FakeHttpClient(), new Psr17Factory());
    }

    public function testRejectsABaseUrlWithoutAHost(): void
    {
        $this->expectException(InvalidConfig::class);

        new CasDriver('https:///cas', self::SERVICE, new FakeHttpClient(), new Psr17Factory());
    }

    public function testRejectsABaseUrlWithCredentials(): void
    {
        $this->expectException(InvalidConfig::class);

        new CasDriver('https://user:pass@cas.test/cas', self::SERVICE, new FakeHttpClient(), new Psr17Factory());
    }

    public function testRejectsAnUnknownProtocol(): void
    {
        $this->expectException(InvalidConfig::class);

        new CasDriver(self::BASE, self::SERVICE, new FakeHttpClient(), new Psr17Factory(), '1.0');
    }

    public function testStripsOneTrailingSlashFromTheBaseUrl(): void
    {
        $http = new FakeHttpClient();
        $driver = new CasDriver(self::BASE.'/', self::SERVICE, $http, new Psr17Factory());

        $step = $driver->login(new ServerRequest('GET', self::SERVICE));

        $this->assertInstanceOf(Redirect::class, $step);
        $this->assertSame(self::BASE.'/login?service='.rawurlencode(self::SERVICE), $step->url);
    }

    public function testLogoutUrlPointsAtTheCasLogout(): void
    {
        $driver = $this->driver(new FakeHttpClient());

        $this->assertSame(
            self::BASE.'/logout?service='.rawurlencode('https://app.test/'),
            $driver->logoutUrl('https://app.test/'),
        );
    }

    public function testLogoutUrlKeepsAnHttpsReturnToOnTheServiceHost(): void
    {
        $driver = $this->driver(new FakeHttpClient());

        $this->assertSame(
            self::BASE.'/logout?service='.rawurlencode('https://app.test/dashboard'),
            $driver->logoutUrl('https://app.test/dashboard'),
        );
    }

    public function testLogoutUrlFallsBackToTheServiceUrlForAnOffHostReturnTo(): void
    {
        $driver = $this->driver(new FakeHttpClient());

        $this->assertSame(
            self::BASE.'/logout?service='.rawurlencode(self::SERVICE),
            $driver->logoutUrl('https://evil.test/'),
        );
    }

    public function testLogoutUrlFallsBackToTheServiceUrlForAnHttpReturnTo(): void
    {
        $driver = $this->driver(new FakeHttpClient());

        $this->assertSame(
            self::BASE.'/logout?service='.rawurlencode(self::SERVICE),
            $driver->logoutUrl('http://app.test/'),
        );
    }

    public function testLogoutUrlFallsBackToTheServiceUrlForARelativeReturnTo(): void
    {
        $driver = $this->driver(new FakeHttpClient());

        $this->assertSame(
            self::BASE.'/logout?service='.rawurlencode(self::SERVICE),
            $driver->logoutUrl('/dashboard'),
        );
    }

    public function testSesameLoginWithTheCasDriverRegeneratesAndStores(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http);
        $log = new EventLog();
        $session = new RecordingSession($log);
        $resolver = new RecordingResolver('the-user');
        $sesame = new Sesame(
            $driver,
            $resolver,
            $session,
            new Config(
                driver: 'cas',
                environment: 'testing',
                casBaseUrl: self::BASE,
                casServiceUrl: self::SERVICE,
            ),
        );

        $user = $sesame->login(new ServerRequest('GET', self::SERVICE.'?ticket=ST-1'));

        $this->assertSame('the-user', $user);
        $this->assertSame(
            [
                'regenerate',
                'put:sesame.user',
                'put:sesame.identity',
                'put:sesame.signed_in_at',
                'put:sesame.last_seen',
            ],
            $log->events,
        );
        $this->assertSame('the-user', $session->store['sesame.user']);
        $this->assertSame('ab1234', $resolver->identity->username);
    }
}
