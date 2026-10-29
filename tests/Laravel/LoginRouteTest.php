<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Client\ClientInterface;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\FakeHttpClient;

/**
 * The login and logout routes.
 *
 * The fake driver always authenticates, so a GET /login stores the resolved
 * user in the session and redirects home, and a POST /logout clears it. The
 * cas login test drives the real cas driver with a fake HTTP client that
 * answers the ticket with a success.
 */
final class LoginRouteTest extends LaravelTestCase
{
    public function testLoginStoresTheUserAndRedirectsHome(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $this->get('/login')->assertRedirect('/');

        self::assertSame('the-user', $app->make(Sesame::class)->user());
    }

    public function testLoginDropsTheTicketFromTheRedirectAfterACasLogin(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $app['config']->set('sesame.driver', 'cas');
        $app['config']->set('sesame.cas.base_url', 'https://cas.test/cas');
        $app['config']->set('sesame.cas.service_url', 'https://app.test/login');
        $app->instance(ClientInterface::class, $this->casHttpClient());

        $response = $this->get('/login?ticket=ST-1');

        $response->assertRedirect('/');
        self::assertStringNotContainsString('ticket', (string) $response->headers->get('Location'));
    }

    /**
     * A PSR-18 client that answers the cas ticket validation with a success.
     */
    private function casHttpClient(): FakeHttpClient
    {
        $success = (new Response())->withStatus(200)->withBody(
            Stream::create((string) file_get_contents(__DIR__ . '/../fixtures/cas/success.xml')),
        );

        return new FakeHttpClient([$success]);
    }

    public function testLogoutClearsTheUserAndRedirectsHome(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $this->withSession(['sesame.user' => 'the-user'])
            ->post('/logout')
            ->assertRedirect('/');

        self::assertNull($app->make(Sesame::class)->user());
    }

    public function testLogoutIsAPostRouteInTheWebGroupSoCsrfApplies(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $route = $app['router']->getRoutes()->getByName('sesame.logout');

        self::assertNotNull($route);
        self::assertSame(['POST'], $route->methods());
        self::assertContains('web', $route->gatherMiddleware());

        $this->get('/logout')->assertStatus(405);
    }

    public function testLogoutWithoutACsrfTokenIsRefusedOutsideUnitTests(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        // Laravel skips its CSRF check while unit tests run; outside them the
        // web group's CSRF middleware must refuse a forged logout.
        $app['env'] = 'local';

        $response = $this->withSession(['sesame.user' => 'the-user'])->post('/logout');

        $response->assertStatus(419);
        // The logout route never ran: it is what sends Clear-Site-Data.
        self::assertFalse($response->headers->has('Clear-Site-Data'));
    }

    public function testLogoutSendsNoStoreAndClearSiteData(): void
    {
        $response = $this->withSession(['sesame.user' => 'the-user'])
            ->post('/logout');

        $response->assertRedirect('/');

        // The session handling appends its own "private" directive to the
        // Cache-Control header after the route runs, so assert the no-store
        // directive is present rather than the whole header equaling it.
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('"cache", "storage"', $response->headers->get('Clear-Site-Data'));
        self::assertStringNotContainsString('cookies', $response->headers->get('Clear-Site-Data'));
    }
}
