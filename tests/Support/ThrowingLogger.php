<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * A PSR-3 logger that throws on every call, for the test that a broken
 * logger must never change what the facade returns or throws.
 */
final class ThrowingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;

        throw new RuntimeException('This logger refuses to work.');
    }
}
