<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * The status route: the "who am I" answer for single-page apps.
 *
 * A GET /sesame/status answers 200 with the session facts when someone is
 * signed in and 401 with {"signedIn":false} when no one is. The answer is
 * never cacheable, the app can add its own user fields by binding a closure
 * as sesame.describe_user, and the route stays out of the router entirely
 * when sesame.routes is false.
 */
final class StatusRouteTest extends LaravelTestCase
{
    public function testStatusIsSignedOutWith401BeforeLogin(): void
    {
        $response = $this->get('/sesame/status');

        $response->assertStatus(401);
        $response->assertExactJson(['signedIn' => false]);
    }

    public function testStatusListsTheSessionFactsAfterLogin(): void
    {
        $this->get('/login')->assertRedirect('/');

        $response = $this->get('/sesame/status');

        $response->assertStatus(200);

        $answer = $response->json();

        self::assertTrue($answer['signedIn']);
        self::assertSame('jdoe', $answer['username']);
        self::assertSame('fake', $answer['driver']);
        self::assertIsString($answer['authenticatedAt']);
        self::assertIsArray($answer['amr']);
        self::assertIsString($answer['idleExpiresAt']);
        self::assertIsString($answer['absoluteExpiresAt']);
        self::assertIsString($answer['logoutToken']);
        self::assertNotSame('', $answer['logoutToken']);
        // The raw IdP attributes never appear in the answer.
        self::assertArrayNotHasKey('attributes', $answer);
    }

    public function testStatusAppliesTheAppsDescribeUserBinding(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $app->instance('sesame.describe_user', fn(mixed $user): array => [
            'name' => 'John Doe',
            'admin' => true,
        ]);

        $this->get('/login')->assertRedirect('/');

        $response = $this->get('/sesame/status');

        $response->assertStatus(200);
        self::assertSame(['name' => 'John Doe', 'admin' => true], $response->json('user'));
    }

    public function testStatusIsNotCacheable(): void
    {
        $signedOut = $this->get('/sesame/status');

        $signedOut->assertStatus(401);
        self::assertStringContainsString('no-store', (string) $signedOut->headers->get('Cache-Control'));
        self::assertSame('nosniff', $signedOut->headers->get('X-Content-Type-Options'));

        $this->get('/login')->assertRedirect('/');

        $signedIn = $this->get('/sesame/status');

        $signedIn->assertStatus(200);
        self::assertStringContainsString('no-store', (string) $signedIn->headers->get('Cache-Control'));
        self::assertSame('nosniff', $signedIn->headers->get('X-Content-Type-Options'));
    }

    #[DefineEnvironment('defineRoutesDisabledEnvironment')]
    public function testStatusRouteIsAbsentWhenRoutesAreOff(): void
    {
        $this->get('/sesame/status')->assertStatus(404);
    }

    /**
     * Disable SESAME's routes for the disabled-routes test.
     *
     * Public because the DefineEnvironment attribute calls it through
     * testbench's own scope, which cannot reach a private method.
     */
    public function defineRoutesDisabledEnvironment(Application $app): void
    {
        $app['config']->set('sesame.routes', false);
    }
}
