<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

use Waynestate\Sesame\Exception\InvalidConfig;

/**
 * The library's config, read from a plain array.
 *
 * `driver` and `environment` are always required. The `fake` driver adds
 * `fake.username` (required) and `fake.attributes` (default `[]`). The
 * `cas` driver adds `cas.base_url` (required), `cas.service_url` (required)
 * and `cas.protocol` (default `3.0`, only `3.0` or `2.0`).
 */
final readonly class Config
{
    public function __construct(
        public string $driver,
        public string $environment,
        public ?string $fakeUsername = null,
        public array $fakeAttributes = [],
        public ?string $casBaseUrl = null,
        public ?string $casServiceUrl = null,
        public string $casProtocol = '3.0',
    ) {
    }

    public static function fromArray(array $config): self
    {
        $driver = self::requiredString($config, 'driver');
        $environment = self::requiredString($config, 'environment');

        $fakeUsername = null;
        $fakeAttributes = [];
        $casBaseUrl = null;
        $casServiceUrl = null;
        $casProtocol = '3.0';

        if ($driver === 'fake') {
            $fakeUsername = self::requiredString($config, 'fake.username');

            $fakeAttributes = $config['fake']['attributes'] ?? [];

            if (! is_array($fakeAttributes)) {
                throw new InvalidConfig('The config key "fake.attributes" must be an array.');
            }
        }

        if ($driver === 'cas') {
            $casBaseUrl = self::requiredString($config, 'cas.base_url');
            $casServiceUrl = self::requiredString($config, 'cas.service_url');
            $casProtocol = $config['cas']['protocol'] ?? '3.0';

            if (! is_string($casProtocol) || ! in_array($casProtocol, ['3.0', '2.0'], true)) {
                throw new InvalidConfig('The config key "cas.protocol" must be "3.0" or "2.0".');
            }
        }

        return new self(
            $driver,
            $environment,
            $fakeUsername,
            $fakeAttributes,
            $casBaseUrl,
            $casServiceUrl,
            $casProtocol,
        );
    }

    /**
     * Read a required, non-empty string from a dotted path in the config.
     */
    private static function requiredString(array $config, string $path): string
    {
        $value = $config;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                throw new InvalidConfig(sprintf('Missing required config key "%s".', $path));
            }

            $value = $value[$segment];
        }

        if (! is_string($value) || $value === '') {
            throw new InvalidConfig(sprintf('The config key "%s" must be a non-empty string.', $path));
        }

        return $value;
    }
}
