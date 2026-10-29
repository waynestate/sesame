<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

use DateTimeImmutable;
use Exception;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use SimpleXMLElement;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Clock\SystemClock;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\LoginStep;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\ReturnTo;

/**
 * The CAS driver, CAS 2.0 against Academica by default.
 *
 * The service URL is the configured $serviceUrl only. This driver never
 * reads Host, X-Forwarded-Proto, X-Forwarded-Host or any other request
 * header to build it, which removes the wayne-cas proto trust and the
 * phpCAS X-Forwarded-Port break in one rule.
 *
 * The default protocol is 2.0 because Academica is believed to redirect
 * cookieless /p3/serviceValidate calls to the login page. Set the protocol
 * to 3.0 (cas.protocol) when a CAS server does release attributes on
 * /p3/serviceValidate.
 *
 * Ticket replay: the CAS server refuses a reused ticket with
 * INVALID_TICKET. SESAME relies on that and surfaces it as
 * AuthenticationFailed.
 *
 * The sign-in time comes from the CAS authenticationDate attribute when the
 * server sends one; otherwise it is the driver's clock. Time is read through
 * a PSR-20 clock, so the future-date guard is testable.
 *
 * CAS carries no MFA signal of its own, so by default the identity carries
 * no MFA facts and the MFA check fails closed. With assume_mfa on, the
 * driver trusts the CAS server's owner's word that a successful validation
 * means MFA happened for the user's current SSO session: the identity's amr
 * then includes "mfa", and its MFA time (authTime) is the
 * authenticationDate when the server sent one. The driver never invents an
 * authTime: with no authenticationDate, the MFA time stays unset and the
 * MFA check answers NO_AUTH_TIME.
 */
final class CasDriver implements Driver
{
    private const CAS_NAMESPACE = 'http://www.yale.edu/tp/cas';

    private const MAX_TICKET_LENGTH = 256;

    private string $base;

    private readonly ClockInterface $clock;

    public function __construct(
        string $baseUrl,
        private readonly string $serviceUrl,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly string $protocol = '2.0',
        private readonly bool $renew = false,
        private readonly bool $assumeMfa = false,
        ?ClockInterface $clock = null,
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

        $this->clock = $clock ?? new SystemClock();
    }

    public function name(): string
    {
        return 'cas';
    }

    public function login(ServerRequestInterface $request): LoginStep
    {
        $ticket = $request->getQueryParams()['ticket'] ?? null;

        if ($ticket === null) {
            return new Redirect($this->base . '/login?' . http_build_query($this->loginQuery(), '', '&', PHP_QUERY_RFC3986));
        }

        $this->assertValidTicket($ticket);

        $path = $this->protocol === '3.0' ? '/p3/serviceValidate' : '/serviceValidate';
        $query = http_build_query($this->loginQuery() + ['ticket' => $ticket], '', '&', PHP_QUERY_RFC3986);

        $response = $this->http->sendRequest(
            $this->requests->createRequest('GET', $this->base . $path . '?' . $query),
        );

        if ($response->getStatusCode() !== 200) {
            throw new AuthenticationFailed(sprintf(
                'The CAS server answered with HTTP %d instead of 200.',
                $response->getStatusCode(),
            ));
        }

        return $this->parse((string) $response->getBody());
    }

    /**
     * The query parameters for the /login redirect and the /serviceValidate
     * request, built with the same http_build_query call so both URLs encode
     * identically.
     *
     * With renew on, both carry renew=true. The CAS 3.0 spec (section 2.1.1)
     * says the server then requires primary credentials instead of the SSO
     * session, and section 2.5.1 says /serviceValidate fails for a ticket
     * issued from an SSO session; section 2.5.3 names that error
     * INVALID_TICKET. SESAME relies on the server for that check and adds
     * none of its own, because the spec defines no client-side signal for
     * it.
     *
     * @return array<string, string>
     */
    private function loginQuery(): array
    {
        $query = ['service' => $this->serviceUrl];

        if ($this->renew) {
            $query['renew'] = 'true';
        }

        return $query;
    }

    public function logoutUrl(string $returnTo): string
    {
        $return = rawurlencode(ReturnTo::safe($returnTo, $this->serviceUrl));

        // Send the return address as both service= and url=: Academica reads
        // url=, and service= is the CAS-standard parameter name.
        return $this->base . '/logout?service=' . $return . '&url=' . $return;
    }

    public function serviceUrl(): string
    {
        return $this->serviceUrl;
    }

    /**
     * The ticket must look like a CAS service ticket before it is sent anywhere.
     *
     * Only ST- service tickets are accepted. Proxy tickets (PT-) and anything
     * with a query string smuggled in are rejected before any HTTP call.
     */
    private function assertValidTicket(mixed $ticket): void
    {
        if (! is_string($ticket)
            || strlen($ticket) > self::MAX_TICKET_LENGTH
            || preg_match('/^ST-[A-Za-z0-9._\-]{1,253}$/', $ticket) !== 1
        ) {
            throw new AuthenticationFailed('The ticket in the request is not a valid CAS ticket.');
        }
    }

    /**
     * Read a CAS service response.
     *
     * Parsed with the CAS namespace, not string matching. A DOCTYPE is
     * rejected outright, and the document is parsed with LIBXML_NONET so no
     * external entities are loaded. Elements are matched by local name and
     * namespace URI, never by prefix, so only elements in the real CAS
     * namespace count whatever prefix (or none) the document binds to it.
     * The success element must be a direct child of the root
     * serviceResponse. A failure element wins over a success element: a
     * response carrying both is a failure.
     */
    private function parse(string $xml): Authenticated
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new AuthenticationFailed('The CAS server returned a response with a DOCTYPE, which is not allowed.');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($doc === false) {
            throw new AuthenticationFailed('The CAS server returned a response that is not valid XML.');
        }

        // Check the failure element first so a response that carries both a
        // failure and a success is treated as a failure.
        $failure = $this->casChildren($doc, 'authenticationFailure');

        if ($failure !== []) {
            $code = (string) $failure[0]['code'];

            throw new AuthenticationFailed($code === ''
                ? 'CAS authentication failed.'
                : 'CAS authentication failed: ' . $code);
        }

        $success = $this->casChildren($doc, 'authenticationSuccess');

        if ($success === []) {
            throw new AuthenticationFailed('The CAS server returned neither a success nor a failure.');
        }

        $successElement = $success[0];

        $userNodes = $this->casChildren($successElement, 'user');
        $user = $userNodes === [] ? '' : trim((string) $userNodes[0]);

        if ($user === '') {
            throw new AuthenticationFailed('The CAS success response has no user.');
        }

        $attributes = [];

        $attributeNodes = $this->casChildren($successElement, 'attributes');

        if ($attributeNodes !== []) {
            foreach ($attributeNodes[0]->children(self::CAS_NAMESPACE) as $name => $value) {
                $attributes[$name][] = (string) $value;
            }
        }

        return new Authenticated(new Identity(
            $user,
            $attributes,
            $this->name(),
            $this->authenticatedAt($attributes),
            $this->authTime($attributes),
            $this->amr(),
        ));
    }

    /**
     * The direct children of $element in the CAS namespace, matched by local
     * name and namespace URI.
     *
     * XPath prefix resolution honors the document's own namespace
     * declarations, which shadow registerXPathNamespace: a body that binds
     * the "cas" prefix to another namespace would answer a "cas:" query with
     * its forged elements. local-name() and namespace-uri() are evaluated
     * per node, so no prefix the document declares can steer this query.
     *
     * @return list<SimpleXMLElement>
     */
    private function casChildren(SimpleXMLElement $element, string $localName): array
    {
        $query = sprintf(
            "*[local-name()='%s' and namespace-uri()='%s']",
            $localName,
            self::CAS_NAMESPACE,
        );

        $nodes = $element->xpath($query);

        if ($nodes === false || $nodes === null) {
            return [];
        }

        return array_values($nodes);
    }

    /**
     * The identity's MFA time, for the MFA check.
     *
     * Only set when assume_mfa is on, and only from the CAS
     * authenticationDate attribute: the driver never invents one. When the
     * attribute is absent, unparseable, or more than five minutes ahead of
     * the driver's clock, the MFA time stays unset and the MFA check
     * answers NO_AUTH_TIME.
     *
     * @param array<string, list<string>> $attributes
     */
    private function authTime(array $attributes): ?DateTimeImmutable
    {
        if (! $this->assumeMfa) {
            return null;
        }

        return $this->authenticationDate($attributes);
    }

    /**
     * The identity's amr list.
     *
     * Empty by default: CAS carries no MFA signal, so the MFA check fails
     * closed. With assume_mfa on, the list includes "mfa" (any other values
     * it carries are kept).
     *
     * @return list<string>
     */
    private function amr(): array
    {
        $amr = [];

        if ($this->assumeMfa) {
            $amr[] = 'mfa';
        }

        return $amr;
    }

    /**
     * The time the identity was authenticated, for the Identity.
     *
     * The CAS 3.0 spec (Appendix A) lets the server send an
     * authenticationDate attribute, an ISO 8601 date-time. When it is present
     * and parseable, it is the authenticatedAt. When it is missing,
     * unparseable, or more than five minutes ahead of the driver's clock (a
     * clock skew or a bad value), the driver's own now is used instead, so a
     * bad value can never push the sign-in time into the future.
     *
     * @param array<string, list<string>> $attributes
     */
    private function authenticatedAt(array $attributes): DateTimeImmutable
    {
        return $this->authenticationDate($attributes) ?? $this->clock->now();
    }

    /**
     * The CAS authenticationDate attribute, parsed, or null when it is
     * absent, unparseable, or more than five minutes ahead of the driver's
     * clock (a clock skew or a bad value).
     *
     * @param array<string, list<string>> $attributes
     */
    private function authenticationDate(array $attributes): ?DateTimeImmutable
    {
        $dates = $attributes['authenticationDate'] ?? [];
        $value = $dates === [] ? null : $dates[0];

        if ($value === null) {
            return null;
        }

        $parsed = $this->parseAuthenticationDate($value);

        if ($parsed === null || $parsed > $this->clock->now()->modify('+5 minutes')) {
            return null;
        }

        return $parsed;
    }

    /**
     * Parse an ISO 8601 date-time with a time zone, with optional fractional
     * seconds, into a DateTimeImmutable. Returns null when the value does not
     * match the shape or does not parse.
     */
    private function parseAuthenticationDate(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $value) !== 1) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * The URL must be an absolute https:// URL with a host and no credentials.
     */
    private static function absoluteHttps(string $url, string $key): string
    {
        $parts = parse_url($url);

        if ($parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? '') === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
        ) {
            throw new InvalidConfig(sprintf(
                'The config key "%s" must be an absolute https:// URL, got "%s".',
                $key,
                $url,
            ));
        }

        return $url;
    }
}
