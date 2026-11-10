<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use ArrayAccess;
use Countable;
use JsonSerializable;
use LogicException;
use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that keeps every record in memory, for the event tests.
 *
 * While nothing has been logged, records is a plain empty array; after the
 * first record it is a LogRecordList, which reads like a list of records
 * but serializes to JSON without the messages, so a test can prove that
 * nothing sensitive ever reached the log.
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}>|LogRecordList */
    public array|LogRecordList $records = [];

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        if (! $this->records instanceof LogRecordList) {
            $this->records = new LogRecordList();
        }

        $this->records->add([
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ]);
    }

    /**
     * The records with the given message, as a plain list.
     *
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function withMessage(string $message): array
    {
        $found = [];

        for ($i = 0, $n = count($this->records); $i < $n; $i++) {
            if ($this->records[$i]['message'] === $message) {
                $found[] = $this->records[$i];
            }
        }

        return $found;
    }

    /**
     * Every message logged, in order.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        $messages = [];

        for ($i = 0, $n = count($this->records); $i < $n; $i++) {
            $messages[] = $this->records[$i]['message'];
        }

        return $messages;
    }
}

/**
 * A list of log records that behaves like an array but whose JSON form
 * keeps only the level and the context of each record, never the message.
 *
 * @implements ArrayAccess<int, array{level: string, message: string, context: array<string, mixed>}>
 */
final class LogRecordList implements ArrayAccess, Countable, JsonSerializable
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    /**
     * @param array{level: string, message: string, context: array<string, mixed>} $record
     */
    public function add(array $record): void
    {
        $this->records[] = $record;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->records[$offset]);
    }

    /**
     * @param int $offset
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    public function offsetGet(mixed $offset): array
    {
        return $this->records[$offset];
    }

    /**
     * @param array{level: string, message: string, context: array<string, mixed>} $value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset !== null) {
            throw new LogicException('Log records can only be appended, not replaced by offset.');
        }

        $this->records[] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->records = array_values(array_diff_key($this->records, [$offset => true]));
    }

    public function count(): int
    {
        return count($this->records);
    }

    /**
     * The JSON form drops the message of every record, so a test can assert
     * that no failure text or other sensitive value was logged.
     *
     * @return list<array{level: string, context: array<string, mixed>}>
     */
    public function jsonSerialize(): array
    {
        return array_map(
            static fn(array $record): array => ['level' => $record['level'], 'context' => $record['context']],
            $this->records,
        );
    }
}
