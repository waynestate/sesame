<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
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

        $this->assertSame('jdoe@wayne.edu', $identity->attribute('mail'));
    }

    public function testAttributeReturnsNullWhenTheAttributeIsAbsent(): void
    {
        $identity = new Identity('jdoe', [], 'fake', new DateTimeImmutable());

        $this->assertNull($identity->attribute('mail'));
    }

    public function testAttributeReturnsNullWhenTheAttributeHasNoValues(): void
    {
        $identity = new Identity('jdoe', ['mail' => []], 'fake', new DateTimeImmutable());

        $this->assertNull($identity->attribute('mail'));
    }

    public function testAuthTimeAndAmrDefaultToNullAndEmpty(): void
    {
        $identity = new Identity('jdoe', [], 'gateway', new DateTimeImmutable());

        $this->assertNull($identity->authTime);
        $this->assertSame([], $identity->amr);
    }

    public function testAuthTimeAndAmrAreStored(): void
    {
        $authenticatedAt = new DateTimeImmutable('2026-10-27T12:00:00+00:00');
        $authTime = new DateTimeImmutable('2026-10-27T11:59:00+00:00');
        $amr = ['mfa', 'password'];

        $identity = new Identity('jdoe', [], 'gateway', $authenticatedAt, $authTime, $amr);

        $this->assertSame($authTime, $identity->authTime);
        $this->assertSame($amr, $identity->amr);
    }

    public function testAuthContextsDefaultToEmpty(): void
    {
        $identity = new Identity('jdoe', [], 'gateway', new DateTimeImmutable());

        $this->assertSame([], $identity->authContexts());
        $this->assertFalse($identity->hasAuthContext('c1'));
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

        $this->assertSame(['c1', 'c10'], $identity->authContexts());
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

        $this->assertTrue($identity->hasAuthContext('c2'));
        $this->assertTrue($identity->hasAuthContext('c10'));
        $this->assertFalse($identity->hasAuthContext('c1'));
        $this->assertFalse($identity->hasAuthContext('c3'));
    }
}
