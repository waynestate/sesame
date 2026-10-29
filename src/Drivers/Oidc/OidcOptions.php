<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Oidc;

use SensitiveParameter;
use SensitiveParameterValue;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Support\Setting;

/**
 * The oidc driver's settings, checked once at boot.
 *
 * Every key is read from the `oidc` block of the config array. The client
 * secret is the one value that is never echoed: it is held wrapped in a
 * SensitiveParameterValue, which var_export, json_encode, var_dump,
 * print_r and array casts show as empty and serialize() refuses; the
 * parameters that carry it are marked #[\SensitiveParameter] so stack
 * traces hide it; and errors name it, never show it.
 * When the config array has no client secret, it is read from the real
 * environment (SESAME_OIDC_CLIENT_SECRET) and never from waynestate/playbook.
 */
final readonly class OidcOptions
{
    /**
     * The signing algorithms an app may allow. Only RSA: "none" and the HMAC
     * family are never accepted, because an HMAC token signed with the
     * issuer's public key is a classic forgery.
     *
     * @var list<string>
     */
    public const ALLOWED_ALGORITHMS = ['RS256', 'RS384', 'RS512'];

    /**
     * The client authentication methods at the token endpoint.
     *
     * @var list<string>
     */
    public const CLIENT_AUTH_METHODS = ['client_secret_post', 'client_secret_basic'];

    /**
     * The longest the discovery document and key set may be cached, in
     * seconds (one hour).
     */
    public const MAX_CACHE_TTL_SECONDS = 3600;

    private SensitiveParameterValue $clientSecret;

    /**
     * @param non-empty-string $issuer
     * @param non-empty-string $clientId
     * @param non-empty-string $redirectUri
     * @param list<non-empty-string> $scopes
     * @param list<non-empty-string> $algorithms
     * @param array<string, string> $endpoints Endpoint overrides, by discovery name.
     * @param non-empty-string $usernameClaim
     * @param non-empty-string $rolesClaim
     * @param list<non-empty-string> $claims The ID token claims passed through to the app as attributes, under these names.
     */
    public function __construct(
        public string $issuer,
        public string $clientId,
        #[SensitiveParameter]
        string $clientSecret,
        public string $redirectUri,
        public array $scopes = ['openid', 'profile', 'email'],
        public string $clientAuth = 'client_secret_post',
        public array $algorithms = ['RS256'],
        public int $leewaySeconds = 60,
        public string $usernameClaim = 'accessid',
        public bool $usernameFallback = false,
        public string $rolesClaim = 'wsuRoles',
        public bool $assumeMfa = false,
        public ?string $postLogoutRedirectUri = null,
        public array $endpoints = [],
        public int $cacheTtlSeconds = 3600,
        public int $stateTtlSeconds = 600,
        public ?string $stepUpPrompt = 'login',
        public ?int $stepUpMaxAge = null,
        public ?string $stepUpAcrValues = null,
        public ?string $stepUpClaims = null,
        public array $claims = ['wsuRoles', 'accessid'],
    ) {
        $this->clientSecret = new SensitiveParameterValue($clientSecret);
    }

    /**
     * The client secret, for the token request only.
     */
    public function clientSecret(): string
    {
        return $this->clientSecret->getValue();
    }

    /**
     * What var_dump and print_r show: everything but the secret.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $values = get_object_vars($this);
        $values['clientSecret'] = '[redacted]';

        return $values;
    }

    /**
     * Build the options from the `oidc` block of the config array.
     *
     * @param array<mixed> $oidc
     */
    public static function fromArray(#[\SensitiveParameter] array $oidc): self
    {
        $issuer = self::requiredUrl($oidc, 'issuer', 'SESAME_OIDC_ISSUER');
        $clientId = self::requiredString($oidc, 'client_id', 'SESAME_OIDC_CLIENT_ID');
        $redirectUri = self::requiredUrl($oidc, 'redirect_uri', 'SESAME_OIDC_REDIRECT_URI', allowLocalhost: true);

        $secret = $oidc['client_secret'] ?? null;

        if ($secret === null || $secret === '') {
            $secret = Setting::env('SESAME_OIDC_CLIENT_SECRET');
        }

        if (! is_string($secret) || $secret === '') {
            // The value is never part of the message, whatever it is.
            throw new InvalidConfig('Missing required setting SESAME_OIDC_CLIENT_SECRET. Set it in the environment only; it is never read from waynestate/playbook.');
        }

        $scopes = self::scopes($oidc['scopes'] ?? 'openid profile email');

        $clientAuth = $oidc['client_auth'] ?? 'client_secret_post';

        if (! is_string($clientAuth) || ! in_array($clientAuth, self::CLIENT_AUTH_METHODS, true)) {
            throw new InvalidConfig('The config key "oidc.client_auth" must be "client_secret_post" or "client_secret_basic".');
        }

        $algorithms = $oidc['algorithms'] ?? ['RS256'];

        if (is_string($algorithms)) {
            $algorithms = array_values(array_filter(array_map('trim', explode(',', $algorithms)), static fn(string $a): bool => $a !== ''));
        }

        if (! is_array($algorithms) || $algorithms === []) {
            throw new InvalidConfig('The config key "oidc.algorithms" must be a non-empty list of RS256, RS384 or RS512.');
        }

        $allowed = [];

        foreach ($algorithms as $algorithm) {
            if (! is_string($algorithm) || ! in_array($algorithm, self::ALLOWED_ALGORITHMS, true)) {
                throw new InvalidConfig('The config key "oidc.algorithms" may only hold RS256, RS384 or RS512; "none" and HMAC algorithms are never allowed.');
            }

            $allowed[] = $algorithm;
        }

        $leeway = self::int($oidc, 'leeway_seconds', 60, 0, 300);
        $usernameClaim = self::optionalString($oidc, 'username_claim') ?? 'accessid';
        $usernameFallback = self::bool($oidc, 'username_fallback', false);
        $rolesClaim = self::optionalString($oidc, 'roles_claim') ?? 'wsuRoles';
        $assumeMfa = self::bool($oidc, 'assume_mfa', false);

        $postLogout = self::optionalString($oidc, 'post_logout_redirect_uri');

        if ($postLogout !== null) {
            self::assertUrl($postLogout, 'oidc.post_logout_redirect_uri', true);
        }

        $endpoints = [];

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'end_session_endpoint'] as $name) {
            $value = self::optionalString($oidc, $name);

            if ($value !== null) {
                self::assertUrl($value, 'oidc.' . $name, false);
                $endpoints[$name] = $value;
            }
        }

        // At most an hour: a discovery document or key set the issuer has
        // replaced (a moved endpoint, a revoked key) must not live on in the
        // cache for a day.
        $cacheTtl = self::int($oidc, 'cache_ttl_seconds', 3600, 1, self::MAX_CACHE_TTL_SECONDS);
        $stateTtl = self::int($oidc, 'state_ttl_seconds', 600, 60, 3600);

        $stepUp = $oidc['step_up'] ?? [];

        if (! is_array($stepUp)) {
            throw new InvalidConfig('The config key "oidc.step_up" must be an array.');
        }

        $prompt = array_key_exists('prompt', $stepUp) ? $stepUp['prompt'] : 'login';

        if ($prompt === '') {
            $prompt = null;
        }

        if ($prompt !== null && $prompt !== 'login') {
            throw new InvalidConfig('The config key "oidc.step_up.prompt" must be "login" or null.');
        }

        $maxAge = $stepUp['max_age'] ?? null;

        if ($maxAge === '') {
            $maxAge = null;
        }

        if (is_string($maxAge) && ctype_digit($maxAge)) {
            $maxAge = (int) $maxAge;
        }

        if ($maxAge !== null && (! is_int($maxAge) || $maxAge < 0)) {
            throw new InvalidConfig('The config key "oidc.step_up.max_age" must be a non-negative integer or null.');
        }

        $acrValues = self::optionalString($stepUp, 'acr_values', 'oidc.step_up.acr_values');
        $claims = self::optionalString($stepUp, 'claims', 'oidc.step_up.claims');

        if ($claims !== null) {
            $decoded = json_decode($claims, true);

            if (! is_array($decoded) || array_is_list($decoded)) {
                throw new InvalidConfig('The config key "oidc.step_up.claims" must be a JSON object.');
            }
        }

        $claimNames = self::claimNames($oidc['claims'] ?? ['wsuRoles', 'accessid']);

        return new self(
            $issuer,
            $clientId,
            $secret,
            $redirectUri,
            $scopes,
            $clientAuth,
            $allowed,
            $leeway,
            $usernameClaim,
            $usernameFallback,
            $rolesClaim,
            $assumeMfa,
            $postLogout,
            $endpoints,
            $cacheTtl,
            $stateTtl,
            $prompt,
            $maxAge,
            $acrValues,
            $claims,
            $claimNames,
        );
    }

    /**
     * The pass-through claim names, from a comma- or space-separated string
     * or a list. An empty string passes nothing through.
     *
     * @return list<non-empty-string>
     */
    private static function claimNames(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (! is_array($value)) {
            throw new InvalidConfig('The config key "oidc.claims" must be a comma-separated string or a list of claim names.');
        }

        $names = [];

        foreach ($value as $name) {
            if (! is_string($name) || preg_match('/^[!-~]{1,256}$/D', $name) !== 1) {
                throw new InvalidConfig('Each entry in "oidc.claims" must be a claim name without spaces.');
            }

            if (! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The scopes, from a space-separated string or a list. "openid" is
     * always included, because without it there is no ID token.
     *
     * @return list<non-empty-string>
     */
    private static function scopes(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(' ', $value);
        }

        if (! is_array($value)) {
            throw new InvalidConfig('The config key "oidc.scopes" must be a space-separated string or a list of strings.');
        }

        $scopes = [];

        foreach ($value as $scope) {
            if (! is_string($scope)) {
                throw new InvalidConfig('Each entry in "oidc.scopes" must be a string.');
            }

            $scope = trim($scope);

            if ($scope === '') {
                continue;
            }

            if (preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/', $scope) !== 1) {
                throw new InvalidConfig('Each entry in "oidc.scopes" must be a valid OAuth 2.0 scope token.');
            }

            if (! in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }

        if (! in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        return $scopes;
    }

    /**
     * @param array<mixed> $oidc
     * @return non-empty-string
     */
    private static function requiredString(array $oidc, string $key, string $setting): string
    {
        $value = $oidc[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidConfig(sprintf(
                'Missing required setting %s. Set it in the environment, or install waynestate/playbook (see the README).',
                $setting,
            ));
        }

        return $value;
    }

    /**
     * @param array<mixed> $oidc
     * @return non-empty-string
     */
    private static function requiredUrl(array $oidc, string $key, string $setting, bool $allowLocalhost = false): string
    {
        $value = self::requiredString($oidc, $key, $setting);
        self::assertUrl($value, 'oidc.' . $key, $allowLocalhost);

        return $value;
    }

    /**
     * The URL must be an absolute https:// URL with a host, no credentials
     * and no fragment. With $allowLocalhost, http://localhost and
     * http://127.0.0.1 are accepted too, for local development.
     */
    private static function assertUrl(string $url, string $key, bool $allowLocalhost): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';

        $ok = is_array($parts)
            && $host !== ''
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && ! array_key_exists('fragment', $parts)
            && ($scheme === 'https'
                || ($allowLocalhost && $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true)));

        if (! $ok) {
            throw new InvalidConfig(sprintf(
                'The config key "%s" must be an absolute https:// URL with no credentials or fragment, got "%s".',
                $key,
                $url,
            ));
        }
    }

    /**
     * @param array<mixed> $values
     * @return non-empty-string|null
     */
    private static function optionalString(array $values, string $key, ?string $path = null): ?string
    {
        $value = $values[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidConfig(sprintf('The config key "%s" must be a string.', $path ?? 'oidc.' . $key));
        }

        return $value;
    }

    /**
     * An integer within bounds. Laravel's env() hands numbers over as
     * strings, so a string of digits is accepted too.
     *
     * @param array<mixed> $oidc
     */
    private static function int(array $oidc, string $key, int $default, int $min, int $max): int
    {
        $value = $oidc[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidConfig(sprintf('The config key "oidc.%s" must be an integer from %d to %d.', $key, $min, $max));
        }

        return $value;
    }

    /**
     * A boolean. Laravel's env() turns "true" and "false" into booleans
     * already; "1" and "0" are accepted too.
     *
     * @param array<mixed> $oidc
     */
    private static function bool(array $oidc, string $key, bool $default): bool
    {
        $value = $oidc[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if ($value === '1' || $value === 1) {
            return true;
        }

        if ($value === '0' || $value === 0) {
            return false;
        }

        if (! is_bool($value)) {
            throw new InvalidConfig(sprintf('The config key "oidc.%s" must be a boolean.', $key));
        }

        return $value;
    }
}
