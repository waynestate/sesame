<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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

        self::assertSame('fake', $config->driver);
        self::assertSame('local', $config->environment);
        self::assertSame('jdoe', $config->fakeUsername);
        self::assertSame(['cn' => ['John Doe']], $config->fakeAttributes);
    }

    public function testFakeAttributesDefaultToEmpty(): void
    {
        $config = Config::fromArray([
            'driver' => 'fake',
            'environment' => 'local',
            'fake' => ['username' => 'jdoe'],
        ]);

        self::assertSame([], $config->fakeAttributes);
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

        self::assertSame('cas', $config->driver);
        self::assertSame('https://cas.test/cas', $config->casBaseUrl);
        self::assertSame('https://app.test/login', $config->casServiceUrl);
        self::assertSame('2.0', $config->casProtocol);
    }

    public function testCasProtocolDefaultsToTwoZero(): void
    {
        $config = Config::fromArray([
            'driver' => 'cas',
            'environment' => 'local',
            'cas' => [
                'base_url' => 'https://cas.test/cas',
                'service_url' => 'https://app.test/login',
            ],
        ]);

        self::assertSame('2.0', $config->casProtocol);
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

    public function testReadsCasRenewWhenSet(): void
    {
        $config = Config::fromArray([
            'driver' => 'cas',
            'environment' => 'local',
            'cas' => [
                'base_url' => 'https://cas.test/cas',
                'service_url' => 'https://app.test/login',
                'renew' => true,
            ],
        ]);

        self::assertTrue($config->casRenew);
    }

    public function testCasRenewDefaultsToFalse(): void
    {
        $config = Config::fromArray([
            'driver' => 'cas',
            'environment' => 'local',
            'cas' => [
                'base_url' => 'https://cas.test/cas',
                'service_url' => 'https://app.test/login',
            ],
        ]);

        self::assertFalse($config->casRenew);
    }

    public function testRejectsANonBooleanCasRenew(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('The config key "cas.renew" must be a boolean.');

        Config::fromArray([
            'driver' => 'cas',
            'environment' => 'local',
            'cas' => [
                'base_url' => 'https://cas.test/cas',
                'service_url' => 'https://app.test/login',
                'renew' => 'yes',
            ],
        ]);
    }

    public function testReadsCasAssumeMfaWhenSet(): void
    {
        $config = Config::fromArray([
            'driver' => 'cas',
            'environment' => 'local',
            'cas' => [
                'base_url' => 'https://cas.test/cas',
                'service_url' => 'https://app.test/login',
                'assume_mfa' => true,
            ],
        ]);

        self::assertTrue($config->casAssumeMfa);
    }

    public function testCasAssumeMfaDefaultsToFalse(): void
    {
        $config = Config::fromArray([
            'driver' => 'cas',
            'environment' => 'local',
            'cas' => [
                'base_url' => 'https://cas.test/cas',
                'service_url' => 'https://app.test/login',
            ],
        ]);

        self::assertFalse($config->casAssumeMfa);
    }

    public function testRejectsANonBooleanCasAssumeMfa(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('The config key "cas.assume_mfa" must be a boolean.');

        Config::fromArray([
            'driver' => 'cas',
            'environment' => 'local',
            'cas' => [
                'base_url' => 'https://cas.test/cas',
                'service_url' => 'https://app.test/login',
                'assume_mfa' => 'yes',
            ],
        ]);
    }

    public function testGatewayMaxLifetimeDefaultsToFiveMinutes(): void
    {
        $config = Config::fromArray($this->gatewayConfig());

        self::assertSame(300, $config->gatewayMaxLifetimeSeconds);
    }

    public function testReadsGatewayMaxLifetime(): void
    {
        $config = Config::fromArray($this->gatewayConfig(['max_lifetime' => 60]));

        self::assertSame(60, $config->gatewayMaxLifetimeSeconds);
    }

    #[DataProvider('badGatewayMaxLifetimes')]
    public function testRejectsAnInvalidGatewayMaxLifetime(int|string $value): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('The config key "gateway.max_lifetime" must be a positive integer.');

        Config::fromArray($this->gatewayConfig(['max_lifetime' => $value]));
    }

    public function testGatewayAllowWithoutReplayGuardDefaultsToFalse(): void
    {
        $config = Config::fromArray($this->gatewayConfig());

        self::assertFalse($config->gatewayAllowWithoutReplayGuard);
    }

    public function testReadsGatewayAllowWithoutReplayGuard(): void
    {
        $config = Config::fromArray($this->gatewayConfig(['allow_without_replay_guard' => true]));

        self::assertTrue($config->gatewayAllowWithoutReplayGuard);
    }

    public function testRejectsANonBooleanGatewayAllowWithoutReplayGuard(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('The config key "gateway.allow_without_replay_guard" must be a boolean.');

        Config::fromArray($this->gatewayConfig(['allow_without_replay_guard' => 'yes']));
    }

    public function testReadsTheSessionTimeouts(): void
    {
        $config = Config::fromArray([
            'driver' => 'fake',
            'environment' => 'local',
            'fake' => ['username' => 'jdoe'],
            'session' => [
                'idle_minutes' => 30,
                'absolute_minutes' => 1440,
            ],
        ]);

        self::assertSame(30, $config->sessionIdleMinutes);
        self::assertSame(1440, $config->sessionAbsoluteMinutes);
    }

    public function testSessionTimeoutsDefaultToTwoHoursAndThirtyDays(): void
    {
        $config = Config::fromArray([
            'driver' => 'fake',
            'environment' => 'local',
            'fake' => ['username' => 'jdoe'],
        ]);

        self::assertSame(120, $config->sessionIdleMinutes);
        self::assertSame(720, $config->sessionAbsoluteMinutes);
    }

    #[DataProvider('badSessionTimeouts')]
    public function testRejectsAnInvalidSessionTimeout(string $key, int|string $value): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('The config key "session.' . $key . '" must be a positive integer.');

        Config::fromArray([
            'driver' => 'fake',
            'environment' => 'local',
            'fake' => ['username' => 'jdoe'],
            'session' => [$key => $value],
        ]);
    }

    /**
     * Session timeout values that must be rejected.
     *
     * @return array<string, array{string, int|string}>
     */
    public static function badSessionTimeouts(): array
    {
        return [
            'an idle timeout of zero' => ['idle_minutes', 0],
            'a negative idle timeout' => ['idle_minutes', -5],
            'a string idle timeout' => ['idle_minutes', 'abc'],
            'an absolute timeout of zero' => ['absolute_minutes', 0],
            'a negative absolute timeout' => ['absolute_minutes', -5],
            'a string absolute timeout' => ['absolute_minutes', 'abc'],
        ];
    }

    public function testRejectsATruthySloSetting(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('Single logout is not supported by the identity provider; remove the slo setting.');

        Config::fromArray([
            'driver' => 'fake',
            'environment' => 'local',
            'fake' => ['username' => 'jdoe'],
            'slo' => true,
        ]);
    }

    public function testRejectsATruthySingleLogoutSetting(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('Single logout is not supported by the identity provider; remove the slo setting.');

        Config::fromArray([
            'driver' => 'fake',
            'environment' => 'local',
            'fake' => ['username' => 'jdoe'],
            'single_logout' => true,
        ]);
    }

    public function testIgnoresAFalsySloSetting(): void
    {
        $config = Config::fromArray([
            'driver' => 'fake',
            'environment' => 'local',
            'fake' => ['username' => 'jdoe'],
            'slo' => false,
        ]);

        self::assertSame('fake', $config->driver);
    }

    /**
     * Gateway max-lifetime values that must be rejected.
     *
     * @return array<string, array{int|string}>
     */
    public static function badGatewayMaxLifetimes(): array
    {
        return [
            'a lifetime of zero' => [0],
            'a negative lifetime' => [-5],
            'a string lifetime' => ['abc'],
        ];
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
