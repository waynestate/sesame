<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

/**
 * The auth.sesame middleware.
 *
 * It lets an authenticated request through, sends a guest to the login route,
 * and answers a JSON guest with a 401.
 */
final class AuthenticateTest extends LaravelTestCase
{
    public function testItSendsAGuestToTheLoginRoute(): void
    {
        $this->app['router']->get('/protected', fn () => 'ok')->middleware(['web', 'auth.sesame']);

        $this->get('/protected')->assertRedirect(route('sesame.login'));
    }

    public function testItAnswersAJsonGuestWith401(): void
    {
        $this->app['router']->get('/protected', fn () => 'ok')->middleware(['web', 'auth.sesame']);

        $this->getJson('/protected')->assertStatus(401);
    }

    public function testItLetsAnAuthenticatedRequestThrough(): void
    {
        $this->app['router']->get('/protected', fn () => 'ok')->middleware(['web', 'auth.sesame']);

        $this->withSession([
            'sesame.user' => 'the-user',
            'sesame.signed_in_at' => time(),
            'sesame.last_seen' => time(),
        ])
            ->get('/protected')
            ->assertOk()
            ->assertSee('ok');
    }
}
