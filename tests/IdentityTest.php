<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Identity;

final class IdentityTest extends TestCase
{
    public function testRejectsAnEmptyUsername(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Identity('', [], 'fake', new DateTimeImmutable());
    }

    public function testAttributeReturnsTheFirstValue(): void
    {
        $identity = new Identity(
            'jdoe',
            ['mail' => ['jdoe@wayne.edu', 'john.doe@wayne.edu']],
            'fake',
            new DateTimeImmutable(),
        );

        self::assertSame('jdoe@wayne.edu', $identity->attribute('mail'));
    }

    public function testAttributeReturnsNullWhenTheAttributeIsAbsent(): void
    {
        $identity = new Identity('jdoe', [], 'fake', new DateTimeImmutable());

        self::assertNull($identity->attribute('mail'));
    }

    public function testAttributeReturnsNullWhenTheAttributeHasNoValues(): void
    {
        $identity = new Identity('jdoe', ['mail' => []], 'fake', new DateTimeImmutable());

        self::assertNull($identity->attribute('mail'));
    }

    public function testAuthTimeAndAmrDefaultToNullAndEmpty(): void
    {
        $identity = new Identity('jdoe', [], 'gateway', new DateTimeImmutable());

        self::assertNull($identity->authTime);
        self::assertSame([], $identity->amr);
    }

    public function testAuthTimeAndAmrAreStored(): void
    {
        $authenticatedAt = new DateTimeImmutable('2026-10-27T12:00:00+00:00');
        $authTime = new DateTimeImmutable('2026-10-27T11:59:00+00:00');
        $amr = ['mfa', 'password'];

        $identity = new Identity('jdoe', [], 'gateway', $authenticatedAt, $authTime, $amr);

        self::assertSame($authTime, $identity->authTime);
        self::assertSame($amr, $identity->amr);
    }

    public function testAuthContextsDefaultToEmpty(): void
    {
        $identity = new Identity('jdoe', [], 'gateway', new DateTimeImmutable());

        self::assertSame([], $identity->authContexts());
        self::assertFalse($identity->hasAuthContext('c1'));
    }

    public function testAuthContextsAreStored(): void
    {
        $identity = new Identity(
            'jdoe',
            [],
            'gateway',
            new DateTimeImmutable(),
            null,
            ['mfa'],
            ['c1', 'c10'],
        );

        self::assertSame(['c1', 'c10'], $identity->authContexts());
    }

    public function testHasAuthContextIsMembershipNotEquality(): void
    {
        // An identity that verified c2 and c10 has c2, has c10, and does not
        // have c1: the check is a membership test, never an equality on the
        // whole list (and never a prefix match).
        $identity = new Identity(
            'jdoe',
            [],
            'gateway',
            new DateTimeImmutable(),
            null,
            [],
            ['c2', 'c10'],
        );

        self::assertTrue($identity->hasAuthContext('c2'));
        self::assertTrue($identity->hasAuthContext('c10'));
        self::assertFalse($identity->hasAuthContext('c1'));
        self::assertFalse($identity->hasAuthContext('c3'));
    }

    public function testReadsIsFromNewLoginAndTheLongTermTokenFlag(): void
    {
        $now = new DateTimeImmutable();

        // Present true and false, in any case.
        $identity = new Identity(
            'jdoe',
            [
                'isFromNewLogin' => ['true'],
                'longTermAuthenticationRequestTokenUsed' => ['False'],
            ],
            'cas',
            $now,
        );

        self::assertTrue($identity->isFromNewLogin());
        self::assertFalse($identity->usedLongTermToken());

        // Present false for both.
        $identity = new Identity(
            'jdoe',
            [
                'isFromNewLogin' => ['FALSE'],
                'longTermAuthenticationRequestTokenUsed' => ['false'],
            ],
            'cas',
            $now,
        );

        self::assertFalse($identity->isFromNewLogin());
        self::assertFalse($identity->usedLongTermToken());

        // Absent: a driver that does not release the attributes answers null.
        $identity = new Identity('jdoe', [], 'fake', $now);

        self::assertNull($identity->isFromNewLogin());
        self::assertNull($identity->usedLongTermToken());

        // A junk value is not a boolean: null, never a guess.
        $identity = new Identity(
            'jdoe',
            [
                'isFromNewLogin' => ['yes'],
                'longTermAuthenticationRequestTokenUsed' => ['1'],
            ],
            'cas',
            $now,
        );

        self::assertNull($identity->isFromNewLogin());
        self::assertNull($identity->usedLongTermToken());
    }

    private function full(): Identity
    {
        return new Identity(
            'jdoe',
            ['cn' => ['John Doe'], 'mail' => []],
            'oidc',
            new DateTimeImmutable('2026-10-27T07:59:30-04:00'),
            new DateTimeImmutable('2026-10-27T11:58:00+00:00'),
            ['pwd', 'mfa'],
            ['c1', 'c10'],
        );
    }

    public function testToArrayIsPlainDataWithAtomTimes(): void
    {
        self::assertSame([
            'v' => 1,
            'username' => 'jdoe',
            'attributes' => ['cn' => ['John Doe'], 'mail' => []],
            'driver' => 'oidc',
            'authenticatedAt' => '2026-10-27T07:59:30-04:00',
            'authTime' => '2026-10-27T11:58:00+00:00',
            'amr' => ['pwd', 'mfa'],
            'authContexts' => ['c1', 'c10'],
        ], $this->full()->toArray());
    }

    public function testFromArrayRebuildsTheIdentityThroughJson(): void
    {
        $identity = $this->full();
        $data = json_decode(json_encode($identity->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        $back = Identity::fromArray($data);

        self::assertSame($identity->username, $back->username);
        self::assertSame($identity->attributes, $back->attributes);
        self::assertSame($identity->driver, $back->driver);
        self::assertSame($identity->authenticatedAt->getTimestamp(), $back->authenticatedAt->getTimestamp());
        self::assertSame('-04:00', $back->authenticatedAt->format('P'), 'The offset survives.');
        self::assertSame($identity->authTime?->getTimestamp(), $back->authTime?->getTimestamp());
        self::assertSame($identity->amr, $back->amr);
        self::assertSame($identity->authContexts(), $back->authContexts());
        self::assertSame($identity->toArray(), $back->toArray());
    }

    public function testFromArrayTakesANullAuthTimeAndEmptyLists(): void
    {
        $identity = new Identity('jdoe', [], 'cas', new DateTimeImmutable('2026-10-27T12:00:00+00:00'));

        $back = Identity::fromArray($identity->toArray());

        self::assertNull($back->authTime);
        self::assertSame([], $back->amr);
        self::assertSame([], $back->authContexts());
    }

    public function testFromArrayTakesTheAppsOwnDriverOnlyWhenNamed(): void
    {
        $data = ['driver' => 'campus-sso'] + $this->full()->toArray();

        self::assertSame('campus-sso', Identity::fromArray($data, ['campus-sso'])->driver);

        $this->expectException(InvalidArgumentException::class);
        Identity::fromArray($data);
    }

    /**
     * @return iterable<string, array{Closure(array<string, mixed>): array<mixed>}>
     */
    public static function badShapes(): iterable
    {
        yield 'missing field' => [static fn(array $d): array => array_diff_key($d, ['authTime' => true])];
        yield 'unexpected field' => [static fn(array $d): array => $d + ['admin' => true]];
        yield 'unknown version' => [static fn(array $d): array => ['v' => 2] + $d];
        yield 'version as a string' => [static fn(array $d): array => ['v' => '1'] + $d];
        yield 'empty username' => [static fn(array $d): array => ['username' => ''] + $d];
        yield 'username not a string' => [static fn(array $d): array => ['username' => 42] + $d];
        yield 'unknown driver' => [static fn(array $d): array => ['driver' => 'evil'] + $d];
        yield 'driver not a string' => [static fn(array $d): array => ['driver' => ['cas']] + $d];
        yield 'time as a number' => [static fn(array $d): array => ['authenticatedAt' => 1793000000] + $d];
        yield 'time not ATOM' => [static fn(array $d): array => ['authenticatedAt' => '2026-10-27 12:00:00'] + $d];
        yield 'time overflowing' => [static fn(array $d): array => ['authenticatedAt' => '2026-02-31T12:00:00+00:00'] + $d];
        yield 'time as the old object shape' => [static fn(array $d): array => ['authenticatedAt' => ['date' => '2026-10-27 12:00:00.000000', 'timezone_type' => 1, 'timezone' => '+00:00']] + $d];
        yield 'authTime not ATOM' => [static fn(array $d): array => ['authTime' => 'yesterday'] + $d];
        yield 'amr not a list' => [static fn(array $d): array => ['amr' => 'mfa'] + $d];
        yield 'amr with a number' => [static fn(array $d): array => ['amr' => ['mfa', 1]] + $d];
        yield 'amr keyed' => [static fn(array $d): array => ['amr' => ['a' => 'mfa']] + $d];
        yield 'contexts with a null' => [static fn(array $d): array => ['authContexts' => [null]] + $d];
        yield 'attributes not a map' => [static fn(array $d): array => ['attributes' => 'cn'] + $d];
        yield 'attribute value not a list' => [static fn(array $d): array => ['attributes' => ['cn' => 'John']] + $d];
        yield 'attribute name not a string' => [static fn(array $d): array => ['attributes' => [['John']]] + $d];
    }

    /**
     * @param Closure(array<string, mixed>): array<mixed> $change
     */
    #[DataProvider('badShapes')]
    public function testFromArrayRefusesEveryOtherShape(Closure $change): void
    {
        $data = $change($this->full()->toArray());

        $this->expectException(InvalidArgumentException::class);
        Identity::fromArray($data);
    }

    public function testTheRefusalNeverEchoesTheValue(): void
    {
        try {
            Identity::fromArray(['driver' => 'evil<script>'] + $this->full()->toArray());
            self::fail('An unknown driver was accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('evil', $e->getMessage());
        }
    }
}
