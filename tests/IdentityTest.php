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
}
