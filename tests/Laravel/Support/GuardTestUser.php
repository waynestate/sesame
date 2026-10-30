<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A minimal app user for the guard tests.
 *
 * It carries an id and a username, and nothing else: the guard only ever
 * asks for the identifier, and the tests assert on both.
 */
final class GuardTestUser implements Authenticatable
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(mixed $value): void
    {
        // The test users are never remembered.
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
