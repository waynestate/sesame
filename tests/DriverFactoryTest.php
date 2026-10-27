<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Drivers\FakeDriver;
use Waynestate\Sesame\Exception\InvalidConfig;

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

    public function testRejectsCasWithALaterReleaseMessage(): void
    {
        $config = Config::fromArray(['driver' => 'cas', 'environment' => 'local']);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessageMatches('/cas.*later release/');

        DriverFactory::make($config);
    }

    public function testNamesTheUnknownDriver(): void
    {
        $config = Config::fromArray(['driver' => 'saml', 'environment' => 'local']);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('Unknown driver "saml". The cas driver arrives in a later release.');

        DriverFactory::make($config);
    }
}
