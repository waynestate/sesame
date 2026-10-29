<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Oidc;

use Psr\Clock\ClockInterface;
use Waynestate\Sesame\SessionPort;

/**
 * The sign-ins this browser has started and not finished, kept in the app's
 * session.
 *
 * Each one is keyed by a hash of its state value and holds the nonce, the
 * PKCE code verifier, its expiry, the return-to and the step-up facts. A
 * pending sign-in is single use: take() removes it whether or not the rest
 * of the callback succeeds, so a replayed callback finds nothing. Expired
 * entries are dropped on every read, and at most MAX_PENDING are kept, so
 * a browser that opens many sign-in tabs cannot grow the session without
 * bound.
 *
 * @phpstan-type Pending array{nonce: string, verifier: string, expires: int, started: int, return_to: string|null, step_up: bool, max_age: int|null}
 */
final readonly class PendingLogins
{
    public const SESSION_KEY = 'sesame.oidc.pending';

    private const MAX_PENDING = 5;

    public function __construct(
        private SessionPort $session,
        private ClockInterface $clock,
        private int $ttlSeconds,
    ) {}

    /**
     * Remember a sign-in that is about to start.
     *
     * @param Pending $pending
     */
    public function put(string $state, array $pending): void
    {
        $all = $this->live();
        $all[self::key($state)] = $pending;

        // Keep the newest entries only.
        if (count($all) > self::MAX_PENDING) {
            $all = array_slice($all, -self::MAX_PENDING, null, true);
        }

        $this->session->put(self::SESSION_KEY, $all);
    }

    /**
     * Remove and return the pending sign-in for this state, or null when
     * there is none (an unknown, replayed or expired state).
     *
     * @return Pending|null
     */
    public function take(string $state): ?array
    {
        $all = $this->live();
        $key = self::key($state);
        $pending = $all[$key] ?? null;

        unset($all[$key]);

        if ($all === []) {
            $this->session->forget(self::SESSION_KEY);
        } else {
            $this->session->put(self::SESSION_KEY, $all);
        }

        return $pending;
    }

    /**
     * The expiry for a sign-in that starts now.
     */
    public function expiresAt(): int
    {
        return $this->clock->now()->getTimestamp() + $this->ttlSeconds;
    }

    /**
     * The stored entries that are well formed and not expired.
     *
     * @return array<string, Pending>
     */
    private function live(): array
    {
        $stored = $this->session->get(self::SESSION_KEY);

        if (! is_array($stored)) {
            return [];
        }

        $now = $this->clock->now()->getTimestamp();
        $live = [];

        foreach ($stored as $key => $entry) {
            if (! is_string($key) || ! is_array($entry)) {
                continue;
            }

            $nonce = $entry['nonce'] ?? null;
            $verifier = $entry['verifier'] ?? null;
            $expires = $entry['expires'] ?? null;
            $started = $entry['started'] ?? null;
            $returnTo = $entry['return_to'] ?? null;
            $stepUp = $entry['step_up'] ?? null;
            $maxAge = $entry['max_age'] ?? null;

            if (! is_string($nonce) || ! is_string($verifier) || ! is_int($expires) || ! is_int($started)
                || ($returnTo !== null && ! is_string($returnTo)) || ! is_bool($stepUp)
                || ($maxAge !== null && ! is_int($maxAge))
                || $expires <= $now) {
                continue;
            }

            $live[$key] = [
                'nonce' => $nonce,
                'verifier' => $verifier,
                'expires' => $expires,
                'started' => $started,
                'return_to' => $returnTo,
                'step_up' => $stepUp,
                'max_age' => $maxAge,
            ];
        }

        return $live;
    }

    /**
     * The session key for one state: a hash, so the raw state value is not
     * kept as an array key.
     */
    private static function key(string $state): string
    {
        return hash('sha256', $state);
    }
}
