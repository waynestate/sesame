<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Oidc;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Waynestate\Sesame\Exception\AuthenticationFailed;

/**
 * The issuer's OpenID Provider metadata, from its discovery document.
 *
 * The document lives at the issuer plus /.well-known/openid-configuration
 * (OpenID Connect Discovery 1.0, section 4). It is fetched once and kept in
 * a PSR-16 cache for a TTL. Its "issuer" must equal the configured issuer
 * exactly (section 4.3), and every endpoint SESAME uses must be an https://
 * URL. An endpoint set in config wins over the document, and when every
 * endpoint a step needs is set in config the document is never fetched.
 * Anything wrong fails closed with an authentication error.
 */
final readonly class Discovery
{
    /**
     * The largest discovery document accepted, in bytes; real ones are a
     * few KB.
     */
    private const MAX_BODY_BYTES = 262144;

    /**
     * @param array<string, string> $overrides Endpoint overrides, by discovery name.
     */
    public function __construct(
        private string $issuer,
        private ClientInterface $http,
        private RequestFactoryInterface $requests,
        private CacheInterface $cache,
        private int $ttlSeconds,
        private array $overrides = [],
    ) {}

    /**
     * The URL of one required endpoint (authorization_endpoint,
     * token_endpoint or jwks_uri).
     *
     * @throws AuthenticationFailed When the endpoint is neither configured nor discoverable.
     */
    public function endpoint(string $name): string
    {
        $url = $this->optionalEndpoint($name);

        if ($url === null) {
            throw new AuthenticationFailed(sprintf('The issuer does not publish a "%s" (oidc.discovery).', $name));
        }

        return $url;
    }

    /**
     * The URL of one endpoint, or null when the issuer publishes none (the
     * end_session_endpoint is optional).
     *
     * @throws AuthenticationFailed When the discovery document cannot be read.
     */
    public function optionalEndpoint(string $name): ?string
    {
        if (isset($this->overrides[$name])) {
            return $this->overrides[$name];
        }

        $document = $this->document();
        $url = $document[$name] ?? null;

        if ($url === null) {
            return null;
        }

        if (! is_string($url) || ! self::isHttps($url)) {
            throw new AuthenticationFailed(sprintf('The issuer\'s "%s" is not an https:// URL (oidc.discovery).', $name));
        }

        return $url;
    }

    /**
     * The discovery document, from the cache or freshly fetched.
     *
     * @return array<string, mixed>
     */
    private function document(): array
    {
        try {
            $cached = $this->cache->get($this->cacheKey());
        } catch (\Throwable) {
            $cached = null;
        }

        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return $cached;
        }

        $document = $this->fetch();

        try {
            $this->cache->set($this->cacheKey(), $document, $this->ttlSeconds);
        } catch (\Throwable) {
            // Without the cache, the next request fetches again.
        }

        return $document;
    }

    /**
     * Fetch the discovery document and check its issuer.
     *
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        $url = rtrim($this->issuer, '/') . '/.well-known/openid-configuration';

        try {
            $response = $this->http->sendRequest(
                $this->requests->createRequest('GET', $url)->withHeader('Accept', 'application/json'),
            );
        } catch (\Throwable) {
            throw new AuthenticationFailed('The issuer\'s discovery document could not be reached (oidc.discovery_unavailable).');
        }

        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new AuthenticationFailed(sprintf('The issuer\'s discovery document answered %d, not 200 (oidc.discovery_unavailable).', $status));
        }

        $body = (string) $response->getBody();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new AuthenticationFailed('The issuer\'s discovery document is too large (oidc.discovery_unavailable).');
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new AuthenticationFailed('The issuer\'s discovery document is not a JSON object (oidc.discovery_unavailable).');
        }

        // Section 4.3: the issuer in the document must be identical to the
        // issuer the document was fetched for, or the document is not used.
        if (($decoded['issuer'] ?? null) !== $this->issuer) {
            throw new AuthenticationFailed('The issuer\'s discovery document names a different issuer (oidc.discovery_issuer).');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function cacheKey(): string
    {
        return 'sesame.oidc.discovery.' . hash('sha256', $this->issuer);
    }

    private static function isHttps(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts);
    }
}
