<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use UnexpectedValueException;
use Waynestate\Sesame\Exception\AuthenticationFailed;

/**
 * An issuer's key set, fetched from the issuer and kept in a cache.
 *
 * The oauth2 driver uses it for bearer tokens and the oidc driver for ID
 * tokens, so both verify signatures through the same code.
 *
 * A JWKS is small and changes rarely (the issuer rotates its signing keys),
 * so the provider fetches it once, keeps the parsed set in a PSR-16 cache
 * for a TTL, and answers key lookups from the cache. When a token carries a
 * key id the cached set does not know, the issuer may have rotated: the
 * provider refetches once, at most once per minute, then fails closed. Any
 * failure to reach or read the issuer fails closed with an authentication
 * error, never with an open door.
 */
final readonly class JwksProvider
{
    /**
     * The longest two requests may share one refetch, in seconds: a token
     * with an unknown key id triggers at most one refetch per minute, so a
     * flood of bad tokens cannot hammer the issuer.
     */
    private const REFETCH_COOLDOWN_SECONDS = 60;

    /**
     * The largest key set body accepted, in bytes; real sets are a few KB.
     */
    private const MAX_BODY_BYTES = 262144;

    /**
     * @param int $ttlSeconds How long the fetched set stays usable, in seconds.
     * @param string $reasonPrefix The driver name the failure reasons carry, for example "oauth2" in "oauth2.jwks_unavailable".
     */
    public function __construct(
        private ClientInterface $http,
        private RequestFactoryInterface $requests,
        private CacheInterface $cache,
        private ClockInterface $clock,
        private string $jwksUrl,
        private int $ttlSeconds,
        private string $reasonPrefix = 'oauth2',
    ) {}

    /**
     * The public key for the given key id, or null when no known key set
     * holds it.
     *
     * The algorithm is the one the caller has already checked against its
     * allowlist. It is the default for keys that do not name their own
     * algorithm; a key that names a different one never verifies the token,
     * because the JWT library refuses a key whose algorithm does not match
     * the token's header.
     *
     * @throws AuthenticationFailed When the issuer cannot be reached or read.
     */
    public function keyFor(string $kid, string $algorithm = 'RS256'): ?Key
    {
        $set = $this->cachedSet();
        $justFetched = false;

        if ($set === null) {
            // Cold start: no cached set yet, so fetch and keep one.
            $set = $this->fetch();
            $this->store($set);
            $this->markRefetched();
            $justFetched = true;
        }

        $key = $this->findKey($set, $kid, $algorithm);

        if ($key !== null) {
            return $key;
        }

        // An unknown key id means the cached set may be stale: the issuer
        // rotated its keys. Refetch once, at most once per minute, then fail
        // closed rather than trust a key nobody published.
        if ($justFetched || $this->recentlyRefetched()) {
            return null;
        }

        // Mark before fetching so a failing issuer is not hammered either.
        $this->markRefetched();
        $fresh = $this->fetch();
        $this->store($fresh);

        return $this->findKey($fresh, $kid, $algorithm);
    }

    /**
     * The cached key set, or null when there is none or its TTL has passed.
     *
     * @return array<string, mixed>|null
     */
    private function cachedSet(): ?array
    {
        try {
            $set = $this->cache->get($this->setKey());
        } catch (\Throwable) {
            // A broken cache is not a reason to sign anyone in: treat it as
            // no cache and fetch fresh.
            return null;
        }

        return is_array($set) ? $set : null;
    }

    /**
     * Fetch the issuer's key set over HTTP and check its shape.
     *
     * @return array<string, mixed>
     *
     * @throws AuthenticationFailed When the endpoint is unreachable or unreadable.
     */
    private function fetch(): array
    {
        // Any failure here fails closed: a transport that cannot reach the
        // issuer, or that misbehaves, must never leave the door open.
        try {
            $response = $this->http->sendRequest(
                $this->requests->createRequest('GET', $this->jwksUrl)->withHeader('Accept', 'application/json'),
            );
        } catch (\Throwable) {
            throw new AuthenticationFailed('The issuer\'s key set could not be reached (' . $this->reasonPrefix . '.jwks_unavailable).');
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new AuthenticationFailed(sprintf('The issuer\'s key set answered %d, not 2xx (%s.jwks_unavailable).', $status, $this->reasonPrefix));
        }

        $body = (string) $response->getBody();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new AuthenticationFailed('The issuer\'s key set is too large (' . $this->reasonPrefix . '.jwks_unavailable).');
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! isset($decoded['keys']) || ! is_array($decoded['keys']) || $decoded['keys'] === []) {
            throw new AuthenticationFailed('The issuer\'s key set is not a usable JWKS document (' . $this->reasonPrefix . '.jwks_unavailable).');
        }

        return $decoded;
    }

    /**
     * Keep the fetched set in the cache for its TTL.
     *
     * @param array<string, mixed> $set
     */
    private function store(array $set): void
    {
        try {
            $this->cache->set($this->setKey(), $set, $this->ttlSeconds);
        } catch (\Throwable) {
            // If the cache refuses, the next request refetches. That costs
            // one extra HTTP call and nothing more.
        }
    }

    /**
     * Whether a refetch already happened within the cooldown window.
     */
    private function recentlyRefetched(): bool
    {
        try {
            return $this->cache->has($this->refetchKey());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Record that a refetch just happened, for the cooldown window.
     */
    private function markRefetched(): void
    {
        try {
            $this->cache->set(
                $this->refetchKey(),
                $this->clock->now()->getTimestamp(),
                self::REFETCH_COOLDOWN_SECONDS,
            );
        } catch (\Throwable) {
            // Without the marker, the worst case is one extra refetch.
        }
    }

    /**
     * The public key for the given key id in the set, or null when the set
     * holds no such key or the set is malformed.
     *
     * @param array<string, mixed> $set
     */
    private function findKey(array $set, string $kid, string $algorithm): ?Key
    {
        try {
            $keys = JWK::parseKeySet($set, $algorithm);
        } catch (InvalidArgumentException|UnexpectedValueException|DomainException) {
            return null;
        }

        return $keys[$kid] ?? null;
    }

    /**
     * The cache key for the fetched set, per issuer URL.
     */
    private function setKey(): string
    {
        return 'sesame.oauth2.jwks.' . hash('sha256', $this->jwksUrl);
    }

    /**
     * The cache key for the refetch marker, per issuer URL.
     */
    private function refetchKey(): string
    {
        return 'sesame.oauth2.jwks.refetched.' . hash('sha256', $this->jwksUrl);
    }
}
