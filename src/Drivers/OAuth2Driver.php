<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

use DateTimeImmutable;
use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use UnexpectedValueException;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Clock\SystemClock;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\LoginStep;

/**
 * The oauth2 driver: JWT bearer tokens from an identity provider.
 *
 * "Open Sesame", where the magic word is a token only the issuer can sign.
 * The driver reads the Authorization header (a Bearer token, per RFC 6750),
 * verifies its RS256 signature against a key from the issuer's JWKS, and
 * checks the issuer, audience, time, scope and role claims. It accepts only
 * RS256: "none" and HMAC algorithms are refused before their signatures are
 * even looked at, because an HMAC token signed with the public key is a
 * classic forgery.
 *
 * Microsoft Entra ID is the first planned issuer: point issuer, jwks_url and
 * audience at the tenant's v2.0 endpoints and the app's client id, and the
 * driver signs the user in from the access token. Any other RS256 issuer
 * works with the same settings.
 *
 * The username comes from one configurable claim: preferred_username by
 * default, or accessid (the UPN's mail prefix) when the app wants that.
 *
 * There is no redirect step and no logout on the provider side: the token
 * arrives with the request, and signing out is the app's own session being
 * dropped.
 *
 * Opaque bearer tokens (tokens that are not three-part JWTs) can be checked
 * against an RFC 7662 introspection endpoint when one is configured. The
 * request carries the token as a form field and authenticates with the
 * configured client id and secret. Only a 200 answer whose JSON says
 * active === true is accepted, and the same issuer, audience, expiry and
 * not-before rules apply to the claims the endpoint returns. Accepted
 * answers are cached briefly under a hash of the token; the token itself is
 * never cached or logged.
 */
final class OAuth2Driver implements Driver
{
    /**
     * The only algorithm the oauth2 driver accepts.
     */
    private const ALGORITHM = 'RS256';

    /**
     * The scheme of the Authorization header, per RFC 6750. Case-insensitive.
     */
    private const BEARER_SCHEME = 'bearer';

    /**
     * The optional claims that become read-only identity attributes.
     *
     * @var list<string>
     */
    private const ATTRIBUTE_CLAIMS = ['email', 'name'];

    /**
     * The largest introspection body accepted, in bytes; real answers are a
     * few hundred bytes.
     */
    private const MAX_INTROSPECTION_BODY_BYTES = 65536;

    private readonly JwksProvider $jwks;

    private readonly ClockInterface $clock;

    private readonly bool $requireAtJwt;

    private readonly ClientInterface $http;

    private readonly RequestFactoryInterface $requests;

    private readonly StreamFactoryInterface $streams;

    private readonly ?string $introspectionUrl;

    private readonly ?string $introspectionClientId;

    private readonly ?string $introspectionClientSecret;

    private readonly int $introspectionCacheTtlSeconds;

    private readonly ?CacheInterface $introspectionCache;

    /**
     * The in-process fallback for accepted introspection answers, used only
     * when no PSR-16 cache is passed.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $introspectionMemory = [];

    /**
     * @param  non-empty-string  $issuer  The expected issuer (iss claim).
     * @param  non-empty-string  $audience  The expected audience (aud claim).
     * @param  list<string>  $requiredScopes  The scopes the token's scp claim must include.
     * @param  list<string>  $requiredRoles  The roles the token's roles claim must include.
     * @param  int  $jwksCacheTtlSeconds  How long the fetched key set stays usable, in seconds.
     * @param  bool  $requireAtJwt  When true, the token's typ header must be an RFC 9068 access token type (at+jwt or application/at+jwt). Off by default because Microsoft Entra v2 tokens are typed JWT.
     * @param  string|null  $introspectionUrl  The RFC 7662 token introspection endpoint for opaque bearer tokens, or null to keep the driver JWT-only.
     * @param  string|null  $introspectionClientId  The client id for the introspection request, sent as HTTP Basic auth.
     * @param  string|null  $introspectionClientSecret  The client secret for the introspection request, sent as HTTP Basic auth.
     * @param  int  $introspectionCacheTtlSeconds  How long an accepted introspection answer stays usable, in seconds, capped by the token's exp. Zero disables the cache.
     * @param  CacheInterface|null  $introspectionCache  The PSR-16 cache for accepted introspection answers; without one, an in-process array is used.
     */
    public function __construct(
        private readonly string $issuer,
        string $jwksUrl,
        private readonly string $audience,
        private readonly int $leewaySeconds,
        private readonly array $requiredScopes,
        private readonly array $requiredRoles,
        private readonly string $identityClaim,
        ClientInterface $http,
        RequestFactoryInterface $requests,
        CacheInterface $jwksCache,
        int $jwksCacheTtlSeconds,
        ?ClockInterface $clock = null,
        bool $requireAtJwt = false,
        ?string $introspectionUrl = null,
        ?string $introspectionClientId = null,
        #[\SensitiveParameter]
        ?string $introspectionClientSecret = null,
        int $introspectionCacheTtlSeconds = 60,
        ?CacheInterface $introspectionCache = null,
    ) {
        $this->requireAtJwt = $requireAtJwt;
        $this->clock = $clock ?? new SystemClock();
        $this->jwks = new JwksProvider($http, $requests, $jwksCache, $this->clock, $jwksUrl, $jwksCacheTtlSeconds);
        $this->http = $http;
        $this->requests = $requests;
        $this->streams = $requests instanceof StreamFactoryInterface ? $requests : new Psr17Factory();
        $this->introspectionUrl = $introspectionUrl;
        $this->introspectionClientId = $introspectionClientId;
        $this->introspectionClientSecret = $introspectionClientSecret;
        $this->introspectionCacheTtlSeconds = $introspectionCacheTtlSeconds;
        $this->introspectionCache = $introspectionCache;
    }

    public function name(): string
    {
        return 'oauth2';
    }

    public function login(ServerRequestInterface $request): LoginStep
    {
        $token = $this->readToken($request);

        if (! $this->isJwt($token)) {
            return $this->introspect($token);
        }

        $kid = $this->assertHeader($token);

        $key = $this->jwks->keyFor($kid);

        if ($key === null) {
            throw new AuthenticationFailed('No published signing key matches the access token (oauth2.unknown_key).');
        }

        $claims = $this->decode($token, $key);
        $this->assertClaims($claims);

        return new Authenticated($this->identity($claims));
    }

    public function logoutUrl(string $returnTo): ?string
    {
        // The token arrived with the request; there is nothing to log out on
        // the provider side. Dropping the app's session is the logout.
        return null;
    }

    public function serviceUrl(): ?string
    {
        return null;
    }

    /**
     * Whether the token has the three dot-separated parts of a JWT.
     *
     * This is the routing test, not a validation: a three-part token goes to
     * the local JWT path, where its header and signature are checked, and a
     * shorter token is treated as opaque and sent to introspection (or
     * refused when no endpoint is configured).
     */
    private function isJwt(string $token): bool
    {
        return count(explode('.', $token)) === 3;
    }

    /**
     * An opaque bearer token, checked against the configured introspection
     * endpoint (RFC 7662), with the accepted answer cached briefly under a
     * hash of the token.
     */
    private function introspect(string $token): Authenticated
    {
        $key = $this->introspectionKey($token);
        $cached = $this->introspectionCacheGet($key);

        if ($cached !== null) {
            return new Authenticated($this->identityFromPayload($cached));
        }

        $claims = $this->fetchIntrospection($token);
        $this->assertIntrospectionClaims($claims);
        $identity = $this->introspectionIdentity($claims);

        $this->storeIntrospection($key, $identity, $claims->exp ?? null);

        return new Authenticated($identity);
    }

    /**
     * The introspection answer's claims, as an object.
     *
     * Only a 200 answer whose JSON body is an object with active === true is
     * usable. Anything else fails closed: the token is refused, and the raw
     * token never appears in the message.
     */
    private function fetchIntrospection(string $token): object
    {
        if ($this->introspectionUrl === null || $this->introspectionClientId === null || $this->introspectionClientSecret === null) {
            throw new AuthenticationFailed('The access token is not a valid JWT (oauth2.invalid_jwt).');
        }

        try {
            $request = $this->requests->createRequest('POST', $this->introspectionUrl)
                ->withHeader('Accept', 'application/json')
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withHeader('Authorization', 'Basic ' . base64_encode($this->introspectionClientId . ':' . $this->introspectionClientSecret))
                ->withBody($this->streams->createStream(http_build_query([
                    'token' => $token,
                    'token_type_hint' => 'access_token',
                ])));

            $response = $this->http->sendRequest($request);
        } catch (\Throwable) {
            throw new AuthenticationFailed('The token introspection endpoint could not be reached (oauth2.introspection_unavailable).');
        }

        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new AuthenticationFailed(sprintf('The token introspection endpoint answered %d, not 200 (oauth2.introspection_unavailable).', $status));
        }

        $body = (string) $response->getBody();

        if (strlen($body) > self::MAX_INTROSPECTION_BODY_BYTES) {
            throw new AuthenticationFailed('The token introspection answer is too large (oauth2.introspection_unavailable).');
        }

        $decoded = json_decode($body);

        if (! $decoded instanceof \stdClass) {
            throw new AuthenticationFailed('The token introspection answer is not usable JSON (oauth2.introspection_unavailable).');
        }

        if (($decoded->active ?? false) !== true) {
            throw new AuthenticationFailed('The token is not active (oauth2.inactive).');
        }

        return $decoded;
    }

    /**
     * The claim checks for an introspection answer: expiry, not-before,
     * issuer and audience, each applied when the claim is present.
     */
    private function assertIntrospectionClaims(object $claims): void
    {
        $now = $this->clock->now()->getTimestamp();
        $leeway = $this->leewaySeconds;

        $exp = $claims->exp ?? null;

        if (is_numeric($exp) && ((int) $exp + $leeway) < $now) {
            throw new AuthenticationFailed('The access token has expired (oauth2.expired).');
        }

        $nbf = $claims->nbf ?? null;

        if (is_numeric($nbf) && ((int) $nbf - $leeway) > $now) {
            throw new AuthenticationFailed('The access token is not valid yet (oauth2.not_yet_valid).');
        }

        $iss = $claims->iss ?? null;

        if ($iss !== null && (! is_string($iss) || $iss !== $this->issuer)) {
            throw new AuthenticationFailed('The access token issuer does not match (oauth2.issuer).');
        }

        $aud = $claims->aud ?? null;

        if ($aud !== null && ! self::audienceMatches($aud, $this->audience)) {
            throw new AuthenticationFailed('The access token audience does not match (oauth2.audience).');
        }
    }

    /**
     * The identity from an accepted introspection answer, with the username
     * from the configured claim or from sub when that claim is absent.
     */
    private function introspectionIdentity(object $claims): Identity
    {
        $values = (array) $claims;
        $username = $values[$this->identityClaim] ?? null;

        if ((! is_string($username) || $username === '') && isset($values['sub'])) {
            $username = $values['sub'];
        }

        if (! is_string($username) || $username === '') {
            throw new AuthenticationFailed('The introspection answer has no usable username or "sub" claim (oauth2.missing_claim).');
        }

        return new Identity(
            $username,
            $this->attributes($claims),
            $this->name(),
            $this->clock->now(),
            $this->authTime($claims),
            $this->amr($claims),
        );
    }

    /**
     * The cached introspection payload for this token, or null when there is
     * none, it is expired, or the cache cannot be read.
     *
     * @return array<string, mixed>|null
     */
    private function introspectionCacheGet(string $key): ?array
    {
        if ($this->introspectionCache !== null) {
            try {
                $value = $this->introspectionCache->get($key);
            } catch (\Throwable) {
                return null;
            }

            return is_array($value) ? $value : null;
        }

        $value = $this->introspectionMemory[$key] ?? null;

        if (! is_array($value)) {
            return null;
        }

        if (isset($value['expires_at']) && is_int($value['expires_at']) && $value['expires_at'] <= $this->clock->now()->getTimestamp()) {
            unset($this->introspectionMemory[$key]);

            return null;
        }

        return $value;
    }

    /**
     * Keep an accepted introspection answer in the cache, for the shorter of
     * the configured TTL and the token's remaining life. Rejections are
     * never cached.
     *
     * @param mixed $exp
     */
    private function storeIntrospection(string $key, Identity $identity, mixed $exp): void
    {
        $now = $this->clock->now()->getTimestamp();
        $ttl = $this->introspectionCacheTtlSeconds;

        if ($ttl <= 0) {
            return;
        }

        if (is_numeric($exp)) {
            $ttl = min($ttl, (int) $exp - $now);
        }

        if ($ttl <= 0) {
            return;
        }

        $payload = [
            'username' => $identity->username,
            'attributes' => $identity->attributes,
            'driver' => $identity->driver,
            'authenticated_at' => $identity->authenticatedAt->getTimestamp(),
            'auth_time' => $identity->authTime?->getTimestamp(),
            'amr' => $identity->amr,
            'expires_at' => $now + $ttl,
        ];

        if ($this->introspectionCache !== null) {
            try {
                $this->introspectionCache->set($key, $payload, $ttl);
            } catch (\Throwable) {
                // A broken cache is not a reason to fail the sign-in.
            }

            return;
        }

        $this->introspectionMemory[$key] = $payload;
    }

    /**
     * The identity rebuilt from a cached introspection payload.
     *
     * The original authenticatedAt is kept, so a cached answer still says
     * when the person actually signed in.
     *
     * @param array<string, mixed> $payload
     */
    private function identityFromPayload(array $payload): Identity
    {
        $username = $payload['username'] ?? null;
        $rawAttributes = $payload['attributes'] ?? [];
        $driver = $payload['driver'] ?? null;
        $authenticatedAt = $payload['authenticated_at'] ?? null;
        $authTime = $payload['auth_time'] ?? null;
        $rawAmr = $payload['amr'] ?? [];

        if (! is_string($username) || $username === '') {
            throw new AuthenticationFailed('The cached introspection answer is unusable (oauth2.introspection_unavailable).');
        }

        if (! is_array($rawAttributes)) {
            throw new AuthenticationFailed('The cached introspection answer is unusable (oauth2.introspection_unavailable).');
        }

        $attributes = [];

        foreach ($rawAttributes as $name => $values) {
            if (! is_string($name) || $name === '' || ! is_array($values)) {
                throw new AuthenticationFailed('The cached introspection answer is unusable (oauth2.introspection_unavailable).');
            }

            $list = [];

            foreach ($values as $value) {
                if (! is_string($value) || $value === '') {
                    throw new AuthenticationFailed('The cached introspection answer is unusable (oauth2.introspection_unavailable).');
                }

                $list[] = $value;
            }

            $attributes[$name] = $list;
        }

        if (! is_string($driver) || $driver !== $this->name()) {
            throw new AuthenticationFailed('The cached introspection answer is unusable (oauth2.introspection_unavailable).');
        }

        if (! is_int($authenticatedAt)) {
            throw new AuthenticationFailed('The cached introspection answer is unusable (oauth2.introspection_unavailable).');
        }

        if ($authTime !== null && ! is_int($authTime)) {
            throw new AuthenticationFailed('The cached introspection answer is unusable (oauth2.introspection_unavailable).');
        }

        if (! is_array($rawAmr)) {
            throw new AuthenticationFailed('The cached introspection answer is unusable (oauth2.introspection_unavailable).');
        }

        $amr = [];

        foreach ($rawAmr as $method) {
            if (is_string($method) && $method !== '') {
                $amr[] = $method;
            }
        }

        return new Identity(
            $username,
            $attributes,
            $driver,
            new DateTimeImmutable('@' . $authenticatedAt),
            $authTime === null ? null : new DateTimeImmutable('@' . $authTime),
            $amr,
        );
    }

    /**
     * The cache key for an introspection answer: a hash of the token, never
     * the token itself.
     */
    private function introspectionKey(string $token): string
    {
        return 'sesame.oauth2.introspection.' . hash('sha256', $token);
    }

    /**
     * The bearer token from the Authorization header, or an authentication
     * error when the header is missing or not a bearer token.
     */
    private function readToken(ServerRequestInterface $request): string
    {
        $header = trim($request->getHeaderLine('Authorization'));

        if ($header === '') {
            throw new AuthenticationFailed('The request has no bearer token (oauth2.missing_token).');
        }

        $schemeLength = strlen(self::BEARER_SCHEME) + 1;

        if (strncasecmp($header, self::BEARER_SCHEME . ' ', $schemeLength) !== 0) {
            throw new AuthenticationFailed('The Authorization header is not a bearer token (oauth2.missing_token).');
        }

        $token = trim(substr($header, $schemeLength));

        if ($token === '') {
            throw new AuthenticationFailed('The request has no bearer token (oauth2.missing_token).');
        }

        return $token;
    }

    /**
     * The token's header, checked for the pinned algorithm and a usable kid.
     *
     * @return non-empty-string The key id the token was signed with.
     */
    private function assertHeader(string $token): string
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new AuthenticationFailed('The access token is not a valid JWT (oauth2.invalid_jwt).');
        }

        $header = json_decode(self::base64UrlDecode($parts[0]), true);

        if (! is_array($header)) {
            throw new AuthenticationFailed('The access token is not a valid JWT (oauth2.invalid_jwt).');
        }

        $alg = $header['alg'] ?? null;

        if (! is_string($alg) || $alg !== self::ALGORITHM) {
            // The attacker-chosen algorithm name is not echoed back or logged.
            throw new AuthenticationFailed(sprintf('The access token does not use %s (oauth2.bad_algorithm).', self::ALGORITHM));
        }

        $kid = $header['kid'] ?? null;

        if (! is_string($kid) || $kid === '') {
            throw new AuthenticationFailed('The access token has no usable "kid" header (oauth2.missing_kid).');
        }

        if ($this->requireAtJwt) {
            // RFC 9068 types the access token as at+jwt. The value is not
            // echoed back or logged.
            $typ = $header['typ'] ?? null;

            if (! is_string($typ) || ! in_array(strtolower(trim($typ)), ['at+jwt', 'application/at+jwt'], true)) {
                throw new AuthenticationFailed('The access token is not typed as an RFC 9068 access token (oauth2.bad_typ).');
            }
        }

        return $kid;
    }

    /**
     * The verified claims, with the signature and the time checks done.
     *
     * The library's leeway and timestamp are static, so they are pinned to
     * this driver's config and clock right before the decode: the time
     * checks then use the same "now" the rest of the driver uses.
     */
    private function decode(string $token, Key $key): object
    {
        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = $this->leewaySeconds;
        JWT::$timestamp = $this->clock->now()->getTimestamp();

        try {
            return JWT::decode($token, $key);
        } catch (ExpiredException) {
            throw new AuthenticationFailed('The access token has expired (oauth2.expired).');
        } catch (BeforeValidException) {
            throw new AuthenticationFailed('The access token is not valid yet (oauth2.not_yet_valid).');
        } catch (SignatureInvalidException) {
            throw new AuthenticationFailed('The access token signature is invalid (oauth2.signature).');
        } catch (UnexpectedValueException|InvalidArgumentException|DomainException) {
            throw new AuthenticationFailed('The access token is not a valid JWT (oauth2.invalid_jwt).');
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }
    }

    /**
     * The claim checks: issuer, audience, a usable expiry, and the required
     * scopes and roles.
     */
    private function assertClaims(object $claims): void
    {
        $iss = $claims->iss ?? null;

        if (! is_string($iss) || $iss !== $this->issuer) {
            throw new AuthenticationFailed('The access token issuer does not match (oauth2.issuer).');
        }

        if (! self::audienceMatches($claims->aud ?? null, $this->audience)) {
            throw new AuthenticationFailed('The access token audience does not match (oauth2.audience).');
        }

        $exp = $claims->exp ?? null;

        if (! is_numeric($exp)) {
            throw new AuthenticationFailed('The access token has no usable "exp" claim (oauth2.missing_claim).');
        }

        $this->assertScopes($claims->scp ?? null);
        $this->assertRoles($claims->roles ?? null);
    }

    /**
     * Whether the aud claim carries the expected audience: a single string
     * must match exactly, and a list must include it.
     */
    private static function audienceMatches(mixed $audience, string $expected): bool
    {
        if (is_string($audience)) {
            return $audience === $expected;
        }

        if (is_array($audience)) {
            foreach ($audience as $entry) {
                if (is_string($entry) && $entry === $expected) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The required scopes, checked against the scp claim.
     *
     * The claim is a space-separated string (the shape RFC 8693 and most
     * issuers use); a list is also accepted. When no scopes are required,
     * the claim is not checked at all.
     */
    private function assertScopes(mixed $scopes): void
    {
        if ($this->requiredScopes === []) {
            return;
        }

        $granted = self::scopeList($scopes);

        foreach ($this->requiredScopes as $scope) {
            if (! in_array($scope, $granted, true)) {
                throw new AuthenticationFailed(sprintf('The access token is missing the "%s" scope (oauth2.scope).', $scope));
            }
        }
    }

    /**
     * The granted scopes, as a list: a space-separated string is split, a
     * list keeps its string items, and anything else grants nothing.
     *
     * @return list<string>
     */
    private static function scopeList(mixed $scopes): array
    {
        if (is_string($scopes)) {
            $list = [];

            foreach (explode(' ', $scopes) as $scope) {
                if ($scope !== '') {
                    $list[] = $scope;
                }
            }

            return $list;
        }

        if (is_array($scopes)) {
            $list = [];

            foreach ($scopes as $scope) {
                if (is_string($scope) && $scope !== '') {
                    $list[] = $scope;
                }
            }

            return $list;
        }

        return [];
    }

    /**
     * The required roles, checked against the roles claim.
     *
     * The claim is a list of strings (Microsoft Entra ID's shape). When no
     * roles are required, the claim is not checked at all.
     */
    private function assertRoles(mixed $roles): void
    {
        if ($this->requiredRoles === []) {
            return;
        }

        if (! is_array($roles)) {
            throw new AuthenticationFailed('The access token has no usable "roles" claim (oauth2.role).');
        }

        $granted = [];

        foreach ($roles as $role) {
            if (is_string($role) && $role !== '') {
                $granted[] = $role;
            }
        }

        foreach ($this->requiredRoles as $required) {
            if (! in_array($required, $granted, true)) {
                throw new AuthenticationFailed(sprintf('The access token is missing the "%s" role (oauth2.role).', $required));
            }
        }
    }

    /**
     * The identity, with the username from the configured claim.
     */
    private function identity(object $claims): Identity
    {
        $values = (array) $claims;
        $username = $values[$this->identityClaim] ?? null;

        if (! is_string($username) || $username === '') {
            throw new AuthenticationFailed(sprintf('The access token has no usable "%s" claim (oauth2.missing_claim).', $this->identityClaim));
        }

        return new Identity(
            $username,
            $this->attributes($claims),
            $this->name(),
            $this->clock->now(),
            $this->authTime($claims),
            $this->amr($claims),
        );
    }

    /**
     * The optional claims, as read-only identity attributes.
     *
     * @return array<string, list<string>>
     */
    private function attributes(object $claims): array
    {
        $attributes = [];

        foreach (self::ATTRIBUTE_CLAIMS as $claim) {
            $values = $this->attributeValues($claims->{$claim} ?? null);

            if ($values !== []) {
                $attributes[$claim] = $values;
            }
        }

        return $attributes;
    }

    /**
     * A claim value as a list of strings: a string becomes a one-item list,
     * a list keeps its string items, and anything else is dropped.
     *
     * @return list<string>
     */
    private function attributeValues(mixed $value): array
    {
        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }

        if (is_array($value)) {
            $list = [];

            foreach ($value as $item) {
                if (is_string($item) && $item !== '') {
                    $list[] = $item;
                }
            }

            return $list;
        }

        return [];
    }

    /**
     * The MFA sign-in time, from the token's auth_time claim.
     *
     * The claim is an epoch-seconds integer. A missing claim, or a value
     * that is not a non-negative integer, yields null: the driver never
     * invents a sign-in time, and a bad value can never push it into the
     * future.
     */
    private function authTime(object $claims): ?DateTimeImmutable
    {
        $value = $claims->auth_time ?? null;

        if (! is_int($value) || $value < 0) {
            return null;
        }

        return new DateTimeImmutable('@' . $value);
    }

    /**
     * The authentication methods, from the token's amr claim.
     *
     * The claim is a list of strings. A missing claim, or a value that is
     * not a list, yields an empty list, and any non-string entry is
     * dropped: the driver never invents an authentication method.
     *
     * @return list<string>
     */
    private function amr(object $claims): array
    {
        $value = $claims->amr ?? null;

        if (! is_array($value)) {
            return [];
        }

        $methods = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $methods[] = $item;
            }
        }

        return $methods;
    }

    /**
     * Decode a base64url segment, padding it back up first.
     */
    private static function base64UrlDecode(string $input): string
    {
        $padded = str_pad($input, (int) ceil(strlen($input) / 4) * 4, '=');

        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
