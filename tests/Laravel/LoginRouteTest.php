<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Waynestate\Sesame\Sesame;

/**
 * The login and logout routes, with the fake driver.
 *
 * The fake driver always authenticates, so a GET /login stores the resolved
 * user in the session and redirects home, and a POST /logout clears it.
 */
final class LoginRouteTest extends LaravelTestCase
{
    public function testLoginStoresTheUserAndRedirectsHome(): void
    {
        $this->get('/login')->assertRedirect('/');

        $this->assertSame('the-user', $this->app->make(Sesame::class)->user());
    }

    public function testLogoutClearsTheUserAndRedirectsHome(): void
    {
        $this->withSession(['sesame.user' => 'the-user'])
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertNull($this->app->make(Sesame::class)->user());
    }
}
