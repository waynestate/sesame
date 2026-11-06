<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Psr\Http\Message\ServerRequestInterface;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\WayneAuth\WayneAuth;

/**
 * The Wayne Auth bridge with its three seams swapped for tests: the request
 * is the one given, a redirect throws RedirectSent instead of sending a
 * header and exiting, and the time is a fixed clock's.
 */
final class TestableWayneAuth extends WayneAuth
{
    public function __construct(
        private readonly ServerRequestInterface $testRequest,
        private readonly int $testNow,
        mixed $config = [],
        ?Sesame $sesame = null,
        ?string $onGuest = null,
        ?string $accessIdCase = null,
    ) {
        parent::__construct($config, $sesame, $onGuest, $accessIdCase);
    }

    protected function request(): ServerRequestInterface
    {
        return $this->testRequest;
    }

    protected function redirect(string $url): never
    {
        throw new RedirectSent($url);
    }

    protected function now(): int
    {
        return $this->testNow;
    }
}
