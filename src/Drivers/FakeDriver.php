<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Exception\FakeDriverInProduction;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\LoginStep;

/**
 * A driver for local development and tests only.
 *
 * It returns the identity it was built with, every time. It refuses to
 * construct when the environment is production: it throws, it never silently
 * falls back.
 */
final class FakeDriver implements Driver
{
    /**
     * @param  array<string, list<string>>  $attributes
     */
    public function __construct(
        private readonly string $environment,
        private readonly string $username,
        private readonly array $attributes,
    ) {
        if (strtolower(trim($environment)) === 'production') {
            throw new FakeDriverInProduction('The fake driver refuses to run in production.');
        }
    }

    public function name(): string
    {
        return 'fake';
    }

    public function login(ServerRequestInterface $request): LoginStep
    {
        return new Authenticated(new Identity(
            $this->username,
            $this->attributes,
            $this->name(),
            new DateTimeImmutable(),
        ));
    }

    public function logoutUrl(string $returnTo): ?string
    {
        return null;
    }
}
