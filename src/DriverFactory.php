<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Waynestate\Sesame\Drivers\CasDriver;
use Waynestate\Sesame\Drivers\FakeDriver;
use Waynestate\Sesame\Drivers\GatewayDriver;
use Waynestate\Sesame\Drivers\OAuth2Driver;
use Waynestate\Sesame\Drivers\ReplayGuard;
use Waynestate\Sesame\Exception\InvalidConfig;

/**
 * Build a driver from config.
 *
 * The cas driver talks to a CAS server over HTTP, and this factory cannot
 * build an HTTP client itself: the app passes a PSR-18 client and a PSR-17
 * request factory to make(). The gateway driver needs no HTTP client: it
 * only reads a signed assertion from the request. A PSR-20 clock may be
 * passed for the cas, gateway and oauth2 drivers' sign-in time; without one
 * they use the system clock.
 *
 * The gateway driver also needs a ReplayGuard, so a captured assertion
 * cannot be replayed: pass one (for example a Psr16ReplayGuard over a PSR-16
 * cache) as the fifth argument. Without one, the gateway driver refuses to
 * start, unless the config says gateway.allow_without_replay_guard (local
 * development only).
 *
 * The oauth2 driver fetches the issuer's key set over HTTP and keeps it in
 * a cache: pass a PSR-16 cache as the sixth argument. Without one, the
 * oauth2 driver refuses to start.
 */
final class DriverFactory
{
    public static function make(
        Config $config,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?ClockInterface $clock = null,
        ?ReplayGuard $replayGuard = null,
        ?CacheInterface $jwksCache = null,
    ): Driver {
        return match ($config->driver) {
            'fake' => new FakeDriver(
                $config->environment,
                $config->fakeUsername ?? '',
                $config->fakeAttributes,
            ),
            'cas' => new CasDriver(
                $config->casBaseUrl ?? '',
                $config->casServiceUrl ?? '',
                self::requiredHttp($http),
                self::requiredRequests($requests),
                $config->casProtocol,
                $config->casRenew,
                $config->casAssumeMfa,
                $clock,
            ),
            'gateway' => new GatewayDriver(
                $config->gatewayHeader ?? 'X-Sesame-Assertion',
                $config->gatewayAlgorithm,
                self::requiredNonEmpty($config->gatewayPublicKey, 'gateway.public_key'),
                self::requiredNonEmpty($config->gatewayIssuer, 'gateway.issuer'),
                self::requiredNonEmpty($config->gatewayAudience, 'gateway.audience'),
                $config->gatewayLeewaySeconds,
                $config->gatewayTrustedProxies,
                $clock,
                $replayGuard,
                $config->gatewayMaxLifetimeSeconds,
                $config->gatewayAllowWithoutReplayGuard,
            ),
            'oauth2' => new OAuth2Driver(
                self::requiredNonEmpty($config->oauth2Issuer, 'oauth2.issuer'),
                self::requiredNonEmpty($config->oauth2JwksUrl, 'oauth2.jwks_url'),
                self::requiredNonEmpty($config->oauth2Audience, 'oauth2.audience'),
                $config->oauth2LeewaySeconds,
                $config->oauth2RequiredScopes,
                $config->oauth2RequiredRoles,
                $config->oauth2IdentityClaim,
                self::requiredOauth2Http($http),
                self::requiredRequests($requests),
                self::requiredJwksCache($jwksCache),
                $config->oauth2JwksCacheTtlSeconds,
                $clock,
            ),
            default => throw new InvalidConfig(sprintf('Unknown driver "%s".', $config->driver)),
        };
    }

    private static function requiredHttp(?ClientInterface $http): ClientInterface
    {
        if ($http === null) {
            throw new InvalidConfig('The cas driver needs a PSR-18 client. Pass $http to DriverFactory::make().');
        }

        return $http;
    }

    private static function requiredRequests(?RequestFactoryInterface $requests): RequestFactoryInterface
    {
        if ($requests === null) {
            throw new InvalidConfig('The cas and oauth2 drivers need a PSR-17 request factory. Pass $requests to DriverFactory::make().');
        }

        return $requests;
    }

    private static function requiredOauth2Http(?ClientInterface $http): ClientInterface
    {
        if ($http === null) {
            throw new InvalidConfig('The oauth2 driver needs a PSR-18 client to fetch the issuer\'s key set. Pass $http to DriverFactory::make().');
        }

        return $http;
    }

    private static function requiredJwksCache(?CacheInterface $jwksCache): CacheInterface
    {
        if ($jwksCache === null) {
            throw new InvalidConfig('The oauth2 driver needs a PSR-16 cache to hold the fetched key set. Pass $jwksCache to DriverFactory::make().');
        }

        return $jwksCache;
    }

    /**
     * The gateway's public key, issuer and audience must be non-empty strings.
     *
     * @return non-empty-string
     */
    private static function requiredNonEmpty(?string $value, string $key): string
    {
        if ($value === null || $value === '') {
            throw new InvalidConfig(sprintf('The config key "%s" must be a non-empty string.', $key));
        }

        return $value;
    }
}
