<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Drivers\Cas\PendingLogin;
use Waynestate\Sesame\Drivers\CasDriver;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Mfa;
use Waynestate\Sesame\MfaResult;
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

    private function driver(FakeHttpClient $http, string $protocol = '3.0', bool $renew = false, bool $assumeMfa = false, ?ClockInterface $clock = null): CasDriver
    {
        // No session and binding off: these tests exercise ticket shape,
        // URLs, MFA facts and XML parsing, not login binding. The binding
        // itself is covered by the testLoginBinding* tests below.
        return new CasDriver(self::BASE, self::SERVICE, $http, new Psr17Factory(), $protocol, $renew, $assumeMfa, $clock, null, false);
    }

    private static function response(int $status, string $body): ResponseInterface
    {
        return (new Response())->withStatus($status)->withBody(Stream::create($body));
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/cas/' . $name);
    }

    /**
     * A CAS success response, with optional authenticationDate, isFromNewLogin
     * and longTermAuthenticationRequestTokenUsed attributes.
     */
    private static function successXml(?string $authenticationDate = null, ?string $isFromNewLogin = null, ?string $longTermToken = null): string
    {
        $date = $authenticationDate === null ? '' : '<authenticationDate>' . $authenticationDate . '</authenticationDate>' . "\n      ";
        $newLogin = $isFromNewLogin === null ? '' : '<isFromNewLogin>' . $isFromNewLogin . '</isFromNewLogin>' . "\n      ";
        $longTerm = $longTermToken === null ? '' : '<longTermAuthenticationRequestTokenUsed>' . $longTermToken . '</longTermAuthenticationRequestTokenUsed>' . "\n      ";

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<serviceResponse xmlns="http://www.yale.edu/tp/cas">' . "\n"
            . '  <authenticationSuccess>' . "\n"
            . '    <user>ab1234</user>' . "\n"
            . '    <attributes>' . "\n"
            . '      <mail>ab1234@wayne.edu</mail>' . "\n"
            . $date
            . $newLogin
            . $longTerm
            . '    </attributes>' . "\n"
            . '  </authenticationSuccess>' . "\n"
            . '</serviceResponse>' . "\n";
    }

    public function testNoTicketRedirectsToTheCasLogin(): void
    {
        $http = new FakeHttpClient();
        $driver = $this->driver($http);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE));

        self::assertInstanceOf(Redirect::class, $step);
        self::assertSame(
            self::BASE . '/login?service=' . rawurlencode(self::SERVICE),
            $step->url,
        );
        self::assertSame([], $http->requests);
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

        self::assertInstanceOf(Redirect::class, $redirect);
        self::assertStringContainsString(rawurlencode(self::SERVICE), $redirect->url);
        self::assertStringNotContainsString('evil.test', $redirect->url);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1', $headers));

        self::assertInstanceOf(Authenticated::class, $step);

        parse_str($http->requests[0]->getUri()->getQuery(), $query);

        self::assertSame(self::SERVICE, $query['service']);
        self::assertSame('ST-1', $query['ticket']);
    }

    #[DataProvider('badTickets')]
    public function testRejectsABadTicketBeforeAnyHttpCall(string $ticket): void
    {
        $http = new FakeHttpClient();
        $driver = $this->driver($http);

        try {
            $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=' . rawurlencode($ticket)));
            self::fail('Expected AuthenticationFailed.');
        } catch (AuthenticationFailed) {
            // Expected: the ticket fails the pattern before any HTTP call.
        }

        self::assertSame([], $http->requests);
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
            'a ticket that is too long' => ['ST-' . str_repeat('a', 254)],
        ];
    }

    public function testValidateUrlEncodesServiceAndTicket(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http);

        $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1.a_b-c'));

        self::assertSame(
            self::BASE . '/p3/serviceValidate?service=' . rawurlencode(self::SERVICE) . '&ticket=ST-1.a_b-c',
            $http->requests[0]->getUri()->__toString(),
        );
    }

    public function testLoginRedirectCarriesRenewWhenEnabled(): void
    {
        $http = new FakeHttpClient();
        $driver = $this->driver($http, '3.0', true);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE));

        self::assertInstanceOf(Redirect::class, $step);
        self::assertSame(
            self::BASE . '/login?' . http_build_query([
                'service' => self::SERVICE,
                'renew' => 'true',
            ], '', '&', PHP_QUERY_RFC3986),
            $step->url,
        );
        self::assertSame([], $http->requests);
    }

    public function testLoginRedirectHasNoRenewWhenDisabled(): void
    {
        $http = new FakeHttpClient();
        $driver = $this->driver($http);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE));

        self::assertInstanceOf(Redirect::class, $step);
        self::assertStringNotContainsString('renew', $step->url);
    }

    public function testValidateRequestCarriesRenewWhenEnabled(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http, '3.0', true);

        $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        parse_str($http->requests[0]->getUri()->getQuery(), $query);

        self::assertSame(self::SERVICE, $query['service']);
        self::assertSame('ST-1', $query['ticket']);
        self::assertSame('true', $query['renew']);
    }

    public function testValidateRequestHasNoRenewWhenDisabled(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http);

        $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        parse_str($http->requests[0]->getUri()->getQuery(), $query);

        self::assertArrayNotHasKey('renew', $query);
    }

    public function testRenewUrlsEncodeWithTheSameQueryBuilder(): void
    {
        $service = 'https://app.test/login?next=/a b&c=d';
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        // Binding off: this test is about URL encoding, not login binding.
        $driver = new CasDriver(self::BASE, $service, $http, new Psr17Factory(), '3.0', true, false, new FakeClock(), null, false);

        $redirect = $driver->login(new ServerRequest('GET', 'https://app.test/'));

        self::assertInstanceOf(Redirect::class, $redirect);
        self::assertSame(
            self::BASE . '/login?' . http_build_query([
                'service' => $service,
                'renew' => 'true',
            ], '', '&', PHP_QUERY_RFC3986),
            $redirect->url,
        );

        $driver->login(new ServerRequest('GET', 'https://app.test/?ticket=ST-1'));

        self::assertSame(
            self::BASE . '/p3/serviceValidate?' . http_build_query([
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
        $driver = $this->driver($http, '3.0', false, false, $clock);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame('2026-10-27T11:59:00+00:00', $step->identity->authenticatedAt->format('Y-m-d\TH:i:sP'));
    }

    public function testAuthenticatedAtParsesFractionalSeconds(): void
    {
        $clock = new FakeClock();
        $http = new FakeHttpClient([self::response(200, self::successXml('2026-10-27T11:59:00.123Z'))]);
        $driver = $this->driver($http, '3.0', false, false, $clock);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame('2026-10-27T11:59:00+00:00', $step->identity->authenticatedAt->format('Y-m-d\TH:i:sP'));
        self::assertSame('123000', $step->identity->authenticatedAt->format('u'));
    }

    public function testAuthenticatedAtFallsBackToNowWhenTheDateIsMissing(): void
    {
        $clock = new FakeClock();
        $http = new FakeHttpClient([self::response(200, self::successXml())]);
        $driver = $this->driver($http, '3.0', false, false, $clock);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame('2026-10-27T12:00:00+00:00', $step->identity->authenticatedAt->format('Y-m-d\TH:i:sP'));
    }

    public function testAuthenticatedAtFallsBackToNowWhenTheDateIsGarbage(): void
    {
        $clock = new FakeClock();
        $http = new FakeHttpClient([self::response(200, self::successXml('not-a-date'))]);
        $driver = $this->driver($http, '3.0', false, false, $clock);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame('2026-10-27T12:00:00+00:00', $step->identity->authenticatedAt->format('Y-m-d\TH:i:sP'));
    }

    public function testAuthenticatedAtFallsBackToNowWhenTheDateIsTooFarInTheFuture(): void
    {
        $clock = new FakeClock();
        // The clock reads 2026-10-27T12:00:00+00:00; 12:06:00 is six minutes
        // ahead, past the five-minute guard.
        $http = new FakeHttpClient([self::response(200, self::successXml('2026-10-27T12:06:00Z'))]);
        $driver = $this->driver($http, '3.0', false, false, $clock);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame('2026-10-27T12:00:00+00:00', $step->identity->authenticatedAt->format('Y-m-d\TH:i:sP'));
    }

    public function testAmrHasNoMfaWhenAssumeMfaIsOff(): void
    {
        $http = new FakeHttpClient([self::response(200, self::successXml())]);
        $driver = $this->driver($http);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame([], $step->identity->amr);
        self::assertNull($step->identity->authTime);
    }

    public function testAmrHasMfaWhenAssumeMfaIsOn(): void
    {
        $http = new FakeHttpClient([self::response(200, self::successXml())]);
        $driver = $this->driver($http, '3.0', false, true);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame(['mfa'], $step->identity->amr);
    }

    public function testAssumeMfaWithARecentAuthenticationDateSatisfiesMfa(): void
    {
        $clock = new FakeClock();
        $http = new FakeHttpClient([self::response(200, self::successXml('2026-10-27T11:59:00Z'))]);
        $driver = $this->driver($http, '3.0', false, true, $clock);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertInstanceOf(DateTimeImmutable::class, $step->identity->authTime);
        self::assertSame('2026-10-27T11:59:00+00:00', $step->identity->authTime->format('Y-m-d\TH:i:sP'));

        $result = Mfa::satisfied($step->identity, 28800, $clock->now());

        self::assertSame(MfaResult::SATISFIED, $result->reason);
        self::assertTrue($result->satisfied);
    }

    public function testAssumeMfaWithoutAnAuthenticationDateHasNoAuthTime(): void
    {
        $clock = new FakeClock();
        $http = new FakeHttpClient([self::response(200, self::successXml())]);
        $driver = $this->driver($http, '3.0', false, true, $clock);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertNull($step->identity->authTime);

        $result = Mfa::satisfied($step->identity, 28800, $clock->now());

        self::assertSame(MfaResult::NO_AUTH_TIME, $result->reason);
        self::assertFalse($result->satisfied);
    }

    public function testAssumeMfaWithAFutureAuthenticationDateHasNoAuthTime(): void
    {
        $clock = new FakeClock();
        // The clock reads 2026-10-27T12:00:00+00:00; 12:06:00 is six minutes
        // ahead, past the five-minute guard.
        $http = new FakeHttpClient([self::response(200, self::successXml('2026-10-27T12:06:00Z'))]);
        $driver = $this->driver($http, '3.0', false, true, $clock);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertNull($step->identity->authTime);

        $result = Mfa::satisfied($step->identity, 28800, $clock->now());

        self::assertSame(MfaResult::NO_AUTH_TIME, $result->reason);
    }

    public function testSuccessParsesUserAndAttributes(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame('ab1234', $step->identity->username);
        self::assertSame('cas', $step->identity->driver);
        self::assertSame(['ab1234@wayne.edu'], $step->identity->attributes['mail']);
        self::assertCount(2, $step->identity->attributes['memberOf']);
    }

    public function testReadsIsFromNewLoginAndTheLongTermTokenFlag(): void
    {
        // Present true and false: the flags are read from the attributes.
        $http = new FakeHttpClient([self::response(200, self::successXml(null, 'true', 'false'))]);
        $driver = $this->driver($http);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertTrue($step->identity->isFromNewLogin());
        self::assertFalse($step->identity->usedLongTermToken());

        // Present false for both.
        $http = new FakeHttpClient([self::response(200, self::successXml(null, 'false', 'true'))]);

        $step = $this->driver($http)->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertFalse($step->identity->isFromNewLogin());
        self::assertTrue($step->identity->usedLongTermToken());

        // Uppercase values count too: the comparison is case-insensitive.
        $http = new FakeHttpClient([self::response(200, self::successXml(null, 'TRUE', 'FALSE'))]);

        $step = $this->driver($http)->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertTrue($step->identity->isFromNewLogin());
        self::assertFalse($step->identity->usedLongTermToken());

        // Absent: the server released no flag, so the identity says nothing.
        $http = new FakeHttpClient([self::response(200, self::successXml())]);

        $step = $this->driver($http)->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertNull($step->identity->isFromNewLogin());
        self::assertNull($step->identity->usedLongTermToken());

        // A junk value is not a boolean: it answers null, never a guess.
        $http = new FakeHttpClient([self::response(200, self::successXml(null, 'yes', '1'))]);

        $step = $this->driver($http)->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertNull($step->identity->isFromNewLogin());
        self::assertNull($step->identity->usedLongTermToken());
    }

    public function testProtocolThreeUsesTheCas3ValidatePath(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http);

        $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertSame('/cas/p3/serviceValidate', $http->requests[0]->getUri()->getPath());
    }

    public function testProtocolTwoUsesTheCas2ValidatePath(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http, '2.0');

        $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertSame('/cas/serviceValidate', $http->requests[0]->getUri()->getPath());
    }

    public function testTheDefaultProtocolIsTwoAndValidatesAgainstServiceValidate(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        // Binding off: this test is about the default protocol path.
        $driver = new CasDriver(self::BASE, self::SERVICE, $http, new Psr17Factory(), '2.0', false, false, new FakeClock(), null, false);

        $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertSame('/cas/serviceValidate', $http->requests[0]->getUri()->getPath());
    }

    public function testAReplayedTicketFailsOnTheSecondUse(): void
    {
        $http = new FakeHttpClient([
            self::response(200, self::fixture('success.xml')),
            self::response(200, self::fixture('failure-invalid-ticket.xml')),
        ]);
        $driver = $this->driver($http);
        $request = new ServerRequest('GET', self::SERVICE . '?ticket=ST-1');

        $first = $driver->login($request);

        self::assertInstanceOf(Authenticated::class, $first);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('INVALID_TICKET');

        $driver->login($request);
    }

    public function testMalformedXmlThrowsAuthenticationFailed(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('malformed.xml'))]);
        $driver = $this->driver($http);

        $this->expectException(AuthenticationFailed::class);

        $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));
    }

    public function testXxeWithDoctypeThrowsAuthenticationFailed(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('xxe.xml'))]);
        $driver = $this->driver($http);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('DOCTYPE');

        $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));
    }

    public function testANestedSuccessIsNotAccepted(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('nested-success.xml'))]);
        $driver = $this->driver($http);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('neither a success nor a failure');

        $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));
    }

    public function testAWrongCasNamespaceIsNotAccepted(): void
    {
        // The "cas" prefix is bound to a different namespace URI. Only
        // elements in the real CAS namespace count, so this forged success
        // must be refused.
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<serviceResponse xmlns:cas="urn:other">' . "\n"
            . '  <cas:authenticationSuccess>' . "\n"
            . '    <cas:user>ab1234</cas:user>' . "\n"
            . '  </cas:authenticationSuccess>' . "\n"
            . '</serviceResponse>' . "\n";
        $http = new FakeHttpClient([self::response(200, $xml)]);
        $driver = $this->driver($http);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('neither a success nor a failure');

        $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));
    }

    public function testADifferentPrefixBoundToTheCasNamespaceIsAccepted(): void
    {
        // A different prefix bound to the real CAS namespace URI must keep
        // working: the driver matches by namespace, not by prefix.
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<serviceResponse xmlns:x="http://www.yale.edu/tp/cas">' . "\n"
            . '  <x:authenticationSuccess>' . "\n"
            . '    <x:user>ab1234</x:user>' . "\n"
            . '    <x:attributes>' . "\n"
            . '      <x:mail>ab1234@wayne.edu</x:mail>' . "\n"
            . '    </x:attributes>' . "\n"
            . '  </x:authenticationSuccess>' . "\n"
            . '</serviceResponse>' . "\n";
        $http = new FakeHttpClient([self::response(200, $xml)]);
        $driver = $this->driver($http);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame('ab1234', $step->identity->username);
        self::assertSame(['ab1234@wayne.edu'], $step->identity->attributes['mail']);
    }

    public function testAFailureElementWinsOverASuccessElement(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<serviceResponse xmlns="http://www.yale.edu/tp/cas">' . "\n"
            . '  <authenticationSuccess>' . "\n"
            . '    <user>ab1234</user>' . "\n"
            . '  </authenticationSuccess>' . "\n"
            . '  <authenticationFailure code="INVALID_TICKET">Ticket ST-1 not recognized</authenticationFailure>' . "\n"
            . '</serviceResponse>' . "\n";
        $http = new FakeHttpClient([self::response(200, $xml)]);
        $driver = $this->driver($http);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('INVALID_TICKET');

        $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));
    }

    public function testA500ResponseThrowsAuthenticationFailed(): void
    {
        $http = new FakeHttpClient([self::response(500, 'boom')]);
        $driver = $this->driver($http);

        $this->expectException(AuthenticationFailed::class);

        $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));
    }

    public function testAValidationRedirectIsRefusedAndNotFollowed(): void
    {
        // A 302 with a Location header and a valid success body: the driver
        // must refuse the non-200 answer and never follow the redirect.
        $http = new FakeHttpClient([
            self::response(302, self::successXml())->withHeader('Location', 'http://evil.test/steal'),
        ]);
        $driver = $this->driver($http);

        try {
            $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));
            self::fail('Expected AuthenticationFailed.');
        } catch (AuthenticationFailed $e) {
            self::assertStringNotContainsString('evil.test', $e->getMessage());
            self::assertStringNotContainsString('ST-1', $e->getMessage());
        }

        self::assertCount(1, $http->requests);
        self::assertSame('cas.test', $http->requests[0]->getUri()->getHost());
    }

    #[DataProvider('redirectStatuses')]
    public function testEveryRedirectStatusIsRefused(int $status): void
    {
        $http = new FakeHttpClient([
            self::response($status, self::successXml())->withHeader('Location', 'http://evil.test/steal'),
        ]);
        $driver = $this->driver($http);

        $this->expectException(AuthenticationFailed::class);

        $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));
    }

    /**
     * Every redirect status the CAS server or a proxy may answer with.
     *
     * @return array<string, array{int}>
     */
    public static function redirectStatuses(): array
    {
        return [
            '301 moved permanently' => [301],
            '302 found' => [302],
            '303 see other' => [303],
            '307 temporary redirect' => [307],
            '308 permanent redirect' => [308],
        ];
    }

    public function testATransportFailureIsNotSwallowedAndSignsNobodyIn(): void
    {
        // A PSR-18 client that fails the TLS handshake: the exception must
        // come straight back out of login(), unchanged, and nobody is signed
        // in.
        $exception = new class ('SSL: certificate subject name does not match target host') extends \RuntimeException implements ClientExceptionInterface {};

        $http = new class ($exception) implements ClientInterface {
            public function __construct(private readonly \Throwable $failure) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw $this->failure;
            }
        };

        // Binding off: this test is about transport failure propagation.
        $driver = new CasDriver(self::BASE, self::SERVICE, $http, new Psr17Factory(), '2.0', false, false, new FakeClock(), null, false);

        try {
            $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));
            self::fail('Expected the client exception to be rethrown.');
        } catch (ClientExceptionInterface $caught) {
            self::assertSame($exception, $caught);
        }
    }

    public function testTheValidationRequestGoesOnlyToTheConfiguredHttpsHost(): void
    {
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = $this->driver($http);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertCount(1, $http->requests);
        self::assertSame('https', $http->requests[0]->getUri()->getScheme());
        self::assertSame('cas.test', $http->requests[0]->getUri()->getHost());
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
        // Binding off: this test is about base URL normalization.
        $driver = new CasDriver(self::BASE . '/', self::SERVICE, $http, new Psr17Factory(), '2.0', false, false, new FakeClock(), null, false);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE));

        self::assertInstanceOf(Redirect::class, $step);
        self::assertSame(self::BASE . '/login?service=' . rawurlencode(self::SERVICE), $step->url);
    }

    public function testLogoutUrlPointsAtTheCasLogout(): void
    {
        $driver = $this->driver(new FakeHttpClient());

        self::assertSame(
            self::BASE . '/logout?service=' . rawurlencode('https://app.test/') . '&url=' . rawurlencode('https://app.test/'),
            $driver->logoutUrl('https://app.test/'),
        );
    }

    public function testLogoutUrlCarriesTheReturnAddressAsBothServiceAndUrl(): void
    {
        $driver = $this->driver(new FakeHttpClient());
        $url = $driver->logoutUrl('https://app.test/dashboard');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('https://app.test/dashboard', $query['service']);
        self::assertSame('https://app.test/dashboard', $query['url']);
    }

    public function testLogoutUrlKeepsAnHttpsReturnToOnTheServiceHost(): void
    {
        $driver = $this->driver(new FakeHttpClient());

        self::assertSame(
            self::BASE . '/logout?service=' . rawurlencode('https://app.test/dashboard') . '&url=' . rawurlencode('https://app.test/dashboard'),
            $driver->logoutUrl('https://app.test/dashboard'),
        );
    }

    public function testLogoutUrlFallsBackToTheServiceUrlForAnOffHostReturnTo(): void
    {
        $driver = $this->driver(new FakeHttpClient());

        self::assertSame(
            self::BASE . '/logout?service=' . rawurlencode(self::SERVICE) . '&url=' . rawurlencode(self::SERVICE),
            $driver->logoutUrl('https://evil.test/'),
        );
    }

    public function testLogoutUrlFallsBackToTheServiceUrlForAnHttpReturnTo(): void
    {
        $driver = $this->driver(new FakeHttpClient());

        self::assertSame(
            self::BASE . '/logout?service=' . rawurlencode(self::SERVICE) . '&url=' . rawurlencode(self::SERVICE),
            $driver->logoutUrl('http://app.test/'),
        );
    }

    public function testLogoutUrlFallsBackToTheServiceUrlForARelativeReturnTo(): void
    {
        $driver = $this->driver(new FakeHttpClient());

        self::assertSame(
            self::BASE . '/logout?service=' . rawurlencode(self::SERVICE) . '&url=' . rawurlencode(self::SERVICE),
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

        $user = $sesame->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertSame('the-user', $user);
        self::assertSame(
            [
                'regenerate',
                'put:sesame.user',
                'put:sesame.identity',
                'put:sesame.signed_in_at',
                'put:sesame.last_seen',
                'put:sesame.session_ref',
            ],
            $log->events,
        );
        self::assertSame('the-user', $session->store['sesame.user']);
        self::assertInstanceOf(Identity::class, $resolver->identity);
        self::assertSame('ab1234', $resolver->identity->username);
    }

    public function testLoginBindingRejectsATicketWithNoPendingLogin(): void
    {
        // A ticket arrives but this browser never started a sign-in: the
        // login CSRF case. It is refused before any HTTP call.
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $session = new RecordingSession(new EventLog());
        $driver = new CasDriver(self::BASE, self::SERVICE, $http, new Psr17Factory(), '3.0', false, false, new FakeClock(), $session);

        try {
            $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));
            self::fail('Expected AuthenticationFailed.');
        } catch (AuthenticationFailed $e) {
            self::assertSame(
                'This sign-in was not started in this browser, or it took too long. Start again from the app.',
                $e->getMessage(),
            );
        }

        self::assertSame([], $http->requests, 'No validation call when there is no pending login.');
    }

    public function testLoginBindingRejectsAnExpiredMarker(): void
    {
        $clock = new FakeClock();
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $session = new RecordingSession(new EventLog());
        $driver = new CasDriver(self::BASE, self::SERVICE, $http, new Psr17Factory(), '3.0', false, false, $clock, $session);

        $redirect = $driver->login(new ServerRequest('GET', self::SERVICE));

        self::assertInstanceOf(Redirect::class, $redirect);

        // The marker lives ten minutes; past that it is gone.
        $clock->advance(601);

        try {
            $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));
            self::fail('Expected AuthenticationFailed.');
        } catch (AuthenticationFailed $e) {
            self::assertSame(
                'This sign-in was not started in this browser, or it took too long. Start again from the app.',
                $e->getMessage(),
            );
        }

        self::assertSame([], $http->requests, 'No validation call for an expired marker.');
    }

    public function testLoginBindingRejectsAReusedMarker(): void
    {
        // The marker is single use: the first ticket spends it, so a second
        // ticket finds nothing even though the CAS server would still
        // answer.
        $http = new FakeHttpClient([
            self::response(200, self::fixture('success.xml')),
            self::response(200, self::fixture('success.xml')),
        ]);
        $session = new RecordingSession(new EventLog());
        $driver = new CasDriver(self::BASE, self::SERVICE, $http, new Psr17Factory(), '3.0', false, false, new FakeClock(), $session);

        $driver->login(new ServerRequest('GET', self::SERVICE));

        $first = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $first);

        try {
            $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-2'));
            self::fail('Expected AuthenticationFailed.');
        } catch (AuthenticationFailed $e) {
            self::assertSame(
                'This sign-in was not started in this browser, or it took too long. Start again from the app.',
                $e->getMessage(),
            );
        }

        self::assertCount(1, $http->requests, 'The second ticket never reaches the CAS server.');
    }

    public function testLoginBindingAcceptsTheBrowserThatStarted(): void
    {
        // The natural flow: the app sends the browser to CAS, the browser
        // comes back with a ticket, and the marker says this browser did
        // start the sign-in.
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $session = new RecordingSession(new EventLog());
        $driver = new CasDriver(self::BASE, self::SERVICE, $http, new Psr17Factory(), '3.0', false, false, new FakeClock(), $session);

        $redirect = $driver->login(new ServerRequest('GET', self::SERVICE));

        self::assertInstanceOf(Redirect::class, $redirect);
        self::assertArrayHasKey(PendingLogin::SESSION_KEY, $session->store, 'The redirect stores the marker.');

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame('ab1234', $step->identity->username);
        self::assertArrayNotHasKey(PendingLogin::SESSION_KEY, $session->store, 'The marker is spent.');
        self::assertCount(1, $http->requests);
    }

    public function testTheLoginBindingKeepsAtMostFiveMarkers(): void
    {
        // Six tabs, six redirects: the session keeps only the five newest
        // markers, so a browser cannot grow the session without bound.
        $http = new FakeHttpClient();
        $session = new RecordingSession(new EventLog());
        $driver = new CasDriver(self::BASE, self::SERVICE, $http, new Psr17Factory(), '3.0', false, false, new FakeClock(), $session);

        for ($i = 0; $i < 6; $i++) {
            $step = $driver->login(new ServerRequest('GET', self::SERVICE));
            self::assertInstanceOf(Redirect::class, $step);
        }

        $pending = $session->store[PendingLogin::SESSION_KEY];

        self::assertIsArray($pending);
        self::assertCount(5, $pending);

        foreach ($pending as $marker) {
            self::assertIsArray($marker);

            $id = $marker['id'];
            $expires = $marker['expires'];

            self::assertIsString($id);
            self::assertIsInt($expires);
            self::assertSame(32, strlen($id), 'The marker id is 16 random bytes in hex.');
        }
    }

    public function testBindingCanBeTurnedOffWithoutASession(): void
    {
        // With require_pending_login off the driver boots without a session
        // and accepts a ticket exactly as before the binding existed.
        $http = new FakeHttpClient([self::response(200, self::fixture('success.xml'))]);
        $driver = new CasDriver(self::BASE, self::SERVICE, $http, new Psr17Factory(), '3.0', false, false, new FakeClock(), null, false);

        $step = $driver->login(new ServerRequest('GET', self::SERVICE . '?ticket=ST-1'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertCount(1, $http->requests);
    }

    public function testTheDriverRefusesToStartWithoutASessionWhenBindingIsOn(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage(
            'The cas driver needs the app session to bind each sign-in to the browser. Pass $session to DriverFactory::make(), or set cas.require_pending_login to false.',
        );

        new CasDriver(self::BASE, self::SERVICE, new FakeHttpClient(), new Psr17Factory());
    }
}
