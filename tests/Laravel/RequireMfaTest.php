<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use DateTimeImmutable;
use Waynestate\Sesame\Identity;

/**
 * The sesame.mfa middleware.
 *
 * It lets a request through when the signed-in user's MFA is still good, and
 * otherwise stops it: a 403 by default, a JSON 403 for JSON requests, and a
 * redirect to the login route when the app has chosen "reauth". The allowed
 * age comes from the route parameter when there is one, and from
 * sesame.mfa.max_age when there is not.
 */
final class RequireMfaTest extends LaravelTestCase
{
    public function testItLetsARequestThroughWhenMfaIsSatisfied(): void
    {
        $this->app['router']->get('/protected', fn () => 'ok')->middleware(['web', 'sesame.mfa']);

        $this->withSession($this->signedInWithMfa())
            ->get('/protected')
            ->assertOk()
            ->assertSee('ok');
    }

    public function testItAnswers403WhenTheSessionHasNoIdentity(): void
    {
        $this->app['router']->get('/protected', fn () => 'ok')->middleware(['web', 'sesame.mfa']);

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
        $this->app['router']->get('/protected', fn () => 'ok')->middleware(['web', 'sesame.mfa']);

        $this->get('/protected')->assertStatus(403);
    }

    public function testItAnswersAJson403WhenMfaIsNotSatisfied(): void
    {
        $this->app['router']->get('/protected', fn () => 'ok')->middleware(['web', 'sesame.mfa']);

        $this->getJson('/protected')
            ->assertStatus(403)
            ->assertJsonStructure(['message']);
    }

    public function testItRedirectsToLoginWhenOnFailureIsReauth(): void
    {
        $this->app['config']->set('sesame.mfa.on_failure', 'reauth');
        $this->app['router']->get('/protected', fn () => 'ok')->middleware(['web', 'sesame.mfa']);

        $this->get('/protected')->assertRedirect(route('sesame.login'));
    }

    public function testItUsesTheMiddlewareParameterForTheMaxAge(): void
    {
        // The user's last MFA sign-in was 30 seconds ago: inside a 120-second
        // max age, outside a 10-second one.
        $this->app['router']->get('/wide', fn () => 'ok')->middleware(['web', 'sesame.mfa:120']);
        $this->app['router']->get('/narrow', fn () => 'ok')->middleware(['web', 'sesame.mfa:10']);

        $session = $this->signedInWithMfa(new DateTimeImmutable('-30 seconds'));

        $this->withSession($session)->get('/wide')->assertOk();
        $this->withSession($session)->get('/narrow')->assertStatus(403);
    }

    /**
     * A signed-in session whose identity has MFA facts from $authTime ago.
     *
     * @return array<string, mixed>
     */
    private function signedInWithMfa(?DateTimeImmutable $authTime = null): array
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
                $authTime ?? $now,
                ['mfa'],
            ),
        ];
    }
}
