<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

use Waynestate\Sesame\Drivers\FakeDriver;
use Waynestate\Sesame\Exception\InvalidConfig;

/**
 * Build a driver from config.
 *
 * This release knows only the fake driver. The cas driver arrives in a later
 * release, and the rest after it.
 */
final class DriverFactory
{
    public static function make(Config $config): Driver
    {
        return match ($config->driver) {
            'fake' => new FakeDriver(
                $config->environment,
                $config->fakeUsername ?? '',
                $config->fakeAttributes,
            ),
            default => throw new InvalidConfig(sprintf(
                'Unknown driver "%s". The cas driver arrives in a later release.',
                $config->driver,
            )),
        };
    }
}
