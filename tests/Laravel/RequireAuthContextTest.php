<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use DateTimeImmutable;
use Waynestate\Sesame\Identity;

/**
 * The sesame.context middleware.
 *
 * It lets a request through when the signed-in identity has the required
 * authentication context, and otherwise stops it with a 403, or a JSON 403
 * for JSON requests. The context id comes from the route parameter
 * ("sesame.context:c1"); a route without one fails closed.
 */
final class RequireAuthContextTest extends LaravelTestCase
{
    public function testItLetsARequestThroughWhenTheIdentityHasTheContext(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);
        $app['router']->get('/protected', fn() => 'ok')->middleware(['web', 'sesame.context:c1']);

        $this->withSession($this->signedInWith(['c1']))
            ->get('/protected')
            ->assertOk()
            ->assertSee('ok');
    }

    public function testItAnswers403WhenTheIdentityLacksTheContext(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);
        $app['router']->get('/protected', fn() => 'ok')->middleware(['web', 'sesame.context:c1']);

        $this->withSession($this->signedInWith(['c2']))
            ->get('/protected')
            ->assertStatus(403);
    }

    public function testItAnswers403WhenTheSessionHasNoIdentity(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);
        $app['router']->get('/protected', fn() => 'ok')->middleware(['web', 'sesame.context:c1']);

        $this->withSession([
            'sesame.user' => 'the-user',
            'sesame.signed_in_at' => time(),
            'sesame.last_seen' => time(),
        ])
            ->get('/protected')
            ->assertStatus(403);
    }

    public function testItAnswers403WhenThereIsNoUser(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);
        $app['router']->get('/protected', fn() => 'ok')->middleware(['web', 'sesame.context:c1']);

        $this->get('/protected')->assertStatus(403);
    }

    public function testItAnswersAJson403WhenTheIdentityLacksTheContext(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);
        $app['router']->get('/protected', fn() => 'ok')->middleware(['web', 'sesame.context:c1']);

        $this->withSession($this->signedInWith(['c2']))
            ->getJson('/protected')
            ->assertStatus(403)
            ->assertJsonStructure(['message']);
    }

    public function testItAnswers403WhenTheRouteHasNoContextParameter(): void
    {
        // No context named, no context satisfied: the middleware fails
        // closed.
        $app = $this->app;
        $this->assertNotNull($app);
        $app['router']->get('/protected', fn() => 'ok')->middleware(['web', 'sesame.context']);

        $this->withSession($this->signedInWith(['c1']))
            ->get('/protected')
            ->assertStatus(403);
    }

    /**
     * A signed-in session whose identity has the given authentication
     * contexts.
     *
     * @param  list<string>  $contexts
     * @return array<string, mixed>
     */
    private function signedInWith(array $contexts): array
    {
        $now = new DateTimeImmutable();

        return [
            'sesame.user' => 'the-user',
            'sesame.signed_in_at' => time(),
            'sesame.last_seen' => time(),
            'sesame.identity' => new Identity(
                'jdoe',
                [],
                'gateway',
                $now,
                $now,
                ['mfa'],
                $contexts,
            ),
        ];
    }
}
