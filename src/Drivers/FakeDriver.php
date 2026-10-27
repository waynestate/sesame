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
 * construct outside the allowed development environments: it throws, it never
 * silently falls back. The check is an allowlist, so an environment that is
 * not explicitly a development one (production, staging, or anything unknown)
 * can never boot the fake driver.
 */
final class FakeDriver implements Driver
{
    /**
     * The only environments the fake driver may run in.
     *
     * @var list<string>
     */
    private const ALLOWED_ENVIRONMENTS = ['local', 'development', 'dev', 'testing', 'test'];

    /**
     * @param  array<string, list<string>>  $attributes
     */
    public function __construct(
        private readonly string $environment,
        private readonly string $username,
        private readonly array $attributes,
    ) {
        if (! in_array(strtolower(trim($environment)), self::ALLOWED_ENVIRONMENTS, true)) {
            throw new FakeDriverInProduction('The fake driver only runs in local, development and testing environments.');
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
