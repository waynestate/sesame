<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A PSR-18 client whose server is never home: every request fails the way
 * Guzzle fails on a connect error or a timeout, with a PSR-18 network
 * exception that holds the request (and so the URL, and so the ticket).
 * It records the requests it was asked to send.
 */
final class UnreachableHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        throw new class ('cURL error 28: Operation timed out after 10001 milliseconds for ' . $request->getUri(), $request) extends RuntimeException implements NetworkExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
    }
}
