<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;

/**
 * A user provider over a fixed list of GuardTestUser instances.
 *
 * It answers retrieveById() only; everything else throws, so a test that
 * reaches for credentials or remember tokens fails loudly instead of
 * pretending.
 */
final class GuardTestUserProvider implements UserProvider
{
    /**
     * @param  list<GuardTestUser>  $users
     */
    public function __construct(
        private readonly array $users,
    ) {}

    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        foreach ($this->users as $user) {
            if ($user->id === $identifier) {
                return $user;
            }
        }

        return null;
    }

    public function retrieveByToken(mixed $identifier, #[\SensitiveParameter] mixed $token): ?Authenticatable
    {
        throw new \LogicException('The guard test provider has no remember tokens.');
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] mixed $token): void
    {
        throw new \LogicException('The guard test provider has no remember tokens.');
    }

    /**
     * @param  array<mixed, mixed>  $credentials
     */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        throw new \LogicException('The guard test provider has no credentials.');
    }

    /**
     * @param  array<mixed, mixed>  $credentials
     */
    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        throw new \LogicException('The guard test provider has no credentials.');
    }

    /**
     * @param  array<mixed, mixed>  $credentials
     */
    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false): void
    {
        // Nothing to rehash: the test users have no passwords.
    }
}
