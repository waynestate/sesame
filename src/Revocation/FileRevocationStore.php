<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Revocation;

use Psr\Clock\ClockInterface;
use Waynestate\Sesame\Clock\SystemClock;
use Waynestate\Sesame\RevocationStore;
use Waynestate\Sesame\Support\Setting;

/**
 * The file revocation store: one directory both apps can read and write.
 *
 * Booster's Laravel strangler runs two frameworks out of one repository
 * (ADR-0024), the old app under `legacy/` and Laravel at the root, each with
 * its own session. They share no cache, but they share a disk, so each half
 * points this store at the same directory and a logout on either half ends
 * the other half's session too.
 *
 * Each cutoff is a file named after the SHA-256 of the username, so no
 * plain username touches the disk, holding two integers: the cutoff and
 * the time it expires (the cutoff's write time plus the TTL), so a
 * forgotten cutoff dies on its own. A write goes to a temporary file in the
 * same directory and is renamed into place, so a reader never sees half a
 * file. The store never throws: a missing or unreadable file counts as no
 * cutoff, and a failed write is dropped, the PSR-16 store's rule. So a
 * directory the web server cannot write is the one thing to check at
 * deploy time: there, a logout ends only the half it was made on.
 */
final class FileRevocationStore implements RevocationStore
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly string $directory,
        private readonly int $ttlSeconds = 2_592_000,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * The store `SESAME_REVOCATION_PATH` names, or null when it is not set.
     *
     * For raw-PHP, CakePHP and Slim apps, which build their own Sesame: the
     * same two environment keys the Laravel adapter reads, so one `.env`
     * line points both halves of a strangler at one directory.
     * `SESAME_REVOCATION_TTL` (seconds, default 30 days) sets the TTL; a
     * value that is not a positive whole number keeps the default.
     */
    public static function fromEnvironment(?ClockInterface $clock = null): ?self
    {
        $path = Setting::env('SESAME_REVOCATION_PATH');

        if ($path === null || trim($path) === '') {
            return null;
        }

        $ttl = Setting::env('SESAME_REVOCATION_TTL');
        $ttlSeconds = $ttl !== null && ctype_digit(trim($ttl)) && (int) trim($ttl) > 0 ? (int) trim($ttl) : 2_592_000;

        return new self(trim($path), $ttlSeconds, $clock);
    }

    public function revokeBefore(string $username, int $at): void
    {
        $current = $this->revokedBefore($username);

        if ($current !== null && $current >= $at) {
            return;
        }

        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0o770, true) && ! is_dir($this->directory)) {
            return;
        }

        $expires = $this->clock->now()->getTimestamp() + $this->ttlSeconds;
        $file = $this->file($username);
        $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $at . ' ' . $expires . "\n") === false) {
            return;
        }

        if (! @rename($temp, $file)) {
            @unlink($temp);
        }
    }

    public function revokedBefore(string $username): ?int
    {
        $file = $this->file($username);

        if (! is_file($file)) {
            return null;
        }

        $content = @file_get_contents($file);

        if (! is_string($content) || preg_match('/^(\d+) (\d+)\n?$/', $content, $m) !== 1) {
            return null;
        }

        if ((int) $m[2] < $this->clock->now()->getTimestamp()) {
            // Past its TTL: the cutoff is gone, and the file goes with it.
            @unlink($file);

            return null;
        }

        return (int) $m[1];
    }

    private function file(string $username): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . 'sesame.revoked.' . hash('sha256', $username);
    }
}
