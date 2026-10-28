<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

use Waynestate\Sesame\Exception\InvalidConfig;

/**
 * The library's config, read from a plain array.
 *
 * `driver` and `environment` are always required. The `fake` driver adds
 * `fake.username` (required) and `fake.attributes` (default `[]`). The
 * `cas` driver adds `cas.base_url` (required), `cas.service_url` (required),
 * `cas.protocol` (default `2.0`, only `3.0` or `2.0`), `cas.renew` (default
 * `false`) and `cas.assume_mfa` (default `false`). The `gateway` driver adds
 * `gateway.header` (default
 * `X-Sesame-Assertion`), `gateway.algorithm` (default `ES256`, only
 * `ES256`, `RS256` or `EdDSA`), `gateway.public_key` (required),
 * `gateway.issuer` (required), `gateway.audience` (required),
 * `gateway.leeway_seconds` (default `30`, a non-negative integer),
 * `gateway.trusted_proxies` (required, a non-empty array of CIDR ranges),
 * `gateway.max_lifetime` (default `300`, a positive integer: the longest an
 * assertion may live, in seconds) and
 * `gateway.allow_without_replay_guard` (default `false`, a boolean: skip the
 * replay guard, for local development only). The session timeouts are
 * `session.idle_minutes` (default `120`) and `session.absolute_minutes`
 * (default `720`), both optional positive integers.
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
        public string $casProtocol = '2.0',
        public bool $casRenew = false,
        public bool $casAssumeMfa = false,
        public int $sessionIdleMinutes = 120,
        public int $sessionAbsoluteMinutes = 720,
        public ?string $gatewayHeader = null,
        public string $gatewayAlgorithm = 'ES256',
        public ?string $gatewayPublicKey = null,
        public ?string $gatewayIssuer = null,
        public ?string $gatewayAudience = null,
        public int $gatewayLeewaySeconds = 30,
        public array $gatewayTrustedProxies = [],
        public int $gatewayMaxLifetimeSeconds = 300,
        public bool $gatewayAllowWithoutReplayGuard = false,
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
        $casProtocol = '2.0';
        $casRenew = false;
        $casAssumeMfa = false;
        $gatewayHeader = null;
        $gatewayAlgorithm = 'ES256';
        $gatewayPublicKey = null;
        $gatewayIssuer = null;
        $gatewayAudience = null;
        $gatewayLeewaySeconds = 30;
        $gatewayTrustedProxies = [];
        $gatewayMaxLifetimeSeconds = 300;
        $gatewayAllowWithoutReplayGuard = false;

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
            $casProtocol = $config['cas']['protocol'] ?? '2.0';

            if (! is_string($casProtocol) || ! in_array($casProtocol, ['3.0', '2.0'], true)) {
                throw new InvalidConfig('The config key "cas.protocol" must be "3.0" or "2.0".');
            }

            $casRenew = self::optionalBool($config, 'cas.renew', false);
            $casAssumeMfa = self::optionalBool($config, 'cas.assume_mfa', false);
        }

        if ($driver === 'gateway') {
            $gatewayHeader = $config['gateway']['header'] ?? 'X-Sesame-Assertion';

            if (! is_string($gatewayHeader) || $gatewayHeader === '') {
                throw new InvalidConfig('The config key "gateway.header" must be a non-empty string.');
            }

            $gatewayAlgorithm = $config['gateway']['algorithm'] ?? 'ES256';

            if (! is_string($gatewayAlgorithm) || ! in_array($gatewayAlgorithm, ['ES256', 'RS256', 'EdDSA'], true)) {
                throw new InvalidConfig('The config key "gateway.algorithm" must be "ES256", "RS256" or "EdDSA".');
            }

            $gatewayPublicKey = self::requiredString($config, 'gateway.public_key');
            $gatewayIssuer = self::requiredString($config, 'gateway.issuer');
            $gatewayAudience = self::requiredString($config, 'gateway.audience');
            $gatewayLeewaySeconds = self::optionalNonNegativeInt($config, 'gateway.leeway_seconds', 30);

            $gatewayTrustedProxies = $config['gateway']['trusted_proxies'] ?? [];

            if (! is_array($gatewayTrustedProxies) || $gatewayTrustedProxies === []) {
                throw new InvalidConfig('The config key "gateway.trusted_proxies" must be a non-empty array of CIDR ranges.');
            }

            foreach ($gatewayTrustedProxies as $proxy) {
                if (! is_string($proxy) || $proxy === '') {
                    throw new InvalidConfig('Each entry in "gateway.trusted_proxies" must be a non-empty string.');
                }
            }

            $gatewayMaxLifetimeSeconds = self::optionalPositiveInt($config, 'gateway.max_lifetime', 300);
            $gatewayAllowWithoutReplayGuard = self::optionalBool($config, 'gateway.allow_without_replay_guard', false);
        }

        $sessionIdleMinutes = self::optionalPositiveInt($config, 'session.idle_minutes', 120);
        $sessionAbsoluteMinutes = self::optionalPositiveInt($config, 'session.absolute_minutes', 720);

        return new self(
            $driver,
            $environment,
            $fakeUsername,
            $fakeAttributes,
            $casBaseUrl,
            $casServiceUrl,
            $casProtocol,
            $casRenew,
            $casAssumeMfa,
            $sessionIdleMinutes,
            $sessionAbsoluteMinutes,
            $gatewayHeader,
            $gatewayAlgorithm,
            $gatewayPublicKey,
            $gatewayIssuer,
            $gatewayAudience,
            $gatewayLeewaySeconds,
            $gatewayTrustedProxies,
            $gatewayMaxLifetimeSeconds,
            $gatewayAllowWithoutReplayGuard,
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

    /**
     * Read an optional positive integer from a dotted path in the config.
     *
     * The default is returned when the key is absent; a present key that is
     * not a positive integer is a config error.
     */
    private static function optionalPositiveInt(array $config, string $path, int $default): int
    {
        $value = $config;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        if (! is_int($value) || $value <= 0) {
            throw new InvalidConfig(sprintf('The config key "%s" must be a positive integer.', $path));
        }

        return $value;
    }

    /**
     * Read an optional non-negative integer from a dotted path in the config.
     *
     * The default is returned when the key is absent; a present key that is
     * not a non-negative integer is a config error. Unlike
     * optionalPositiveInt(), zero is allowed: a zero leeway is a valid,
     * strict choice.
     */
    private static function optionalNonNegativeInt(array $config, string $path, int $default): int
    {
        $value = $config;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        if (! is_int($value) || $value < 0) {
            throw new InvalidConfig(sprintf('The config key "%s" must be a non-negative integer.', $path));
        }

        return $value;
    }

    /**
     * Read an optional boolean from a dotted path in the config.
     *
     * The default is returned when the key is absent; a present key that is
     * not a boolean is a config error.
     */
    private static function optionalBool(array $config, string $path, bool $default): bool
    {
        $value = $config;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        if (! is_bool($value)) {
            throw new InvalidConfig(sprintf('The config key "%s" must be a boolean.', $path));
        }

        return $value;
    }
}
