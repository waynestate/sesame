<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Waynestate\Sesame\Identity;
use Waynestate\Sesame\RefreshesUser;
use Waynestate\Sesame\UserResolver;

/**
 * A UserResolver that also refreshes.
 *
 * It keeps its users in an array keyed by the user's key, the way the app's
 * own store would hold them, so a test can replace or delete the stored user
 * between requests and watch the core follow.
 */
final class RefreshingResolver implements UserResolver, RefreshesUser
{
    /** @var array<string, mixed> */
    private array $users;

    public function __construct(mixed $user)
    {
        $key = $this->keyOf($user);

        $this->users = $key === null ? [] : [$key => $user];
    }

    public function findOrImportUser(Identity $identity): mixed
    {
        return $this->users[$identity->username] ?? null;
    }

    public function refreshUser(mixed $user): mixed
    {
        $key = $this->keyOf($user);

        if ($key === null) {
            return $user;
        }

        return $this->users[$key] ?? null;
    }

    /**
     * Store a new copy of the user, the way the app's store would change it.
     */
    public function replace(mixed $user): void
    {
        $key = $this->keyOf($user);

        if ($key !== null) {
            $this->users[$key] = $user;
        }
    }

    /**
     * Drop the user from the store, the way a delete would.
     */
    public function forget(string $key): void
    {
        unset($this->users[$key]);
    }

    private function keyOf(mixed $user): ?string
    {
        if (! is_object($user) || ! isset($user->key)) {
            return null;
        }

        return (string) $user->key;
    }
}
