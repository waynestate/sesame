<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A CAS server double: /login prompts once and then answers silently from
 * its ticket-granting cookie, tickets are single use and bound to their
 * service, and /serviceValidate answers the CAS 2.0 XML.
 */
final class CasDouble implements ClientInterface
{
    public int $prompts = 0;

    private bool $grantingCookie = false;

    private int $issued = 0;

    /** @var array<string, string> ticket => service */
    private array $tickets = [];

    public function __construct(
        private readonly string $username,
    ) {}

    /**
     * The browser at the CAS /login URL: returns the ticket CAS hands back
     * on its redirect to the service, after checking the URL asks for it.
     */
    public function browse(string $loginUrl, string $expectedService): string
    {
        parse_str((string) parse_url($loginUrl, PHP_URL_QUERY), $query);
        if (($query['service'] ?? null) !== $expectedService || ! str_starts_with($loginUrl, 'https://cas.test/cas/login?')) {
            throw new \RuntimeException('The CAS double was sent to an unexpected login URL: ' . $loginUrl);
        }

        if (! $this->grantingCookie) {
            // No CAS session yet: the person signs in, and CAS sets its cookie.
            $this->prompts++;
            $this->grantingCookie = true;
        }

        $ticket = 'ST-' . (++$this->issued) . '-double';
        $this->tickets[$ticket] = $expectedService;

        return $ticket;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        parse_str($request->getUri()->getQuery(), $query);
        $ticket = is_string($query['ticket'] ?? null) ? $query['ticket'] : '';
        $service = $this->tickets[$ticket] ?? null;
        unset($this->tickets[$ticket]);

        $body = $service !== null && $service === ($query['service'] ?? null)
            ? '<cas:serviceResponse xmlns:cas="http://www.yale.edu/tp/cas"><cas:authenticationSuccess><cas:user>'
                . $this->username . '</cas:user></cas:authenticationSuccess></cas:serviceResponse>'
            : '<cas:serviceResponse xmlns:cas="http://www.yale.edu/tp/cas"><cas:authenticationFailure code="INVALID_TICKET">'
                . 'Ticket not recognized</cas:authenticationFailure></cas:serviceResponse>';

        return new Response(200, ['Content-Type' => 'application/xml'], $body);
    }
}
