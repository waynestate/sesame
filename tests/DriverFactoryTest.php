<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Drivers\CasDriver;
use Waynestate\Sesame\Drivers\FakeDriver;
use Waynestate\Sesame\Exception\InvalidConfig;
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

        $this->assertInstanceOf(FakeDriver::class, $driver);
        $this->assertSame('fake', $driver->name());
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

        $this->assertInstanceOf(CasDriver::class, $driver);
        $this->assertSame('cas', $driver->name());
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
}
