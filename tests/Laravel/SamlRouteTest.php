<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Psr\Clock\ClockInterface;
use Waynestate\Sesame\Driver;
use Waynestate\Sesame\Drivers\SamlDriver;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\FakeSamlIdp;

/**
 * The saml driver through the Laravel adapter: GET /login sends the user to
 * the IdP, POST /login/saml/acs parks the response without a session and
 * answers 303, GET /login?saml_handle= finishes the sign-in, and
 * GET /login/saml/metadata serves the SP metadata. The SP URLs default to
 * the app's own routes.
 */
final class SamlRouteTest extends LaravelTestCase
{
    private const ACS = 'http://localhost/login/saml/acs';

    private const ENTITY = 'http://localhost/login/saml/metadata';

    private FakeSamlIdp $idp;

    private string $metadataFile;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $clock = new FakeClock('@' . time());
        $this->idp = new FakeSamlIdp($clock);
        $this->metadataFile = FakeSamlIdp::metadataFile();

        $app['config']->set('sesame.driver', 'saml');
        $app['config']->set('sesame.saml.idp.metadata_file', $this->metadataFile);
        $app['config']->set('cache.default', 'array');
        $app->instance(ClockInterface::class, $clock);
    }

    protected function tearDown(): void
    {
        @unlink($this->metadataFile);

        parent::tearDown();
    }

    public function testTheRoutesAndDefaultUrls(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        self::assertTrue($app['router']->getRoutes()->hasNamedRoute('sesame.saml.acs'));
        self::assertTrue($app['router']->getRoutes()->hasNamedRoute('sesame.saml.metadata'));
        self::assertFalse($app['router']->getRoutes()->hasNamedRoute('sesame.callback'));

        $driver = $app->make(Driver::class);
        self::assertInstanceOf(SamlDriver::class, $driver);
        self::assertSame('http://localhost/login', $driver->serviceUrl());
        self::assertSame(self::ACS, $driver->acsUrl());
    }

    public function testTheAcsRouteRunsWithoutTheWebGroup(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        $route = $app['router']->getRoutes()->getByName('sesame.saml.acs');
        self::assertNotNull($route);
        self::assertNotContains('web', $route->gatherMiddleware());
    }

    public function testASignInRoundTrip(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        $start = $this->get('/login?return_to=' . rawurlencode('http://localhost/reports'));
        $start->assertRedirect();
        $request = FakeSamlIdp::authnRequest((string) $start->headers->get('Location'));

        $response = $this->idp->response($request['id'], ['destination' => self::ACS, 'recipient' => self::ACS, 'audience' => self::ENTITY]);

        // The IdP's cross-site POST: no session cookie, no CSRF token.
        $acs = $this->call('POST', '/login/saml/acs', ['SAMLResponse' => $response, 'RelayState' => $request['query']['RelayState']]);
        $acs->assertStatus(303);
        $acs->assertHeader('Cache-Control', 'no-store, private');
        $location = (string) $acs->headers->get('Location');
        self::assertMatchesRegularExpression('#^http://localhost/login\?saml_handle=[A-Za-z0-9_-]{43}$#D', $location);
        self::assertSame([], $acs->headers->getCookies(), 'The ACS must not set a session cookie.');

        $this->get($location)->assertRedirect('http://localhost/reports');

        self::assertSame('the-user', $app->make(Sesame::class)->user());
        $identity = $app['session.store']->get('sesame.identity');
        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame('ab1234', $identity->username);
        self::assertSame('saml', $identity->driver);
    }

    public function testAnUnusableAcsPostIsA400(): void
    {
        $this->call('POST', '/login/saml/acs', [])->assertStatus(400);
    }

    public function testTheMetadataRoute(): void
    {
        $response = $this->get('/login/saml/metadata');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/samlmetadata+xml');
        $xml = (string) $response->baseResponse->getContent();
        self::assertStringContainsString('entityID="' . self::ENTITY . '"', $xml);
        self::assertStringContainsString('Location="' . self::ACS . '"', $xml);
    }
}
