<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Saml;

use DOMDocument;
use DOMXPath;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use OneLogin\Saml2\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Exception\ProviderUnavailable;

/**
 * What the driver needs to know about the IdP: its entity ID, where to send
 * the AuthnRequest, and the certificates its signatures must verify against.
 *
 * Read from a metadata file (at boot, by SamlOptions) or fetched from the
 * IdP's metadata URL over https through the app's PSR-18 client and kept in
 * the PSR-16 cache for saml.metadata_cache_ttl_seconds. The XML is parsed by
 * php-saml's IdPMetadataParser (which refuses a DOCTYPE). The metadata's own
 * signature is not checked: trust comes from https (or from the file the
 * app ships), and saml.idp.cert_file pins the signing certificate when the
 * app wants to trust only that one. A document with more than one IdP needs
 * saml.idp.entity_id to say which, so the driver never guesses.
 *
 * @phpstan-type Idp array{entityId: string, ssoUrl: string, certs: list<string>}
 */
final class IdpMetadata
{
    /** @var Idp|null */
    private ?array $idp = null;

    public function __construct(
        private readonly SamlOptions $options,
        private readonly ?ClientInterface $http,
        private readonly ?RequestFactoryInterface $requests,
        private readonly CacheInterface $cache,
    ) {
        if ($options->idpMetadataUrl !== null && ($http === null || $requests === null)) {
            throw new InvalidConfig('The saml driver needs a PSR-18 client and a PSR-17 request factory to fetch SESAME_SAML_IDP_METADATA_URL. Pass $http and $requests to DriverFactory::make(), or use SESAME_SAML_IDP_METADATA_FILE.');
        }
    }

    /**
     * The IdP, parsed and checked.
     *
     * @return Idp
     *
     * @throws AuthenticationFailed When fetched metadata cannot be used.
     * @throws InvalidConfig When a metadata file cannot be used.
     */
    public function idp(): array
    {
        if ($this->idp !== null) {
            return $this->idp;
        }

        if ($this->options->idpMetadataXml !== null) {
            try {
                return $this->idp = $this->parse($this->options->idpMetadataXml);
            } catch (AuthenticationFailed $e) {
                throw new InvalidConfig(str_replace('(saml.metadata)', '(saml.idp.metadata_file)', $e->getMessage()));
            }
        }

        $key = 'sesame.saml.idp.' . hash('sha256', (string) $this->options->idpMetadataUrl . '|' . (string) $this->options->idpEntityId);

        try {
            $cached = $this->cache->get($key);
        } catch (\Throwable) {
            $cached = null;
        }

        if (self::isIdp($cached)) {
            /** @var Idp $cached */
            return $this->idp = $cached;
        }

        $idp = $this->parse($this->fetch());

        try {
            $this->cache->set($key, $idp, $this->options->metadataCacheTtlSeconds);
        } catch (\Throwable) {
            // Without the cache, the next request fetches again.
        }

        return $this->idp = $idp;
    }

    /**
     * Fetch the metadata document. Any answer but 200, a redirect included,
     * fails: the app's client must not follow redirects.
     */
    private function fetch(): string
    {
        $url = (string) $this->options->idpMetadataUrl;

        /** @var ClientInterface $http */
        $http = $this->http;
        /** @var RequestFactoryInterface $requests */
        $requests = $this->requests;

        try {
            $response = $http->sendRequest(
                $requests->createRequest('GET', $url)->withHeader('Accept', 'application/samlmetadata+xml, application/xml;q=0.9'),
            );
        } catch (\Throwable) {
            throw new ProviderUnavailable('The IdP metadata could not be reached (saml.metadata).');
        }

        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new AuthenticationFailed(sprintf('The IdP metadata answered %d, not 200 (saml.metadata).', $status));
        }

        $body = (string) $response->getBody();

        if ($body === '' || strlen($body) > SamlOptions::MAX_METADATA_BYTES) {
            throw new AuthenticationFailed('The IdP metadata is empty or too large; point at the IdP\'s own metadata, not a federation aggregate (saml.metadata).');
        }

        return $body;
    }

    /**
     * Parse one IdP out of a metadata document.
     *
     * @return Idp
     */
    private function parse(string $xml): array
    {
        $entityId = $this->options->idpEntityId;

        $count = self::countIdps($xml, $entityId);

        if ($count === 0) {
            throw new AuthenticationFailed($entityId === null
                ? 'The IdP metadata holds no IDPSSODescriptor (saml.metadata).'
                : 'The IdP metadata holds no IdP with the entity ID in SESAME_SAML_IDP_ENTITY_ID (saml.metadata).');
        }

        if ($count > 1) {
            throw new AuthenticationFailed('The IdP metadata holds more than one IdP; set SESAME_SAML_IDP_ENTITY_ID to the one to trust (saml.metadata).');
        }

        try {
            $info = IdPMetadataParser::parseXML($xml, $entityId, null, Constants::BINDING_HTTP_REDIRECT);
        } catch (\Throwable) {
            throw new AuthenticationFailed('The IdP metadata could not be parsed (saml.metadata).');
        }

        $idp = is_array($info['idp'] ?? null) ? $info['idp'] : [];
        $parsedEntityId = $idp['entityId'] ?? null;
        $sso = is_array($idp['singleSignOnService'] ?? null) ? $idp['singleSignOnService'] : [];
        $ssoUrl = $sso['url'] ?? null;

        if (! is_string($parsedEntityId) || $parsedEntityId === '') {
            throw new AuthenticationFailed('The IdP metadata names no entity ID (saml.metadata).');
        }

        if (($sso['binding'] ?? null) !== Constants::BINDING_HTTP_REDIRECT || ! is_string($ssoUrl) || ! self::isHttps($ssoUrl)) {
            throw new AuthenticationFailed('The IdP metadata has no https SingleSignOnService with the HTTP-Redirect binding (saml.metadata).');
        }

        $certs = [];

        if ($this->options->idpCert !== null) {
            $certs[] = $this->options->idpCert;
        } else {
            $multi = is_array($idp['x509certMulti'] ?? null) ? $idp['x509certMulti'] : [];
            $signing = is_array($multi['signing'] ?? null) ? $multi['signing'] : [];
            $candidates = isset($idp['x509cert']) ? [$idp['x509cert']] : $signing;

            foreach ($candidates as $cert) {
                if (is_string($cert) && trim($cert) !== '') {
                    $certs[] = $cert;
                }
            }
        }

        if ($certs === []) {
            throw new AuthenticationFailed('The IdP metadata publishes no signing certificate (saml.metadata).');
        }

        return ['entityId' => $parsedEntityId, 'ssoUrl' => $ssoUrl, 'certs' => $certs];
    }

    /**
     * How many EntityDescriptors with an IDPSSODescriptor the document
     * holds (only the one with $entityId, when it is set).
     */
    private static function countIdps(string $xml, ?string $entityId): int
    {
        $dom = new DOMDocument();

        try {
            $loaded = Utils::loadXML($dom, $xml);
        } catch (\Throwable) {
            $loaded = false;
        }

        if (! $loaded instanceof DOMDocument) {
            throw new AuthenticationFailed('The IdP metadata is not well-formed XML, or carries a DOCTYPE (saml.metadata).');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('md', Constants::NS_MD);
        $nodes = $xpath->query('//md:EntityDescriptor[md:IDPSSODescriptor]');
        $count = 0;

        if ($nodes === false) {
            return 0;
        }

        foreach ($nodes as $node) {
            if ($entityId === null || ($node instanceof \DOMElement && $node->getAttribute('entityID') === $entityId)) {
                $count++;
            }
        }

        return $count;
    }

    private static function isIdp(mixed $value): bool
    {
        return is_array($value)
            && is_string($value['entityId'] ?? null)
            && is_string($value['ssoUrl'] ?? null)
            && is_array($value['certs'] ?? null)
            && $value['certs'] !== []
            && array_is_list($value['certs']);
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
