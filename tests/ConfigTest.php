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

    public function testReadsTheOauth2Settings(): void
    {
        $config = Config::fromArray($this->oauth2Config([
            'leeway_seconds' => 30,
            'required_scopes' => ['openid', 'profile'],
            'required_roles' => ['admin'],
            'identity_claim' => 'accessid',
            'jwks_cache_ttl_seconds' => 600,
        ]));

        self::assertSame('oauth2', $config->driver);
        self::assertSame('https://login.example.com/v2.0', $config->oauth2Issuer);
        self::assertSame('https://login.example.com/discovery/v2.0/keys', $config->oauth2JwksUrl);
        self::assertSame('api://test-client', $config->oauth2Audience);
        self::assertSame(30, $config->oauth2LeewaySeconds);
        self::assertSame(['openid', 'profile'], $config->oauth2RequiredScopes);
        self::assertSame(['admin'], $config->oauth2RequiredRoles);
        self::assertSame('accessid', $config->oauth2IdentityClaim);
        self::assertSame(600, $config->oauth2JwksCacheTtlSeconds);
    }

    public function testOauth2LeewayDefaultsToSixtySeconds(): void
    {
        $config = Config::fromArray($this->oauth2Config());

        self::assertSame(60, $config->oauth2LeewaySeconds);
    }

    public function testOauth2IdentityClaimDefaultsToPreferredUsername(): void
    {
        $config = Config::fromArray($this->oauth2Config());

        self::assertSame('preferred_username', $config->oauth2IdentityClaim);
    }

    public function testOauth2JwksCacheTtlDefaultsToAnHour(): void
    {
        $config = Config::fromArray($this->oauth2Config());

        self::assertSame(3600, $config->oauth2JwksCacheTtlSeconds);
    }

    public function testOauth2ScopesAndRolesDefaultToEmpty(): void
    {
        $config = Config::fromArray($this->oauth2Config());

        self::assertSame([], $config->oauth2RequiredScopes);
        self::assertSame([], $config->oauth2RequiredRoles);
    }

    public function testRejectsAMissingOauth2Issuer(): void
    {
        $this->expectException(InvalidConfig::class);

        $oauth2 = $this->oauth2Config()['oauth2'];
        unset($oauth2['issuer']);

        Config::fromArray(['driver' => 'oauth2', 'environment' => 'local', 'oauth2' => $oauth2]);
    }

    public function testRejectsAMissingOauth2JwksUrl(): void
    {
        $this->expectException(InvalidConfig::class);

        $oauth2 = $this->oauth2Config()['oauth2'];
        unset($oauth2['jwks_url']);

        Config::fromArray(['driver' => 'oauth2', 'environment' => 'local', 'oauth2' => $oauth2]);
    }

    public function testRejectsAMissingOauth2Audience(): void
    {
        $this->expectException(InvalidConfig::class);

        $oauth2 = $this->oauth2Config()['oauth2'];
        unset($oauth2['audience']);

        Config::fromArray(['driver' => 'oauth2', 'environment' => 'local', 'oauth2' => $oauth2]);
    }

    public function testRejectsAnUnknownOauth2IdentityClaim(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('The config key "oauth2.identity_claim" must be "preferred_username" or "accessid".');

        Config::fromArray($this->oauth2Config(['identity_claim' => 'upn']));
    }

    public function testRejectsANonArrayOauth2RequiredScopes(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('The config key "oauth2.required_scopes" must be an array of strings.');

        Config::fromArray($this->oauth2Config(['required_scopes' => 'openid profile']));
    }

    public function testRejectsAnEmptyOauth2ScopeEntry(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('Each entry in "oauth2.required_scopes" must be a non-empty string.');

        Config::fromArray($this->oauth2Config(['required_scopes' => ['openid', '']]));
    }

    public function testRejectsANonStringOauth2RoleEntry(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('Each entry in "oauth2.required_roles" must be a non-empty string.');

        Config::fromArray($this->oauth2Config(['required_roles' => [42]]));
    }

    public function testRejectsANegativeOauth2Leeway(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('The config key "oauth2.leeway_seconds" must be a non-negative integer.');

        Config::fromArray($this->oauth2Config(['leeway_seconds' => -1]));
    }

    public function testAcceptsAZeroOauth2Leeway(): void
    {
        $config = Config::fromArray($this->oauth2Config(['leeway_seconds' => 0]));

        self::assertSame(0, $config->oauth2LeewaySeconds);
    }

    #[DataProvider('badOauth2JwksCacheTtls')]
    public function testRejectsAnInvalidOauth2JwksCacheTtl(int|string $value): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('The config key "oauth2.jwks_cache_ttl_seconds" must be a positive integer.');

        Config::fromArray($this->oauth2Config(['jwks_cache_ttl_seconds' => $value]));
    }

    /**
     * Oauth2 key-set cache TTLs that must be rejected.
     *
     * @return array<string, array{int|string}>
     */
    public static function badOauth2JwksCacheTtls(): array
    {
        return [
            'a ttl of zero' => [0],
            'a negative ttl' => [-5],
            'a string ttl' => ['abc'],
        ];
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

    /**
     * A valid oauth2 config, with any overrides merged into the oauth2
     * section.
     *
     * @param  array<string, mixed>  $oauth2
     * @return array<string, mixed>
     */
    private function oauth2Config(array $oauth2 = []): array
    {
        return [
            'driver' => 'oauth2',
            'environment' => 'local',
            'oauth2' => array_merge([
                'issuer' => 'https://login.example.com/v2.0',
                'jwks_url' => 'https://login.example.com/discovery/v2.0/keys',
                'audience' => 'api://test-client',
            ], $oauth2),
        ];
    }
}
