<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A PSR-18 client for tests: it records every request and answers with the
 * responses it was queued with. It never touches the network.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $queue;

    /**
     * @param  list<ResponseInterface>  $queue
     */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    public function queue(ResponseInterface $response): void
    {
        $this->queue[] = $response;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new RuntimeException('The fake HTTP client has no queued response.');
        }

        return array_shift($this->queue);
    }
}
