<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Support;

use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Waynestate\Sesame\Laravel\LaravelSession;
use Waynestate\Sesame\SessionPort;

/**
 * A Laravel session store across requests: each request gets a fresh Store
 * on the same handler and session ID, so every value goes through the
 * store's serializer in between. With 'json' (Sourdough's
 * config/session.php) an object comes back as an array of its public
 * properties; with 'php' it comes back as the object.
 */
final class LaravelRoundTrip implements SessionRoundTrip
{
    private readonly ArraySessionHandler $handler;

    private ?Store $store = null;

    public function __construct(
        private readonly string $serialization = 'json',
    ) {
        $this->handler = new ArraySessionHandler(120);
    }

    public function next(): SessionPort
    {
        $id = null;

        if ($this->store !== null) {
            $this->store->save();
            $id = $this->store->getId();
        }

        $this->store = new Store('laravel_session', $this->handler, $id, $this->serialization);
        $this->store->start();

        return new LaravelSession($this->store);
    }
}
