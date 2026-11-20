<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Cake;

use Cake\Http\Session;
use Waynestate\Sesame\SessionPort;

/**
 * The CakePHP session, seen through the core's port.
 *
 * The app owns its session, not the library; this class only forwards the five
 * calls the core makes, so the core never sees a Cake type. Dotted keys such as
 * `sesame.user` nest under the top-level `sesame` key in the session, which is
 * where the core already keeps them.
 */
final class CakeSession implements SessionPort
{
    public function __construct(
        private readonly Session $session,
    ) {}

    public function regenerate(): void
    {
        $this->session->renew();
    }

    public function get(string $key): mixed
    {
        return $this->session->read($key);
    }

    public function put(string $key, mixed $value): void
    {
        $this->session->write($key, $value);
    }

    public function forget(string $key): void
    {
        $this->session->delete($key);
    }

    public function invalidate(): void
    {
        $this->session->destroy();
    }
}
