<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Illuminate\Routing\Route;

/**
 * Route caching: an app that uses SESAME must survive `php artisan
 * route:cache` (and therefore `optimize`, which every Citadel release
 * runs).
 *
 * A closure route serializes with everything it captures, and the
 * provider's old closures captured the container, so caching the routes
 * ran out of memory. The actions are methods on SesameController now, so
 * the cached file holds only class names and method names.
 */
final class RouteCacheTest extends LaravelTestCase
{
    public function testSesameRoutesCanBeCached(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $routes = $app['router']->getRoutes();

        // The routes the provider registers for the fake driver.
        self::assertTrue($routes->hasNamedRoute('sesame.login'));
        self::assertTrue($routes->hasNamedRoute('sesame.logout'));
        self::assertTrue($routes->hasNamedRoute('sesame.status'));

        // No SESAME route may carry a closure action: that is what made
        // route:cache blow up.
        /** @var Route $route */
        foreach ($routes->getRoutes() as $route) {
            $name = $route->getName();

            if (! is_string($name) || ! str_starts_with($name, 'sesame.')) {
                continue;
            }

            self::assertFalse(
                $route->getAction('uses') instanceof \Closure,
                sprintf('The %s route (%s) is still a closure.', $name, $route->uri()),
            );
        }

        $this->artisan('route:cache')->assertExitCode(0);
        $this->artisan('route:clear')->assertExitCode(0);
    }
}
