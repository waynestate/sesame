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
 * replay guard, for local development only). The `oauth2` driver adds
 * `oauth2.issuer` (required), `oauth2.jwks_url` (required),
 * `oauth2.audience` (required), `oauth2.leeway_seconds` (default `60`, a
 * non-negative integer, at most `300`), `oauth2.required_scopes` (default `[]`, a list of
 * non-empty strings the token's scp claim must include),
 * `oauth2.required_roles` (default `[]`, a list of non-empty strings the
 * token's roles claim must include), `oauth2.identity_claim` (default
 * `preferred_username`, only `preferred_username` or `accessid`) and
 * `oauth2.jwks_cache_ttl_seconds` (default `3600`, a positive integer, at most
 * `86400`: how long the fetched key set stays usable). The session timeouts are
 * `session.idle_minutes` (default `120`) and `session.absolute_minutes`
 * (default `720`), both optional positive integers. A truthy `slo` (or
 * `single_logout`) key is rejected: the identity provider does not support
 * single logout.
 */
final readonly class Config
{
    /**
     * The environment setting behind each config key the package resolves
     * from the environment or waynestate/playbook, so a missing value can
     * name the setting the developer actually sets.
     *
     * @var array<string, string>
     */
    private const ENV_SETTINGS = [
        'cas.base_url' => 'SESAME_CAS_BASE_URL',
        'gateway.issuer' => 'SESAME_GATEWAY_ISSUER',
        'oauth2.issuer' => 'SESAME_OAUTH2_ISSUER',
        'oauth2.jwks_url' => 'SESAME_OAUTH2_JWKS_URL',
    ];

    /**
     * @param array<string, list<string>> $fakeAttributes
     * @param list<string> $gatewayTrustedProxies
     * @param list<string> $oauth2RequiredScopes
     * @param list<string> $oauth2RequiredRoles
     */
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
        public ?string $oauth2Issuer = null,
        public ?string $oauth2JwksUrl = null,
        public ?string $oauth2Audience = null,
        public int $oauth2LeewaySeconds = 60,
        public array $oauth2RequiredScopes = [],
        public array $oauth2RequiredRoles = [],
        public string $oauth2IdentityClaim = 'preferred_username',
        public int $oauth2JwksCacheTtlSeconds = 3600,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $driver = self::requiredString($config, 'driver');
        $environment = self::requiredString($config, 'environment');

        // The identity provider does not support single logout, so a config
        // that asks for it is a mistake to fail on at boot, not at the first
        // logout.
        $slo = $config['slo'] ?? null;
        $singleLogout = $config['single_logout'] ?? null;

        if ((bool) $slo || (bool) $singleLogout) {
            throw new InvalidConfig('Single logout is not supported by the identity provider; remove the slo setting.');
        }

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
        $oauth2Issuer = null;
        $oauth2JwksUrl = null;
        $oauth2Audience = null;
        $oauth2LeewaySeconds = 60;
        $oauth2RequiredScopes = [];
        $oauth2RequiredRoles = [];
        $oauth2IdentityClaim = 'preferred_username';
        $oauth2JwksCacheTtlSeconds = 3600;

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

        if ($driver === 'oauth2') {
            $oauth2Issuer = self::requiredString($config, 'oauth2.issuer');
            $oauth2JwksUrl = self::requiredString($config, 'oauth2.jwks_url');
            $oauth2Audience = self::requiredString($config, 'oauth2.audience');
            $oauth2LeewaySeconds = self::optionalNonNegativeInt($config, 'oauth2.leeway_seconds', 60);
            $oauth2RequiredScopes = self::optionalStringList($config, 'oauth2.required_scopes', []);
            $oauth2RequiredRoles = self::optionalStringList($config, 'oauth2.required_roles', []);

            $oauth2IdentityClaim = $config['oauth2']['identity_claim'] ?? 'preferred_username';

            if (! is_string($oauth2IdentityClaim) || ! in_array($oauth2IdentityClaim, ['preferred_username', 'accessid'], true)) {
                throw new InvalidConfig('The config key "oauth2.identity_claim" must be "preferred_username" or "accessid".');
            }

            $oauth2JwksCacheTtlSeconds = self::optionalPositiveInt($config, 'oauth2.jwks_cache_ttl_seconds', 3600);

            // Bounds: a huge leeway makes expired tokens live on, and a huge
            // TTL keeps a revoked signing key trusted.
            if ($oauth2LeewaySeconds > 300) {
                throw new InvalidConfig('The config key "oauth2.leeway_seconds" must be at most 300.');
            }

            if ($oauth2JwksCacheTtlSeconds > 86400) {
                throw new InvalidConfig('The config key "oauth2.jwks_cache_ttl_seconds" must be at most 86400.');
            }
        }

        $sessionIdleMinutes = self::optionalPositiveInt($config, 'session.idle_minutes', 120);
        $sessionAbsoluteMinutes = self::optionalPositiveInt($config, 'session.absolute_minutes', 720);

        /**
         * @var array<string, list<string>> $fakeAttributes
         * @var list<non-empty-string> $gatewayTrustedProxies
         * @var list<string> $oauth2RequiredScopes
         * @var list<string> $oauth2RequiredRoles
         */
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
            $oauth2Issuer,
            $oauth2JwksUrl,
            $oauth2Audience,
            $oauth2LeewaySeconds,
            $oauth2RequiredScopes,
            $oauth2RequiredRoles,
            $oauth2IdentityClaim,
            $oauth2JwksCacheTtlSeconds,
        );
    }

    /**
     * Read a required, non-empty string from a dotted path in the config.
     *
     * @param array<string, mixed> $config
     */
    private static function requiredString(array $config, string $path): string
    {
        $value = $config;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                throw new InvalidConfig(self::missingRequiredValueMessage($path));
            }

            $value = $value[$segment];
        }

        if (! is_string($value) || $value === '') {
            if (isset(self::ENV_SETTINGS[$path])) {
                throw new InvalidConfig(self::missingRequiredValueMessage($path));
            }

            throw new InvalidConfig(sprintf('The config key "%s" must be a non-empty string.', $path));
        }

        return $value;
    }

    /**
     * The error for a required value that is missing or empty.
     *
     * The routed settings (the ones resolved from the environment or
     * waynestate/playbook) name the environment setting and point at the
     * playbook; every other key names its config path.
     */
    private static function missingRequiredValueMessage(string $path): string
    {
        $setting = self::ENV_SETTINGS[$path] ?? null;

        if ($setting !== null) {
            return sprintf(
                'Missing required setting %s. Set it in the environment, or install waynestate/playbook (see the README).',
                $setting,
            );
        }

        return sprintf('Missing required config key "%s".', $path);
    }

    /**
     * Read an optional positive integer from a dotted path in the config.
     *
     * The default is returned when the key is absent; a present key that is
     * not a positive integer is a config error.
     *
     * @param array<string, mixed> $config
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
     *
     * @param array<string, mixed> $config
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
     * Read an optional list of non-empty strings from a dotted path in the
     * config.
     *
     * The default is returned when the key is absent; a present key that is
     * not a list of non-empty strings is a config error.
     *
     * @param array<string, mixed> $config
     * @param list<string> $default
     * @return list<string>
     */
    private static function optionalStringList(array $config, string $path, array $default): array
    {
        $value = $config;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        if (! is_array($value)) {
            throw new InvalidConfig(sprintf('The config key "%s" must be an array of strings.', $path));
        }

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new InvalidConfig(sprintf('Each entry in "%s" must be a non-empty string.', $path));
            }
        }

        return array_values($value);
    }

    /**
     * Read an optional boolean from a dotted path in the config.
     *
     * The default is returned when the key is absent; a present key that is
     * not a boolean is a config error.
     *
     * @param array<string, mixed> $config
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
