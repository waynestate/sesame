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
     * @param  list<string>  $authContexts  The authentication contexts the identity provider verified for this sign-in, when a step-up was required.
     */
    public function __construct(
        public string $username,
        public array $attributes,
        public string $driver,
        public DateTimeImmutable $authenticatedAt,
        public ?DateTimeImmutable $authTime = null,
        public array $amr = [],
        private array $authContexts = [],
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

    /**
     * The authentication contexts verified for this sign-in, in the order
     * the driver reported them. Empty when no step-up was required.
     *
     * @return list<string>
     */
    public function authContexts(): array
    {
        return $this->authContexts;
    }

    /**
     * Whether this sign-in verified the given authentication context.
     *
     * A membership test on the list, never an equality on the whole list:
     * an identity that verified c2 and c10 has c2, has c10, and does not
     * have c1.
     */
    public function hasAuthContext(string $id): bool
    {
        return in_array($id, $this->authContexts, true);
    }
}
