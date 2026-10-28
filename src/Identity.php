<?php

declare(strict_types=1);

namespace Waynestate\Sesame;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A verified identity, as one driver returned it.
 *
 * The value is read-only: a driver builds it once, and the app and the
 * session only ever read it.
 */
final readonly class Identity
{
    /**
     * @param  array<string, list<string>>  $attributes  Attribute name to its values, in the order the driver returned them.
     * @param  list<string>  $amr  The authentication methods the identity provider verified for this sign-in.
     */
    public function __construct(
        public string $username,
        public array $attributes,
        public string $driver,
        public DateTimeImmutable $authenticatedAt,
        public ?DateTimeImmutable $authTime = null,
        public array $amr = [],
    ) {
        if ($username === '') {
            throw new InvalidArgumentException('An identity needs a non-empty username.');
        }
    }

    /**
     * The first value of one attribute, or null when the attribute is absent.
     */
    public function attribute(string $name): ?string
    {
        $values = $this->attributes[$name] ?? null;

        if ($values === null || $values === []) {
            return null;
        }

        return $values[0];
    }
}
