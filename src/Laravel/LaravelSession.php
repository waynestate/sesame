<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Laravel;

use Illuminate\Contracts\Session\Session;
use Waynestate\Sesame\SessionPort;

/**
 * The Laravel session, seen through the core's port.
 *
 * The app owns its session (ADR-0004); this class only forwards the five
 * calls the core makes, so the core never sees a Laravel type.
 */
final class LaravelSession implements SessionPort
{
    public function __construct(
        private readonly Session $session,
    ) {
    }

    public function regenerate(): void
    {
        $this->session->regenerate(true);
    }

    public function get(string $key): mixed
    {
        return $this->session->get($key);
    }

    public function put(string $key, mixed $value): void
    {
        $this->session->put($key, $value);
    }

    public function forget(string $key): void
    {
        $this->session->forget($key);
    }

    public function invalidate(): void
    {
        $this->session->invalidate();
    }
}
