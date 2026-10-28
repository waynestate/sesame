<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Client\ClientInterface;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\FakeHttpClient;

/**
 * Login regenerates the session id.
 *
 * The core regenerates the session id on a verified login (Sesame::login),
 * and the Laravel routes go through the core. These tests drive the real
 * /login route with a known session id and assert the id left behind is a
 * different one, so a session id that existed before authentication cannot
 * be reused after it. The session data survives the regeneration.
 */
final class LoginRegeneratesTest extends LaravelTestCase
{
    public function testLoginRegeneratesTheSessionId(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);

        /** @var \Illuminate\Contracts\Session\Session $store */
        $store = $app['session.store'];

        $before = bin2hex(random_bytes(20));
        $store->setId($before);
        $store->put('marker', 'before');

        $cookie = (string) $app['config']->get('session.cookie');

        $this->withCookie($cookie, $before)
            ->get('/login')
            ->assertRedirect('/');

        $this->assertNotSame($before, $store->getId());
        $this->assertSame('before', $store->get('marker'));
        $this->assertSame('the-user', $app->make(Sesame::class)->user());
    }

    public function testLoginRegeneratesTheSessionIdAfterACasLogin(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);
        $app['config']->set('sesame.driver', 'cas');
        $app['config']->set('sesame.cas.base_url', 'https://cas.test/cas');
        $app['config']->set('sesame.cas.service_url', 'https://app.test/login');
        $app->instance(ClientInterface::class, $this->casHttpClient());

        /** @var \Illuminate\Contracts\Session\Session $store */
        $store = $app['session.store'];

        $before = bin2hex(random_bytes(20));
        $store->setId($before);
        $store->put('marker', 'before');

        $cookie = (string) $app['config']->get('session.cookie');

        $this->withCookie($cookie, $before)
            ->get('/login?ticket=ST-1')
            ->assertRedirect('/');

        $this->assertNotSame($before, $store->getId());
        $this->assertSame('before', $store->get('marker'));
        $this->assertSame('the-user', $app->make(Sesame::class)->user());
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
}
