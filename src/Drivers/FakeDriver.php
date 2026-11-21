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
 * construct unless the environment is local or testing AND the caller opted
 * in ($allowed, from SESAME_FAKE_ALLOWED): it throws, it never silently falls
 * back. The check is an allowlist, so an environment that is not explicitly
 * one of those two (development, production, staging, or anything unknown)
 * can never boot the fake driver, no matter what the opt-in says.
 */
final class FakeDriver implements Driver
{
    /**
     * The environments the fake driver may boot in, when opted in.
     *
     * @var list<string>
     */
    public const ENVIRONMENTS = ['local', 'testing'];

    /**
     * @param  array<string, list<string>>  $attributes
     */
    public function __construct(
        private readonly string $environment,
        private readonly string $username,
        private readonly array $attributes,
        private readonly bool $allowed = false,
    ) {
        if (! in_array(strtolower(trim($environment)), self::ENVIRONMENTS, true) || ! $this->allowed) {
            throw new FakeDriverInProduction('The fake driver runs only when the environment is local or testing and SESAME_FAKE_ALLOWED is true.');
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

    public function serviceUrl(): ?string
    {
        return null;
    }
}
