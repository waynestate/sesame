<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Illuminate\Routing\Router;
use Waynestate\Sesame\Sesame;

/**
 * Booster's replay follows the Laravel half's sign-in (strangler section 9.5,
 * `compare.silent_sign_in`): a guest on a moved page goes to /_laravel/login,
 * and under the fake driver that route signs in on the spot and sends the
 * browser straight back to the page it first asked for, query string and
 * all. The recording then holds the page, not the sign-in hop.
 */
final class StranglerSilentSignInTest extends LaravelTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.url', 'https://app.test');
        $app['config']->set('sesame.route_prefix', '_laravel');
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/reports/{id}', fn(string $id): string => 'report ' . $id)->middleware(['web', 'auth.sesame']);
    }

    public function testAGuestOnAMovedPageComesStraightBackSignedIn(): void
    {
        $this->get('https://app.test/reports/7?sort=desc')
            ->assertRedirect('https://app.test/_laravel/login');

        $this->get('https://app.test/_laravel/login')
            ->assertRedirect('https://app.test/reports/7?sort=desc');

        $this->get('https://app.test/reports/7?sort=desc')
            ->assertOk()
            ->assertSee('report 7');

        $app = $this->app;
        self::assertNotNull($app);
        self::assertNotNull($app->make(Sesame::class)->user());
    }

    public function testWithNoPageSavedTheSignInLandsOnTheHomePage(): void
    {
        $this->get('https://app.test/_laravel/login')->assertRedirect('https://app.test');
    }
}
