<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Waynestate\Sesame\SessionPort;

/**
 * An in-memory SessionPort that records every call, in order.
 */
final class RecordingSession implements SessionPort
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function __construct(
        private readonly EventLog $log,
    ) {
    }

    public function regenerate(): void
    {
        $this->log->record('regenerate');
    }

    public function get(string $key): mixed
    {
        $this->log->record('get:'.$key);

        return $this->store[$key] ?? null;
    }

    public function put(string $key, mixed $value): void
    {
        $this->log->record('put:'.$key);
        $this->store[$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->log->record('forget:'.$key);
        unset($this->store[$key]);
    }

    public function invalidate(): void
    {
        $this->log->record('invalidate');
        $this->store = [];
    }
}
