<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Drivers\FakeDriver;
use Waynestate\Sesame\Exception\FakeDriverInProduction;

final class FakeDriverTest extends TestCase
{
    #[DataProvider('productionEnvironments')]
    public function testRefusesProduction(string $environment): void
    {
        $this->expectException(FakeDriverInProduction::class);

        new FakeDriver($environment, 'jdoe', []);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function productionEnvironments(): array
    {
        return [
            'lowercase' => ['production'],
            'titlecase' => ['Production'],
            'padded' => [' production '],
        ];
    }

    #[DataProvider('nonProductionEnvironments')]
    public function testAcceptsNonProduction(string $environment): void
    {
        $driver = new FakeDriver($environment, 'jdoe', []);

        self::assertSame('fake', $driver->name());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonProductionEnvironments(): array
    {
        return [
            'local' => ['local'],
            'testing' => ['testing'],
        ];
    }

    #[DataProvider('nonAllowedEnvironments')]
    public function testRefusesNonAllowedEnvironments(string $environment): void
    {
        $this->expectException(FakeDriverInProduction::class);

        new FakeDriver($environment, 'jdoe', []);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonAllowedEnvironments(): array
    {
        return [
            'prod' => ['prod'],
            'live' => ['live'],
            'staging' => ['staging'],
            'qa' => ['qa'],
            'unknown' => ['unknown'],
        ];
    }

    #[DataProvider('developmentEnvironments')]
    public function testAcceptsDevelopmentEnvironments(string $environment): void
    {
        $driver = new FakeDriver($environment, 'jdoe', []);

        self::assertSame('fake', $driver->name());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function developmentEnvironments(): array
    {
        return [
            'development' => ['development'],
            'dev' => ['dev'],
            'test' => ['test'],
        ];
    }

    public function testLoginAlwaysReturnsAuthenticated(): void
    {
        $driver = new FakeDriver('local', 'jdoe', ['cn' => ['John Doe']]);

        $step = $driver->login(new ServerRequest('GET', 'http://app.local/login'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame('jdoe', $step->identity->username);
        self::assertSame('fake', $step->identity->driver);
        self::assertSame('John Doe', $step->identity->attribute('cn'));
    }

    public function testLogoutUrlIsNull(): void
    {
        $driver = new FakeDriver('local', 'jdoe', []);

        self::assertNull($driver->logoutUrl('http://app.local/'));
    }
}
