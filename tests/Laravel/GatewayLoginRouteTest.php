<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests\Laravel;

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Psr\Clock\ClockInterface;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Tests\Support\FakeClock;

/**
 * The gateway driver behind the Laravel adapter.
 *
 * Switching from the cas driver to the gateway driver is a config-only
 * change: the same service provider, the same routes, the same middleware.
 * The test sets sesame.driver to "gateway", points the gateway config at a
 * test key, and signs a request the way the DRAWBRIDGE gateway would. A
 * request that carries a valid signed assertion (and no CAS ticket) signs
 * the user in; if the driver were still cas, the same request would be
 * redirected to the CAS server instead.
 */
final class GatewayLoginRouteTest extends LaravelTestCase
{
    private const ISSUER = 'https://drawbridge.wayne.edu';

    private const AUDIENCE = 'sesame';

    private const SUBJECT = 'ab1234';

    public function testGatewayLoginAuthenticatesFromConfigAlone(): void
    {
        $clock = new FakeClock();
        $this->app->instance(ClockInterface::class, $clock);

        [$publicKey, $privateKey] = $this->keyPair();

        $this->app['config']->set('sesame.driver', 'gateway');
        $this->app['config']->set('sesame.gateway.public_key', $publicKey);
        $this->app['config']->set('sesame.gateway.issuer', self::ISSUER);
        $this->app['config']->set('sesame.gateway.audience', self::AUDIENCE);
        $this->app['config']->set('sesame.gateway.trusted_proxies', ['10.0.0.0/8']);

        $token = $this->buildToken($privateKey, $clock);

        $response = $this->call('GET', '/login', [], [], [], [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_SESAME_ASSERTION' => $token,
        ]);

        $response->assertRedirect('/');
        $this->assertSame('the-user', $this->app->make(Sesame::class)->user());
        $this->assertSame('gateway', $this->app['session']->get('sesame.identity')->driver);
    }

    public function testGatewayLoginRejectsARequestWithoutASignedAssertion(): void
    {
        $clock = new FakeClock();
        $this->app->instance(ClockInterface::class, $clock);

        [$publicKey] = $this->keyPair();

        $this->app['config']->set('sesame.driver', 'gateway');
        $this->app['config']->set('sesame.gateway.public_key', $publicKey);
        $this->app['config']->set('sesame.gateway.issuer', self::ISSUER);
        $this->app['config']->set('sesame.gateway.audience', self::AUDIENCE);
        $this->app['config']->set('sesame.gateway.trusted_proxies', ['10.0.0.0/8']);

        // A plain identity header, the way a misbehaving proxy might send
        // one, is not a signed assertion: the gateway driver refuses it.
        $this->withoutExceptionHandling();

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessageMatches('/gateway\.missing_assertion/');

        $this->call('GET', '/login', [], [], [], [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_REMOTE_USER' => 'evil',
        ]);
    }

    /**
     * The ES256 key pair the test uses: the public key goes in the gateway
     * config, the private key signs the assertion. Both halves come from the
     * same pair, generated once per test.
     *
     * @return array{0: string, 1: string} The public key (PEM) and the
     *                                     private key (PEM).
     */
    private function keyPair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        openssl_pkey_export($resource, $privatePem);

        return [openssl_pkey_get_details($resource)['key'], $privatePem];
    }

    /**
     * A signed ES256 assertion, the way the DRAWBRIDGE gateway would send
     * one, valid against the given clock.
     */
    private function buildToken(string $privateKey, FakeClock $clock): string
    {
        $now = $clock->now();

        return Builder::new(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates())
            ->issuedBy(self::ISSUER)
            ->permittedFor(self::AUDIENCE)
            ->issuedAt($now->modify('-60 seconds'))
            ->canOnlyBeUsedAfter($now->modify('-60 seconds'))
            ->expiresAt($now->modify('+3600 seconds'))
            ->relatedTo(self::SUBJECT)
            ->getToken(new Sha256(), InMemory::plainText($privateKey))
            ->toString();
    }
}
