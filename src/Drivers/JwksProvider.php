<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Waynestate\Sesame\Exception\AuthenticationFailed;

/**
 * The oauth2 driver's key set, fetched from the issuer and kept in a cache.
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
     * @param int $ttlSeconds How long the fetched set stays usable, in seconds.
     */
    public function __construct(
        private ClientInterface $http,
        private RequestFactoryInterface $requests,
        private CacheInterface $cache,
        private ClockInterface $clock,
        private string $jwksUrl,
        private int $ttlSeconds,
    ) {}

    /**
     * The public key for the given key id, or null when no known key set
     * holds it.
     *
     * @throws AuthenticationFailed When the issuer cannot be reached or read.
     */
    public function keyFor(string $kid): ?Key
    {
        $set = $this->cachedSet();

        if ($set === null) {
            // Cold start: no cached set yet, so fetch and keep one.
            $set = $this->fetch();
            $this->store($set);
        }

        $key = $this->findKey($set, $kid);

        if ($key !== null) {
            return $key;
        }

        // An unknown key id means the cached set may be stale: the issuer
        // rotated its keys. Refetch once, at most once per minute, then fail
        // closed rather than trust a key nobody published.
        if ($this->recentlyRefetched()) {
            return null;
        }

        $fresh = $this->fetch();
        $this->store($fresh);
        $this->markRefetched();

        return $this->findKey($fresh, $kid);
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
        try {
            $response = $this->http->sendRequest(
                $this->requests->createRequest('GET', $this->jwksUrl)->withHeader('Accept', 'application/json'),
            );
        } catch (ClientExceptionInterface) {
            throw new AuthenticationFailed('The issuer\'s key set could not be reached (oauth2.jwks_unavailable).');
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new AuthenticationFailed(sprintf('The issuer\'s key set answered %d, not 2xx (oauth2.jwks_unavailable).', $status));
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded) || ! isset($decoded['keys']) || ! is_array($decoded['keys']) || $decoded['keys'] === []) {
            throw new AuthenticationFailed('The issuer\'s key set is not a usable JWKS document (oauth2.jwks_unavailable).');
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
    private function findKey(array $set, string $kid): ?Key
    {
        try {
            $keys = JWK::parseKeySet($set, 'RS256');
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
