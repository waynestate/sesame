<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Psr\Http\Message\ServerRequestInterface;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\WayneAuth\WayneAuth;

/**
 * The Wayne Auth bridge with its seams swapped for tests: the request
 * is the one given, a redirect throws RedirectSent (and the 401 for a
 * script's request throws UnauthorizedSent) instead of sending a header and
 * exiting, and the time is a fixed clock's.
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

    protected function unauthorized(): never
    {
        throw new UnauthorizedSent();
    }

    protected function now(): int
    {
        return $this->testNow;
    }
}
