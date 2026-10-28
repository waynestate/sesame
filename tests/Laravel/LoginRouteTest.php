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
        $this->get('/login')->assertRedirect('/');

        $this->assertSame('the-user', $this->app->make(Sesame::class)->user());
    }

    public function testLoginDropsTheTicketFromTheRedirectAfterACasLogin(): void
    {
        $this->app['config']->set('sesame.driver', 'cas');
        $this->app['config']->set('sesame.cas.base_url', 'https://cas.test/cas');
        $this->app['config']->set('sesame.cas.service_url', 'https://app.test/login');
        $this->app->instance(ClientInterface::class, $this->casHttpClient());

        $response = $this->get('/login?ticket=ST-1');

        $response->assertRedirect('/');
        $this->assertStringNotContainsString('ticket', (string) $response->headers->get('Location'));
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
        $this->withSession(['sesame.user' => 'the-user'])
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertNull($this->app->make(Sesame::class)->user());
    }

    public function testLogoutSendsNoStoreAndClearSiteData(): void
    {
        $response = $this->withSession(['sesame.user' => 'the-user'])
            ->post('/logout');

        $response->assertRedirect('/');

        // The session handling appends its own "private" directive to the
        // Cache-Control header after the route runs, so assert the no-store
        // directive is present rather than the whole header equaling it.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('"cache", "storage"', $response->headers->get('Clear-Site-Data'));
        $this->assertStringNotContainsString('cookies', (string) $response->headers->get('Clear-Site-Data'));
    }
}
