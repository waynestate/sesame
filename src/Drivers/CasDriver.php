<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

use DateTimeImmutable;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\LoginStep;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\ReturnTo;

/**
 * The CAS driver, CAS 3.0 against Academica.
 *
 * The service URL is the configured $serviceUrl only. This driver never
 * reads Host, X-Forwarded-Proto, X-Forwarded-Host or any other request
 * header to build it, which removes the wayne-cas proto trust and the
 * phpCAS X-Forwarded-Port break in one rule.
 *
 * Ticket replay: the CAS server refuses a reused ticket with
 * INVALID_TICKET. SESAME relies on that and surfaces it as
 * AuthenticationFailed.
 */
final class CasDriver implements Driver
{
    private const CAS_NAMESPACE = 'http://www.yale.edu/tp/cas';

    private const MAX_TICKET_LENGTH = 256;

    private string $base;

    public function __construct(
        string $baseUrl,
        private readonly string $serviceUrl,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly string $protocol = '3.0',
    ) {
        $this->base = self::absoluteHttps($baseUrl, 'cas.base_url');

        if (str_ends_with($this->base, '/')) {
            $this->base = substr($this->base, 0, -1);
        }

        self::absoluteHttps($serviceUrl, 'cas.service_url');

        if (! in_array($protocol, ['3.0', '2.0'], true)) {
            throw new InvalidConfig(sprintf(
                'The config key "cas.protocol" must be "3.0" or "2.0", got "%s".',
                $protocol,
            ));
        }
    }

    public function name(): string
    {
        return 'cas';
    }

    public function login(ServerRequestInterface $request): LoginStep
    {
        $ticket = $request->getQueryParams()['ticket'] ?? null;

        if ($ticket === null) {
            return new Redirect($this->base.'/login?service='.rawurlencode($this->serviceUrl));
        }

        $this->assertValidTicket($ticket);

        $path = $this->protocol === '3.0' ? '/p3/serviceValidate' : '/serviceValidate';
        $query = http_build_query([
            'service' => $this->serviceUrl,
            'ticket' => $ticket,
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->http->sendRequest(
            $this->requests->createRequest('GET', $this->base.$path.'?'.$query),
        );

        if ($response->getStatusCode() !== 200) {
            throw new AuthenticationFailed(sprintf(
                'The CAS server answered with HTTP %d instead of 200.',
                $response->getStatusCode(),
            ));
        }

        return $this->parse((string) $response->getBody());
    }

    public function logoutUrl(string $returnTo): ?string
    {
        return $this->base.'/logout?service='.rawurlencode(ReturnTo::safe($returnTo, $this->serviceUrl));
    }

    public function serviceUrl(): ?string
    {
        return $this->serviceUrl;
    }

    /**
     * The ticket must look like a CAS ticket before it is sent anywhere.
     */
    private function assertValidTicket(mixed $ticket): void
    {
        if (! is_string($ticket)
            || $ticket === ''
            || strlen($ticket) > self::MAX_TICKET_LENGTH
            || preg_match('/^[A-Za-z0-9._\-]+$/', $ticket) !== 1
        ) {
            throw new AuthenticationFailed('The ticket in the request is not a valid CAS ticket.');
        }
    }

    /**
     * Read a CAS service response.
     *
     * Parsed with the CAS namespace, not string matching. External entities
     * are not loaded: that is the default on PHP 8, and no LIBXML_NOENT is
     * passed.
     */
    private function parse(string $xml): Authenticated
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if ($doc === false) {
            throw new AuthenticationFailed('The CAS server returned a response that is not valid XML.');
        }

        $doc->registerXPathNamespace('cas', self::CAS_NAMESPACE);

        $success = $doc->xpath('//cas:authenticationSuccess');

        if ($success === []) {
            $failure = $doc->xpath('//cas:authenticationFailure');

            if ($failure !== []) {
                $code = (string) $failure[0]['code'];

                throw new AuthenticationFailed($code === ''
                    ? 'CAS authentication failed.'
                    : 'CAS authentication failed: '.$code);
            }

            throw new AuthenticationFailed('The CAS server returned neither a success nor a failure.');
        }

        $successElement = $success[0];
        $successElement->registerXPathNamespace('cas', self::CAS_NAMESPACE);

        $userNodes = $successElement->xpath('cas:user');
        $user = $userNodes === [] ? '' : trim((string) $userNodes[0]);

        if ($user === '') {
            throw new AuthenticationFailed('The CAS success response has no user.');
        }

        $attributes = [];

        $attributeNodes = $successElement->xpath('cas:attributes');

        if ($attributeNodes !== []) {
            foreach ($attributeNodes[0]->children(self::CAS_NAMESPACE) as $name => $value) {
                $attributes[(string) $name][] = (string) $value;
            }
        }

        return new Authenticated(new Identity(
            $user,
            $attributes,
            $this->name(),
            new DateTimeImmutable(),
        ));
    }

    /**
     * The URL must be an absolute https:// URL.
     */
    private static function absoluteHttps(string $url, string $key): string
    {
        if (! str_starts_with($url, 'https://')) {
            throw new InvalidConfig(sprintf(
                'The config key "%s" must be an absolute https:// URL, got "%s".',
                $key,
                $url,
            ));
        }

        return $url;
    }
}
