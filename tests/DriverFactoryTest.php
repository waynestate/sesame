<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Drivers\CasDriver;
use Waynestate\Sesame\Drivers\FakeDriver;
use Waynestate\Sesame\Drivers\GatewayDriver;
use Waynestate\Sesame\Drivers\InMemoryReplayGuard;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\FakeHttpClient;

final class DriverFactoryTest extends TestCase
{
    public function testMakesAFakeDriver(): void
    {
        $config = Config::fromArray([
            'driver' => 'fake',
            'environment' => 'local',
            'fake' => ['username' => 'jdoe'],
        ]);

        $driver = DriverFactory::make($config);

        self::assertInstanceOf(FakeDriver::class, $driver);
        self::assertSame('fake', $driver->name());
    }

    public function testMakesACasDriver(): void
    {
        $config = Config::fromArray([
            'driver' => 'cas',
            'environment' => 'local',
            'cas' => [
                'base_url' => 'https://cas.test/cas',
                'service_url' => 'https://app.test/login',
            ],
        ]);

        $driver = DriverFactory::make($config, new FakeHttpClient(), new Psr17Factory());

        self::assertInstanceOf(CasDriver::class, $driver);
        self::assertSame('cas', $driver->name());
    }

    public function testCasWithoutAnHttpClientNamesTheMissingClient(): void
    {
        $config = $this->casConfig();

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('PSR-18 client');

        DriverFactory::make($config);
    }

    public function testCasWithoutARequestFactoryNamesTheMissingFactory(): void
    {
        $config = $this->casConfig();

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('PSR-17 request factory');

        DriverFactory::make($config, new FakeHttpClient());
    }

    public function testNamesTheUnknownDriver(): void
    {
        $config = Config::fromArray(['driver' => 'saml', 'environment' => 'local']);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('Unknown driver "saml".');

        DriverFactory::make($config);
    }

    public function testMakesAGatewayDriver(): void
    {
        $config = Config::fromArray($this->gatewayConfig());

        $driver = DriverFactory::make($config, null, null, new FakeClock(), new InMemoryReplayGuard());

        self::assertInstanceOf(GatewayDriver::class, $driver);
        self::assertSame('gateway', $driver->name());
    }

    public function testGatewayWithoutAReplayGuardFailsClosed(): void
    {
        $config = Config::fromArray($this->gatewayConfig());

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('ReplayGuard');

        DriverFactory::make($config);
    }

    public function testGatewayBootsWithoutAReplayGuardWhenAllowed(): void
    {
        $config = Config::fromArray($this->gatewayConfig(['allow_without_replay_guard' => true]));

        $driver = DriverFactory::make($config);

        self::assertInstanceOf(GatewayDriver::class, $driver);
    }

    /**
     * The gateway's public key, issuer and audience must be non-empty strings.
     *
     * Config::fromArray() already rejects these, so the factory's own guard is
     * exercised by building a Config directly with the value left empty or
     * missing.
     *
     * @return array<string, array{0: string, 1: string|null, 2: string|null, 3: string|null}>
     */
    public static function gatewayEmptyOrMissingKeyProvider(): array
    {
        return [
            'empty public key' => ['gateway.public_key', '', 'https://drawbridge.wayne.edu', 'sesame'],
            'missing public key' => ['gateway.public_key', null, 'https://drawbridge.wayne.edu', 'sesame'],
            'empty issuer' => ['gateway.issuer', 'public-key', '', 'sesame'],
            'missing issuer' => ['gateway.issuer', 'public-key', null, 'sesame'],
            'empty audience' => ['gateway.audience', 'public-key', 'https://drawbridge.wayne.edu', ''],
            'missing audience' => ['gateway.audience', 'public-key', 'https://drawbridge.wayne.edu', null],
        ];
    }

    #[DataProvider('gatewayEmptyOrMissingKeyProvider')]
    public function testGatewayWithAnEmptyOrMissingKeyNamesTheKey(
        string $key,
        ?string $publicKey,
        ?string $issuer,
        ?string $audience,
    ): void {
        $config = new Config(
            driver: 'gateway',
            environment: 'local',
            gatewayPublicKey: $publicKey,
            gatewayIssuer: $issuer,
            gatewayAudience: $audience,
            gatewayTrustedProxies: ['10.0.0.0/8'],
        );

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage(sprintf('The config key "%s" must be a non-empty string.', $key));

        DriverFactory::make($config);
    }

    private function casConfig(): Config
    {
        return Config::fromArray([
            'driver' => 'cas',
            'environment' => 'local',
            'cas' => [
                'base_url' => 'https://cas.test/cas',
                'service_url' => 'https://app.test/login',
            ],
        ]);
    }

    /**
     * A valid gateway config, with any overrides merged into the gateway
     * section.
     *
     * @param  array<string, mixed>  $gateway
     * @return array<string, mixed>
     */
    private function gatewayConfig(array $gateway = []): array
    {
        return [
            'driver' => 'gateway',
            'environment' => 'local',
            'gateway' => array_merge([
                'public_key' => 'public-key',
                'issuer' => 'https://drawbridge.wayne.edu',
                'audience' => 'sesame',
                'trusted_proxies' => ['10.0.0.0/8'],
            ], $gateway),
        ];
    }
}
