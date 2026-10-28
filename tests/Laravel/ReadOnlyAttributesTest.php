<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Tests\Laravel\Support\ReadOnlyUser;

/**
 * The ReadOnlyAttributes trait, the way a wayne-cas app model uses it.
 *
 * A read-only attribute is one the app must not persist: it is excluded from
 * getDirty() so changing it never triggers a database write. These tests pin
 * that refusal on a plain model, without a database.
 */
final class ReadOnlyAttributesTest extends TestCase
{
    public function testWritesToReadOnlyAttributesAreRefused(): void
    {
        $user = new ReadOnlyUser();
        $user->setReadOnlyAttributes(['cn']);
        $user->setRawAttributes(['cn' => 'John Doe', 'username' => 'jdoe'], false);

        $dirty = $user->getDirty();

        $this->assertArrayNotHasKey('cn', $dirty);
        $this->assertArrayHasKey('username', $dirty);
    }

    public function testNewFromBuilderRefusesWritesToReadOnlyAttributes(): void
    {
        $hydrated = (new ReadOnlyUser())->newFromBuilder(['cn' => 'John Doe', 'username' => 'jdoe']);
        $hydrated->setAttribute('cn', 'Changed');

        $this->assertSame([], $hydrated->getDirty());
    }
}
