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
     * The version of the array form toArray() writes and fromArray() reads.
     * A stored array with any other version is refused, so a later change
     * of shape can never be half read.
     */
    public const ARRAY_VERSION = 1;

    /**
     * The driver names SESAME ships. fromArray() accepts these and, through
     * its second argument, the app's own configured driver, and nothing
     * else.
     *
     * @var list<string>
     */
    public const DRIVERS = ['fake', 'cas', 'gateway', 'oauth2', 'oidc', 'saml'];

    /**
     * The identity as plain data, for a session that may be stored as JSON
     * (Laravel's `'serialization' => 'json'`), where an object would come
     * back as an array and fail every `instanceof` check.
     *
     * The times are DATE_ATOM strings with their offset, so they keep their
     * moment through any serializer; fromArray() reads exactly this shape.
     *
     * @return array{v: int, username: string, attributes: array<string, list<string>>, driver: string, authenticatedAt: string, authTime: string|null, amr: list<string>, authContexts: list<string>}
     */
    public function toArray(): array
    {
        return [
            'v' => self::ARRAY_VERSION,
            'username' => $this->username,
            'attributes' => $this->attributes,
            'driver' => $this->driver,
            'authenticatedAt' => $this->authenticatedAt->format(DATE_ATOM),
            'authTime' => $this->authTime?->format(DATE_ATOM),
            'amr' => $this->amr,
            'authContexts' => $this->authContexts,
        ];
    }

    /**
     * Rebuild an identity from toArray()'s shape, strictly.
     *
     * Every key must be there and nothing else may be: the version, a
     * non-empty username, attributes as a map of names to lists of strings,
     * a known driver name (one of DRIVERS or $allowedDrivers), the times as
     * DATE_ATOM strings (authTime may be null), and amr and authContexts as
     * lists of strings. Anything else throws InvalidArgumentException, and
     * nothing else is ever thrown, so a caller can treat the one exception
     * as "this is not an identity" and sign the session out. The message
     * names the field only, never its value.
     *
     * @param array<mixed> $data
     * @param list<string> $allowedDrivers Driver names to accept beyond DRIVERS (the app's own driver).
     *
     * @throws InvalidArgumentException When the array is not exactly toArray()'s shape.
     */
    public static function fromArray(array $data, array $allowedDrivers = []): self
    {
        $keys = ['v', 'username', 'attributes', 'driver', 'authenticatedAt', 'authTime', 'amr', 'authContexts'];
        $given = array_keys($data);
        sort($given);
        $expected = $keys;
        sort($expected);

        if ($given !== $expected) {
            throw new InvalidArgumentException('A stored identity has missing or unexpected fields.');
        }

        if ($data['v'] !== self::ARRAY_VERSION) {
            throw new InvalidArgumentException('A stored identity has an unknown version.');
        }

        $username = $data['username'];

        if (! is_string($username) || $username === '') {
            throw new InvalidArgumentException('A stored identity needs a non-empty username.');
        }

        $driver = $data['driver'];

        if (! is_string($driver) || ! in_array($driver, [...self::DRIVERS, ...$allowedDrivers], true)) {
            throw new InvalidArgumentException('A stored identity names an unknown driver.');
        }

        $authenticatedAt = self::atom($data['authenticatedAt'], 'authenticatedAt');
        $authTime = $data['authTime'] === null ? null : self::atom($data['authTime'], 'authTime');

        return new self(
            $username,
            self::attributeMap($data['attributes']),
            $driver,
            $authenticatedAt,
            $authTime,
            self::stringList($data['amr'], 'amr'),
            self::stringList($data['authContexts'], 'authContexts'),
        );
    }

    /**
     * A DATE_ATOM string as a time, or InvalidArgumentException. The string
     * must format back to itself, so an overflowing date ("2026-02-31") or
     * any other near miss is refused rather than quietly moved.
     */
    private static function atom(mixed $value, string $field): DateTimeImmutable
    {
        if (is_string($value)) {
            $time = DateTimeImmutable::createFromFormat('!' . DATE_ATOM, $value);

            if ($time !== false && $time->format(DATE_ATOM) === $value) {
                return $time;
            }
        }

        throw new InvalidArgumentException(sprintf('A stored identity needs %s as a DATE_ATOM string.', $field));
    }

    /**
     * A list of strings, or InvalidArgumentException.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('A stored identity needs %s as a list of strings.', $field));
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException(sprintf('A stored identity needs %s as a list of strings.', $field));
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * A map of attribute names to lists of strings, or
     * InvalidArgumentException.
     *
     * @return array<string, list<string>>
     */
    private static function attributeMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('A stored identity needs attributes as a map of lists of strings.');
        }

        $map = [];

        foreach ($value as $name => $values) {
            if (! is_string($name)) {
                throw new InvalidArgumentException('A stored identity needs attributes as a map of lists of strings.');
            }

            $map[$name] = self::stringList($values, 'attributes');
        }

        return $map;
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
     * Whether the identity provider says this sign-in was a fresh, direct
     * login, or null when it did not say so.
     *
     * Read from the CAS isFromNewLogin attribute: true for the string
     * "true" (any case), false for "false", and null when the attribute is
     * absent or carries any other value. Drivers that do not release the
     * attribute answer null; the identity never invents one.
     */
    public function isFromNewLogin(): ?bool
    {
        return $this->booleanAttribute('isFromNewLogin');
    }

    /**
     * Whether the identity provider says the sign-in used a long-term
     * authentication request token, or null when it did not say so.
     *
     * Read from the CAS longTermAuthenticationRequestTokenUsed attribute:
     * true for the string "true" (any case), false for "false", and null
     * when the attribute is absent or carries any other value.
     */
    public function usedLongTermToken(): ?bool
    {
        return $this->booleanAttribute('longTermAuthenticationRequestTokenUsed');
    }

    /**
     * A boolean attribute: true for the string "true" (any case), false for
     * "false", and null when the attribute is absent or carries any other
     * value.
     */
    private function booleanAttribute(string $name): ?bool
    {
        $value = $this->attribute($name);

        if ($value === null) {
            return null;
        }

        $lower = strtolower($value);

        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        return null;
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
