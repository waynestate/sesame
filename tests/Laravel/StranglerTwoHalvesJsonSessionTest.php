<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Illuminate\Session\Store;
use Waynestate\Sesame\Sesame;

/**
 * The strangler's two halves with the Laravel half on a JSON session
 * (`'serialization' => 'json'`, Sourdough's config/session.php): the case
 * Booster's CAKE lane found on 2026-11-20. Under 0.10.0 the identity came
 * back from the session as an array, so a legacy logout left the Laravel
 * half signed in and a Laravel logout wrote no cutoff. Every test of
 * StranglerTwoHalvesTest runs here again on JSON.
 *
 * A test request carries no session cookie and the test's session store
 * lives on in memory between requests, so without help the store would
 * never read the session back through its serializer. When every request
 * ends, the store is saved and read back from its handler, as the
 * browser's next request would read it: from then on the identity is
 * whatever JSON hands back.
 */
final class StranglerTwoHalvesJsonSessionTest extends StranglerTwoHalvesTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('session.serialization', 'json');

        $app->terminating(static function () use ($app): void {
            $store = $app['session.store'];

            if ($store instanceof Store) {
                $store->save();
                $store->start();
            }
        });
    }

    public function testTheSessionReallyGoesThroughJson(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        $this->get('https://app.test/_laravel/login?ticket=' . $this->cas->browse(
            (string) $this->get('https://app.test/_laravel/login')->headers->get('Location'),
            'https://app.test/_laravel/login',
        ));

        // A JSON session hands back only plain data: the identity is the
        // array login() wrote, never an object.
        self::assertIsArray($app['session.store']->get('sesame.identity'));
        self::assertSame('the-user', $app->make(Sesame::class)->user());
    }
}
