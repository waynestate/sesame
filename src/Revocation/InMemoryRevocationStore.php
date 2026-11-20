<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Revocation;

use Waynestate\Sesame\RevocationStore;

/**
 * The in-memory revocation store.
 *
 * It keeps the cutoffs in an array for one process, so it cannot share a
 * logout between two apps on its own; it is what the tests use, and what a
 * single-process app can use while it has nothing else. Called twice for
 * the same user, it keeps the later cutoff, so a logout can never un-revoke
 * an earlier one.
 */
final class InMemoryRevocationStore implements RevocationStore
{
    /** @var array<string, int> Username => latest cutoff (Unix seconds). */
    private array $cutoffs = [];

    public function revokeBefore(string $username, int $at): void
    {
        if (isset($this->cutoffs[$username]) && $this->cutoffs[$username] >= $at) {
            return;
        }

        $this->cutoffs[$username] = $at;
    }

    public function revokedBefore(string $username): ?int
    {
        return $this->cutoffs[$username] ?? null;
    }
}
