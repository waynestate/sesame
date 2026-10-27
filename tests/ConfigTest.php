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
}
