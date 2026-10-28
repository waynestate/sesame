<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Psr\Http\Message\ServerRequestInterface;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\LoginStep;

/**
 * A Driver that answers with whatever step it was built with and records
 * every call, in order.
 */
final class RecordingDriver implements Driver
{
    public function __construct(
        private readonly LoginStep $step,
        private readonly ?string $logoutUrl,
        private readonly EventLog $log,
        private readonly ?string $serviceUrl = null,
    ) {}

    public function name(): string
    {
        return 'recording';
    }

    public function login(ServerRequestInterface $request): LoginStep
    {
        $this->log->record('login');

        return $this->step;
    }

    public function logoutUrl(string $returnTo): ?string
    {
        $this->log->record('logoutUrl');

        return $this->logoutUrl;
    }

    public function serviceUrl(): ?string
    {
        return $this->serviceUrl;
    }
}
