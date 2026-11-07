<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Saml;

use Psr\Clock\ClockInterface;
use Waynestate\Sesame\SessionPort;

/**
 * The AuthnRequests this browser has sent and not seen answered, kept in the
 * app's session.
 *
 * Each one is keyed by a hash of its request ID (the value the IdP echoes
 * back as InResponseTo) and holds its expiry, when it started and whether it
 * asked for a step-up. A pending request is single use: take() removes it
 * whether or not the rest of the sign-in succeeds, so a replayed response
 * finds nothing. Expired entries are dropped on every read, and at most
 * MAX_PENDING are kept, so many sign-in tabs cannot grow the session without
 * bound. This is the login binding (S18, S35): a response is accepted only
 * for a sign-in this browser started.
 *
 * @phpstan-type Pending array{expires: int, started: int, step_up: bool}
 */
final readonly class PendingRequests
{
    public const SESSION_KEY = 'sesame.saml.pending';

    private const MAX_PENDING = 5;

    public function __construct(
        private SessionPort $session,
        private ClockInterface $clock,
        private int $ttlSeconds,
    ) {}

    /**
     * Remember a request that is about to be sent.
     */
    public function put(string $requestId, bool $stepUp): void
    {
        $now = $this->clock->now()->getTimestamp();
        $all = $this->live();
        $all[self::key($requestId)] = [
            'expires' => $now + $this->ttlSeconds,
            'started' => $now,
            'step_up' => $stepUp,
        ];

        // Keep the newest entries only.
        if (count($all) > self::MAX_PENDING) {
            $all = array_slice($all, -self::MAX_PENDING, null, true);
        }

        $this->session->put(self::SESSION_KEY, $all);
    }

    /**
     * Remove and return the pending request with this ID, or null when there
     * is none (an unknown, answered or expired request).
     *
     * @return Pending|null
     */
    public function take(string $requestId): ?array
    {
        $all = $this->live();
        $key = self::key($requestId);
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

            $expires = $entry['expires'] ?? null;
            $started = $entry['started'] ?? null;
            $stepUp = $entry['step_up'] ?? null;

            if (! is_int($expires) || ! is_int($started) || ! is_bool($stepUp) || $expires <= $now) {
                continue;
            }

            $live[$key] = ['expires' => $expires, 'started' => $started, 'step_up' => $stepUp];
        }

        return $live;
    }

    private static function key(string $requestId): string
    {
        return hash('sha256', $requestId);
    }
}
