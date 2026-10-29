<?php

declare(strict_types=1);

namespace Waynestate\Playbook;

/**
 * The test double for waynestate/playbook, loaded by the dev autoloader.
 *
 * It holds the same contract as the real class: get() answers from a static
 * array the tests set, and a missing or empty value is null. The real
 * package is private and never installed here, so the tests define the
 * class themselves.
 */
final class Playbook
{
    /**
     * @var array<string, string>
     */
    private static array $values = [];

    /**
     * @param array<string, string> $values
     */
    public static function setValues(array $values): void
    {
        self::$values = $values;
    }

    public static function reset(): void
    {
        self::$values = [];
    }

    public static function get(string $key): ?string
    {
        $value = self::$values[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
