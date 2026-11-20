<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Cake;

use Cake\Http\Session;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Cake\CakeSession;

/**
 * The CakePHP session port, tested against a real Cake session.
 *
 * The port is a thin wrapper over Cake's Session. These tests pin the forwarding
 * behavior and that dotted keys nest under the top-level `sesame` key. In CLI
 * (PHPUnit) Cake's renew() does nothing, so regenerate() is pinned with a mock
 * instead of an ID change.
 */
final class CakeSessionTest extends TestCase
{
    /**
     * @return array{0: CakeSession, 1: Session}
     */
    private function port(): array
    {
        $session = new Session();

        return [new CakeSession($session), $session];
    }

    public function testPutAndGetForwardToTheSession(): void
    {
        [$port, $session] = $this->port();

        $port->put('sesame.user', 'the-user');

        self::assertSame('the-user', $port->get('sesame.user'));
        self::assertSame(['user' => 'the-user'], $session->read('sesame'));
    }

    public function testGetReturnsNullWhenAbsent(): void
    {
        [$port] = $this->port();

        self::assertNull($port->get('missing'));
    }

    public function testForgetRemovesTheKey(): void
    {
        [$port, $session] = $this->port();
        $session->write('sesame.user', 'the-user');

        $port->forget('sesame.user');

        self::assertNull($port->get('sesame.user'));
    }

    public function testInvalidateClearsTheSession(): void
    {
        [$port, $session] = $this->port();
        $session->write('sesame.user', 'the-user');
        $session->write('other', 'value');

        $port->invalidate();

        self::assertNull($session->read('sesame.user'));
        self::assertNull($session->read('other'));
    }

    public function testRegenerateKeepsTheData(): void
    {
        [$port] = $this->port();
        $port->put('sesame.user', 'the-user');

        $port->regenerate();

        self::assertSame('the-user', $port->get('sesame.user'));
    }

    public function testRegenerateForwardsToRenew(): void
    {
        $session = $this->createMock(Session::class);
        $session->expects(self::once())->method('renew');
        $port = new CakeSession($session);

        $port->regenerate();
    }
}
