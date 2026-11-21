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
        $driver = new FakeDriver($environment, 'jdoe', [], true);

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
    public function testRefusesDevelopmentEnvironmentsEvenWithOptIn(string $environment): void
    {
        $this->expectException(FakeDriverInProduction::class);

        new FakeDriver($environment, 'jdoe', [], true);
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

    public function test_refuses_without_opt_in(): void
    {
        $this->expectException(FakeDriverInProduction::class);
        $this->expectExceptionMessage('The fake driver runs only when the environment is local or testing and SESAME_FAKE_ALLOWED is true.');

        new FakeDriver('local', 'jdoe', []);
    }

    public function test_refuses_development_even_with_opt_in(): void
    {
        $this->expectException(FakeDriverInProduction::class);

        new FakeDriver('development', 'jdoe', [], true);
    }

    public function test_refuses_production_with_opt_in(): void
    {
        $this->expectException(FakeDriverInProduction::class);

        new FakeDriver('production', 'jdoe', [], true);
    }

    public function test_boots_in_local_with_opt_in(): void
    {
        $driver = new FakeDriver('local', 'jdoe', [], true);

        self::assertSame('fake', $driver->name());
    }

    public function test_boots_in_testing_with_opt_in(): void
    {
        $driver = new FakeDriver('testing', 'jdoe', [], true);

        self::assertSame('fake', $driver->name());
    }

    public function testLoginAlwaysReturnsAuthenticated(): void
    {
        $driver = new FakeDriver('local', 'jdoe', ['cn' => ['John Doe']], true);

        $step = $driver->login(new ServerRequest('GET', 'http://app.local/login'));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame('jdoe', $step->identity->username);
        self::assertSame('fake', $step->identity->driver);
        self::assertSame('John Doe', $step->identity->attribute('cn'));
    }

    public function testLogoutUrlIsNull(): void
    {
        $driver = new FakeDriver('local', 'jdoe', [], true);

        self::assertNull($driver->logoutUrl('http://app.local/'));
    }
}
