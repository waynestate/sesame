<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Waynestate\Sesame\Drivers\CasDriver;
use Waynestate\Sesame\Drivers\FakeDriver;
use Waynestate\Sesame\Exception\InvalidConfig;

/**
 * Build a driver from config.
 *
 * The cas driver talks to a CAS server over HTTP, and this factory cannot
 * build an HTTP client itself: the app passes a PSR-18 client and a PSR-17
 * request factory to make().
 */
final class DriverFactory
{
    public static function make(
        Config $config,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
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
            throw new InvalidConfig('The cas driver needs a PSR-17 request factory. Pass $requests to DriverFactory::make().');
        }

        return $requests;
    }
}
