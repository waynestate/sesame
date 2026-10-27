<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Waynestate\Sesame\Laravel\SesameServiceProvider;
use Waynestate\Sesame\Tests\Laravel\Support\TestUserResolver;

/**
 * The base case for the Laravel adapter tests.
 *
 * It boots a Testbench application with the SESAME service provider and a
 * fake driver, so the tests exercise the real provider, routes and
 * middleware without a CAS server.
 */
abstract class LaravelTestCase extends TestCase
{
    /**
     * The package's service providers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SesameServiceProvider::class];
    }

    /**
     * Define the environment for the tests.
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('0', 32)));

        $config->set('sesame.driver', 'fake');
        $config->set('sesame.fake.username', 'jdoe');
        $config->set('sesame.user_resolver', TestUserResolver::class);
    }
}
