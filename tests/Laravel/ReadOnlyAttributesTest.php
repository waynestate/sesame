<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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

        self::assertArrayNotHasKey('cn', $dirty);
        self::assertArrayHasKey('username', $dirty);
    }

    public function testNewFromBuilderRefusesWritesToReadOnlyAttributes(): void
    {
        $hydrated = (new ReadOnlyUser())->newFromBuilder(['cn' => 'John Doe', 'username' => 'jdoe']);
        $hydrated->setAttribute('cn', 'Changed');

        self::assertSame([], $hydrated->getDirty());
    }

    public function testTheWayneCasSignatureWorksOnTheModel(): void
    {
        // The one change a wayne-cas model needs: the method goes from
        // protected to public, keeping its parameter. The interface and the
        // trait agree on this shape, so the model satisfies both, and the
        // parameter is optional.
        $user = new ReadOnlyUser();

        self::assertSame(['cn' => null], $user->getReadOnlyAttributes());
        self::assertSame(['cn' => 'John Doe'], $user->getReadOnlyAttributes(['cn' => 'John Doe']));
    }

    public function testTheInterfaceAndTheTraitShareOneSignature(): void
    {
        $method = new ReflectionMethod(ReadOnlyUser::class, 'getReadOnlyAttributes');

        self::assertTrue($method->isPublic());
        self::assertSame('array', (string) $method->getReturnType());
        self::assertCount(1, $method->getParameters());
        self::assertSame('persistedAttributes', $method->getParameters()[0]->getName());
        self::assertTrue($method->getParameters()[0]->isOptional());
    }
}
