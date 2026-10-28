<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

/**
 * The sesame.routes=false escape hatch.
 *
 * An app that defines its own login and logout routes sets sesame.routes to
 * false, and the provider must not register its own.
 */
final class RoutesDisabledTest extends LaravelTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sesame.routes', false);
    }

    public function testItDoesNotRegisterTheRoutes(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);
        $routes = $app['router']->getRoutes();

        $this->assertFalse($routes->hasNamedRoute('sesame.login'));
        $this->assertFalse($routes->hasNamedRoute('sesame.logout'));
    }
}
