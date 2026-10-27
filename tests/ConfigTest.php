<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\Exception\InvalidConfig;

final class ConfigTest extends TestCase
{
    public function testRejectsAMissingDriver(): void
    {
        $this->expectException(InvalidConfig::class);

        Config::fromArray(['environment' => 'local']);
    }

    public function testRejectsAnEmptyDriver(): void
    {
        $this->expectException(InvalidConfig::class);

        Config::fromArray(['driver' => '', 'environment' => 'local']);
    }

    public function testRejectsAMissingEnvironment(): void
    {
        $this->expectException(InvalidConfig::class);

        Config::fromArray(['driver' => 'fake', 'fake' => ['username' => 'jdoe']]);
    }

    public function testRejectsAnEmptyEnvironment(): void
    {
        $this->expectException(InvalidConfig::class);

        Config::fromArray(['driver' => 'fake', 'environment' => '', 'fake' => ['username' => 'jdoe']]);
    }

    public function testReadsTheFakeSettings(): void
    {
        $config = Config::fromArray([
            'driver' => 'fake',
            'environment' => 'local',
            'fake' => [
                'username' => 'jdoe',
                'attributes' => ['cn' => ['John Doe']],
            ],
        ]);

        $this->assertSame('fake', $config->driver);
        $this->assertSame('local', $config->environment);
        $this->assertSame('jdoe', $config->fakeUsername);
        $this->assertSame(['cn' => ['John Doe']], $config->fakeAttributes);
    }

    public function testFakeAttributesDefaultToEmpty(): void
    {
        $config = Config::fromArray([
            'driver' => 'fake',
            'environment' => 'local',
            'fake' => ['username' => 'jdoe'],
        ]);

        $this->assertSame([], $config->fakeAttributes);
    }

    public function testRejectsAMissingFakeUsername(): void
    {
        $this->expectException(InvalidConfig::class);

        Config::fromArray(['driver' => 'fake', 'environment' => 'local']);
    }

    public function testRejectsANonArrayFakeAttributes(): void
    {
        $this->expectException(InvalidConfig::class);

        Config::fromArray([
            'driver' => 'fake',
            'environment' => 'local',
            'fake' => ['username' => 'jdoe', 'attributes' => 'nope'],
        ]);
    }

    public function testReadsTheCasSettings(): void
    {
        $config = Config::fromArray([
            'driver' => 'cas',
            'environment' => 'local',
            'cas' => [
                'base_url' => 'https://cas.test/cas',
                'service_url' => 'https://app.test/login',
                'protocol' => '2.0',
            ],
        ]);

        $this->assertSame('cas', $config->driver);
        $this->assertSame('https://cas.test/cas', $config->casBaseUrl);
        $this->assertSame('https://app.test/login', $config->casServiceUrl);
        $this->assertSame('2.0', $config->casProtocol);
    }

    public function testCasProtocolDefaultsToThreeZero(): void
    {
        $config = Config::fromArray([
            'driver' => 'cas',
            'environment' => 'local',
            'cas' => [
                'base_url' => 'https://cas.test/cas',
                'service_url' => 'https://app.test/login',
            ],
        ]);

        $this->assertSame('3.0', $config->casProtocol);
    }

    public function testRejectsAMissingCasBaseUrl(): void
    {
        $this->expectException(InvalidConfig::class);

        Config::fromArray([
            'driver' => 'cas',
            'environment' => 'local',
            'cas' => ['service_url' => 'https://app.test/login'],
        ]);
    }

    public function testRejectsAMissingCasServiceUrl(): void
    {
        $this->expectException(InvalidConfig::class);

        Config::fromArray([
            'driver' => 'cas',
            'environment' => 'local',
            'cas' => ['base_url' => 'https://cas.test/cas'],
        ]);
    }

    public function testRejectsAnInvalidCasProtocol(): void
    {
        $this->expectException(InvalidConfig::class);

        Config::fromArray([
            'driver' => 'cas',
            'environment' => 'local',
            'cas' => [
                'base_url' => 'https://cas.test/cas',
                'service_url' => 'https://app.test/login',
                'protocol' => '1.0',
            ],
        ]);
    }
}
