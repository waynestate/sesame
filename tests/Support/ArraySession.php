<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Waynestate\Sesame\SessionPort;

/**
 * The legacy half's session: a plain array, as $_SESSION would be.
 */
final class ArraySession implements SessionPort
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function regenerate(): void {}

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    public function invalidate(): void
    {
        $this->data = [];
    }
}
