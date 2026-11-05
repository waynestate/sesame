<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Cas;

use Psr\Clock\ClockInterface;
use Waynestate\Sesame\SessionPort;

/**
 * The sign-ins this browser has started and not finished, kept in the app's
 * session.
 *
 * Each marker is a random id and its expiry, stored in the order the
 * sign-ins started. A pending sign-in is single use: take() removes it
 * whether or not the rest of the login succeeds, so a replayed ticket finds
 * nothing. Expired entries are dropped on every read, and at most
 * MAX_PENDING are kept, so a browser that opens many sign-in tabs cannot
 * grow the session without bound.
 *
 * @phpstan-type Marker array{id: string, expires: int}
 */
final readonly class PendingLogin
{
    public const SESSION_KEY = 'sesame.cas.pending';

    private const MAX_PENDING = 5;

    private const TTL_SECONDS = 600;

    public function __construct(
        private SessionPort $session,
        private ClockInterface $clock,
    ) {}

    /**
     * Remember a sign-in that is about to start.
     */
    public function put(): void
    {
        $all = $this->live();
        $all[] = [
            'id' => bin2hex(random_bytes(16)),
            'expires' => $this->clock->now()->getTimestamp() + self::TTL_SECONDS,
        ];

        // Keep the newest entries only.
        if (count($all) > self::MAX_PENDING) {
            $all = array_slice($all, -self::MAX_PENDING, null, true);
        }

        $this->session->put(self::SESSION_KEY, $all);
    }

    /**
     * Remove and return the oldest live marker, or null when there is none
     * (no sign-in was started in this browser, or it took too long).
     *
     * @return Marker|null
     */
    public function take(): ?array
    {
        $all = $this->live();
        $marker = array_shift($all);

        if ($all === []) {
            $this->session->forget(self::SESSION_KEY);
        } else {
            $this->session->put(self::SESSION_KEY, $all);
        }

        return $marker;
    }

    /**
     * The stored entries that are well formed and not expired, in the order
     * stored.
     *
     * @return list<Marker>
     */
    private function live(): array
    {
        $stored = $this->session->get(self::SESSION_KEY);

        if (! is_array($stored)) {
            return [];
        }

        $now = $this->clock->now()->getTimestamp();
        $live = [];

        foreach ($stored as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $entry['id'] ?? null;
            $expires = $entry['expires'] ?? null;

            if (! is_string($id) || ! is_int($expires) || $expires <= $now) {
                continue;
            }

            $live[] = ['id' => $id, 'expires' => $expires];
        }

        return $live;
    }
}
