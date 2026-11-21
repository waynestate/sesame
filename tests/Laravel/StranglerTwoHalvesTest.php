<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Revocation\FileRevocationStore;
use Waynestate\Sesame\RevocationStore;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\SessionPort;
use Waynestate\Sesame\Tests\Support\ArraySession;
use Waynestate\Sesame\Tests\Support\CasDouble;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\RecordingResolver;

/**
 * Booster's Laravel strangler (ADR-0024, sections 9.1 and 10.6): one app in
 * two halves, the old one still owning /login and Laravel signing in under
 * /_laravel/login, each on its own session, both sharing one file
 * revocation store.
 *
 * The CAS server is a test double that behaves like one: its /login sets a
 * ticket-granting cookie on the first sign-in and answers the next one
 * silently (a redirect with a fresh ticket, no prompt), its tickets are
 * single use and bound to their service URL, and /serviceValidate answers
 * the CAS XML. So the test proves the second half's sign-in is a silent
 * round trip and that a logout on either half ends both.
 *
 * Not final: StranglerTwoHalvesJsonSessionTest runs the same tests with
 * the Laravel half on a JSON session, as Sourdough's apps are.
 */
class StranglerTwoHalvesTest extends LaravelTestCase
{
    private string $dir;

    private FakeClock $clock;

    protected CasDouble $cas;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sesame-strangler-' . bin2hex(random_bytes(6));
        $this->clock = new FakeClock();
        $this->cas = new CasDouble('jdoe');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->files() as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app['config'];
        $config->set('app.url', 'https://app.test');
        $config->set('sesame.driver', 'cas');
        $config->set('sesame.cas.base_url', 'https://cas.test/cas');
        $config->set('sesame.route_prefix', '_laravel');
        $config->set('sesame.revocation.path', $this->dir);

        $app->instance(ClientInterface::class, $this->cas);
        $app->instance(ClockInterface::class, $this->clock);
    }

    public function testTheLaravelHalfSignsInSilentlyAndALogoutOnEitherHalfEndsBoth(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        // The legacy half: the old app's own SESAME, on its own session,
        // with the same store directory.
        $legacySession = new ArraySession();
        $legacy = $this->legacySesame($legacySession);

        // 1. Sign in on the legacy half: CAS prompts once and sets its cookie.
        $step = $legacy->login(new ServerRequest('GET', 'https://app.test/login'));
        self::assertInstanceOf(Redirect::class, $step);
        $ticket = $this->cas->browse($step->url, 'https://app.test/login');
        self::assertSame('the-user', $legacy->login(new ServerRequest('GET', 'https://app.test/login?ticket=' . $ticket)));
        self::assertSame(1, $this->cas->prompts);

        // The halves share no session: Laravel knows nobody yet.
        self::assertNull($app->make(Sesame::class)->user());

        // 2. The Laravel half's own sign-in lives under the prefix, and its
        //    service URL defaults to the prefixed route.
        $this->clock->advance(2);
        $redirect = $this->get('https://app.test/_laravel/login');
        $location = (string) $redirect->headers->get('Location');
        self::assertStringStartsWith('https://cas.test/cas/login?', $location);
        self::assertStringContainsString(rawurlencode('https://app.test/_laravel/login'), $location);
        $this->get('/login')->assertNotFound();

        $ticket = $this->cas->browse($location, 'https://app.test/_laravel/login');
        $this->get('https://app.test/_laravel/login?ticket=' . $ticket)->assertRedirect();
        self::assertSame(1, $this->cas->prompts, 'The second half signed in through a silent CAS round trip.');
        self::assertSame('the-user', $app->make(Sesame::class)->user());
        self::assertSame('the-user', $legacy->user(), 'The second sign-in left the first session alone.');

        // 3. Logout on the Laravel half ends the legacy session too.
        $this->clock->advance(2);
        $this->post('https://app.test/_laravel/logout')->assertRedirect();
        self::assertNull($app->make(Sesame::class)->user());
        self::assertNull($legacy->user(), 'The Laravel logout ended the legacy session.');
        self::assertNull($legacySession->get('sesame.user'));

        // 4. And the other way round: both sign in again, the legacy half logs out.
        $this->clock->advance(2);
        $step = $legacy->login(new ServerRequest('GET', 'https://app.test/login'));
        self::assertInstanceOf(Redirect::class, $step);
        $ticket = $this->cas->browse($step->url, 'https://app.test/login');
        self::assertSame('the-user', $legacy->login(new ServerRequest('GET', 'https://app.test/login?ticket=' . $ticket)));
        $location = (string) $this->get('https://app.test/_laravel/login')->headers->get('Location');
        $this->get('https://app.test/_laravel/login?ticket=' . $this->cas->browse($location, 'https://app.test/_laravel/login'));
        self::assertSame('the-user', $app->make(Sesame::class)->user());

        $this->clock->advance(2);
        $legacy->logout('https://app.test/');
        self::assertNull($legacy->user());
        self::assertNull($app->make(Sesame::class)->user(), 'The legacy logout ended the Laravel session.');
    }

    public function testTheStoreIsTheAppsOwnBindingWhenItHasOne(): void
    {
        $app = $this->app;
        self::assertNotNull($app);
        $store = new \Waynestate\Sesame\Revocation\InMemoryRevocationStore();
        $app->instance(RevocationStore::class, $store);
        $app->forgetInstance(Sesame::class);

        $location = (string) $this->get('https://app.test/_laravel/login')->headers->get('Location');
        $this->get('https://app.test/_laravel/login?ticket=' . $this->cas->browse($location, 'https://app.test/_laravel/login'));
        $this->post('https://app.test/_laravel/logout');

        self::assertNotNull($store->revokedBefore('jdoe'), 'The bound store took the cutoff.');
        self::assertSame([], $this->files(), 'The path store was not used.');
    }

    public function testATicketIssuedForOneHalfIsRefusedByTheOther(): void
    {
        $legacy = $this->legacySesame(new ArraySession());

        $location = (string) $this->get('https://app.test/_laravel/login')->headers->get('Location');
        $ticket = $this->cas->browse($location, 'https://app.test/_laravel/login');

        $step = $legacy->login(new ServerRequest('GET', 'https://app.test/login'));
        self::assertInstanceOf(Redirect::class, $step);

        $this->expectException(\Waynestate\Sesame\Exception\AuthenticationFailed::class);
        $legacy->login(new ServerRequest('GET', 'https://app.test/login?ticket=' . $ticket));
    }

    private function legacySesame(SessionPort $session): Sesame
    {
        $config = Config::fromArray([
            'driver' => 'cas',
            'environment' => 'testing',
            'cas' => ['base_url' => 'https://cas.test/cas', 'service_url' => 'https://app.test/login'],
        ]);

        return new Sesame(
            DriverFactory::make($config, $this->cas, new Psr17Factory(), $this->clock, null, null, $session),
            new RecordingResolver(),
            $session,
            $config,
            $this->clock,
            null,
            null,
            new FileRevocationStore($this->dir, 3600, $this->clock),
        );
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*');

        return $files === false ? [] : $files;
    }
}
