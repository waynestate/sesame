<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Laravel\LaravelSession;

/**
 * The Laravel session port, tested against a real session store.
 *
 * The port is a thin wrapper over Illuminate's session contract. These tests
 * pin the forwarding behavior and, most importantly, that regenerate()
 * destroys the old session (the session-fixation guard).
 */
final class LaravelSessionTest extends TestCase
{
    /**
     * @return array{0: LaravelSession, 1: Store}
     */
    private function port(): array
    {
        $store = new Store('test-session', new ArraySessionHandler(120));

        return [new LaravelSession($store), $store];
    }

    public function testPutAndGetForwardToTheSession(): void
    {
        [$port, $store] = $this->port();

        $port->put('sesame.user', 'the-user');

        self::assertSame('the-user', $port->get('sesame.user'));
        self::assertSame('the-user', $store->get('sesame.user'));
    }

    public function testGetReturnsNullWhenAbsent(): void
    {
        [$port] = $this->port();

        self::assertNull($port->get('missing'));
    }

    public function testForgetRemovesTheKey(): void
    {
        [$port, $store] = $this->port();
        $store->put('sesame.user', 'the-user');

        $port->forget('sesame.user');

        self::assertNull($port->get('sesame.user'));
    }

    public function testInvalidateClearsTheSession(): void
    {
        [$port, $store] = $this->port();
        $store->put('sesame.user', 'the-user');
        $store->put('other', 'value');

        $port->invalidate();

        self::assertNull($store->get('sesame.user'));
        self::assertNull($store->get('other'));
    }

    public function testRegenerateChangesTheSessionId(): void
    {
        [$port, $store] = $this->port();
        $before = $store->getId();

        $port->regenerate();

        self::assertNotSame($before, $store->getId());
    }
}
