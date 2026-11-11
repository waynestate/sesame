<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Drivers\CasDriver;
use Waynestate\Sesame\Drivers\LocalEnvironments;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Tests\Support\FakeHttpClient;

/**
 * D-089: plain http to localhost is a developer's own machine, so the CAS
 * driver accepts it only when the app environment is local. Everywhere else
 * it gets today's refusal, word for word. Nothing here talks to a real host:
 * the HTTP client is a fake that never leaves the building.
 */
final class CasLocalHttpTest extends TestCase
{
    private const BASE = 'https://cas.example.test/cas';

    private const REFUSED = 'The config key "%s" must be an absolute https:// URL, got "%s".';

    private function driver(string $baseUrl, string $serviceUrl, ?string $environment = null): CasDriver
    {
        $http = new FakeHttpClient();
        $requests = new Psr17Factory();

        // No session and binding off: this file is about the address rule.
        return $environment === null
            ? new CasDriver($baseUrl, $serviceUrl, $http, $requests, '2.0', false, false, null, null, false)
            : new CasDriver($baseUrl, $serviceUrl, $http, $requests, '2.0', false, false, null, null, false, $environment);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localEnvironments(): iterable
    {
        foreach (LocalEnvironments::ALLOWED as $environment) {
            yield $environment => [$environment];
        }

        yield 'upper case' => ['LOCAL'];
        yield 'padded' => [' local '];
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function otherEnvironments(): iterable
    {
        yield 'production' => ['production'];
        yield 'prod' => ['prod'];
        yield 'staging' => ['staging'];
        yield 'empty' => [''];
        yield 'left out' => [null];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localhostServiceUrls(): iterable
    {
        yield 'localhost with a port' => ['http://localhost:8080/login'];
        yield 'loopback address' => ['http://127.0.0.1/login'];
        yield 'localhost in capitals' => ['http://LOCALHOST/login'];
    }

    public function testTheSharedListIsTheFakeDriversList(): void
    {
        self::assertSame(['local', 'development', 'dev', 'testing', 'test'], LocalEnvironments::ALLOWED);
    }

    #[DataProvider('localEnvironments')]
    public function testALocalEnvironmentAcceptsAnHttpLocalhostServiceUrl(string $environment): void
    {
        $driver = $this->driver(self::BASE, 'http://localhost:8080/login', $environment);

        self::assertSame('http://localhost:8080/login', $driver->serviceUrl());
    }

    #[DataProvider('localhostServiceUrls')]
    public function testEveryLocalhostSpellingIsAcceptedLocally(string $serviceUrl): void
    {
        self::assertSame($serviceUrl, $this->driver(self::BASE, $serviceUrl, 'local')->serviceUrl());
    }

    public function testTheLoginRedirectCarriesTheLocalServiceUrl(): void
    {
        $step = $this->driver(self::BASE, 'http://localhost:8080/login', 'local')->login(new ServerRequest('GET', 'http://localhost:8080/login'));

        self::assertInstanceOf(Redirect::class, $step);
        self::assertSame(
            'https://cas.example.test/cas/login?service=' . rawurlencode('http://localhost:8080/login'),
            $step->url,
        );
    }

    public function testALocalEnvironmentAcceptsAnHttpLocalhostCasServer(): void
    {
        $driver = $this->driver('http://localhost:8443/cas/', 'http://localhost:8080/login', 'local');

        $return = rawurlencode('http://localhost:8080/');

        self::assertSame('http://localhost:8443/cas/logout?service=' . $return . '&url=' . $return, $driver->logoutUrl('http://localhost:8080/'));
    }

    #[DataProvider('otherEnvironments')]
    public function testOtherEnvironmentsRefuseAnHttpLocalhostServiceUrlAsToday(?string $environment): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage(sprintf(self::REFUSED, 'cas.service_url', 'http://localhost:8080/login'));

        $this->driver(self::BASE, 'http://localhost:8080/login', $environment);
    }

    #[DataProvider('otherEnvironments')]
    public function testOtherEnvironmentsRefuseAnHttpLocalhostCasServerAsToday(?string $environment): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage(sprintf(self::REFUSED, 'cas.base_url', 'http://127.0.0.1/cas'));

        $this->driver('http://127.0.0.1/cas', 'https://app.example.test/login', $environment);
    }

    public function testALocalEnvironmentStillRefusesHttpToARealHost(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage(sprintf(self::REFUSED, 'cas.service_url', 'http://app.example.test/login'));

        $this->driver(self::BASE, 'http://app.example.test/login', 'local');
    }

    public function testALocalEnvironmentStillRefusesHttpToARealCasServer(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage(sprintf(self::REFUSED, 'cas.base_url', 'http://cas.example.test/cas'));

        $this->driver('http://cas.example.test/cas', 'https://app.example.test/login', 'local');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stillRefusedLocally(): iterable
    {
        yield 'credentials' => ['http://user:pw@localhost/login'];
        yield 'ipv6 loopback' => ['http://[::1]/login'];
        yield 'localhost as a subdomain' => ['http://localhost.example.test/login'];
        yield 'another scheme' => ['ftp://localhost/login'];
        yield 'no scheme' => ['localhost/login'];
    }

    #[DataProvider('stillRefusedLocally')]
    public function testALocalEnvironmentRefusesEverythingElse(string $serviceUrl): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage(sprintf(self::REFUSED, 'cas.service_url', $serviceUrl));

        $this->driver(self::BASE, $serviceUrl, 'local');
    }

    public function testARealHostIsUnchangedInEveryEnvironment(): void
    {
        foreach (['local', 'production'] as $environment) {
            $driver = $this->driver(self::BASE, 'https://app.example.test/login', $environment);

            self::assertSame('https://app.example.test/login', $driver->serviceUrl());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(string $environment): array
    {
        return [
            'driver' => 'cas',
            'environment' => $environment,
            'cas' => [
                'base_url' => self::BASE,
                'service_url' => 'http://localhost:8080/login',
                'require_pending_login' => false,
            ],
        ];
    }

    public function testTheFactoryPassesALocalEnvironmentThrough(): void
    {
        $driver = DriverFactory::make(Config::fromArray(self::config('local')), new FakeHttpClient(), new Psr17Factory());

        self::assertInstanceOf(CasDriver::class, $driver);
        self::assertSame('http://localhost:8080/login', $driver->serviceUrl());
    }

    public function testTheFactoryRefusesHttpLocalhostInProduction(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage(sprintf(self::REFUSED, 'cas.service_url', 'http://localhost:8080/login'));

        DriverFactory::make(Config::fromArray(self::config('production')), new FakeHttpClient(), new Psr17Factory());
    }
}
