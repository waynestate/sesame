<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
use Lcobucci\JWT\Token\Builder;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Drivers\GatewayDriver;
use Waynestate\Sesame\Drivers\InMemoryReplayGuard;
use Waynestate\Sesame\Drivers\ReplayGuard;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Tests\Support\FakeClock;

/**
 * The gateway driver: signed JWT assertions only.
 *
 * The driver reads one configured header that holds a signed JWT, verifies
 * its signature against the gateway's public key, and checks the issuer,
 * audience and time claims. It never reads a plain identity header, and the
 * request must come from a trusted proxy.
 */
final class GatewayDriverTest extends TestCase
{
    private const HEADER = 'X-Sesame-Assertion';

    private const ISSUER = 'https://drawbridge.wayne.edu';

    private const AUDIENCE = 'sesame';

    private const SUBJECT = 'ab1234';

    private const TRUSTED = ['10.0.0.0/8'];

    private const PEER = '10.1.2.3';

    private const UNTRUSTED_PEER = '192.168.1.1';

    public function testNameIsGateway(): void
    {
        $driver = $this->driver(new FakeClock(), 'ES256', 'unused');

        $this->assertSame('gateway', $driver->name());
    }

    public function testLogoutUrlAndServiceUrlAreNull(): void
    {
        $driver = $this->driver(new FakeClock(), 'ES256', 'unused');

        $this->assertNull($driver->logoutUrl('https://app.test/'));
        $this->assertNull($driver->serviceUrl());
    }

    public function testValidEs256AssertionAuthenticates(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame(self::SUBJECT, $step->identity->username);
        $this->assertSame('gateway', $step->identity->driver);
        $this->assertSame([], $step->identity->attributes);
        $this->assertSame('2026-10-27T12:00:00+00:00', $step->identity->authenticatedAt->format('Y-m-d\TH:i:sP'));
    }

    public function testValidRs256AssertionAuthenticates(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('RS256');
        $driver = $this->driver($clock, 'RS256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame(self::SUBJECT, $step->identity->username);
    }

    public function testValidEddsaAssertionAuthenticates(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('EdDSA');
        $driver = $this->driver($clock, 'EdDSA', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame(self::SUBJECT, $step->identity->username);
    }

    public function testMapsOptionalClaimsToAttributes(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, [
            'extra' => [
                'email' => 'ab1234@wayne.edu',
                'name' => 'Ab 1234',
                'affiliations' => ['wayne.edu', 'wayne.edu/staff'],
            ],
        ]);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame(
            [
                'email' => ['ab1234@wayne.edu'],
                'name' => ['Ab 1234'],
                'affiliations' => ['wayne.edu', 'wayne.edu/staff'],
            ],
            $step->identity->attributes,
        );
        $this->assertSame('ab1234@wayne.edu', $step->identity->attribute('email'));
    }

    public function testExtractsAuthTimeAndAmrFromTheAssertion(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $authTime = $clock->now()->modify('-60 seconds');
        $token = $this->buildToken($signer, $privateKey, $clock, [
            'extra' => [
                'auth_time' => $authTime->getTimestamp(),
                'amr' => ['mfa', 'password'],
            ],
        ]);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame($authTime->getTimestamp(), $step->identity->authTime?->getTimestamp());
        $this->assertSame(['mfa', 'password'], $step->identity->amr);
    }

    public function testAuthTimeAndAmrDefaultToNullAndEmptyWhenTheClaimsAreMissing(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertNull($step->identity->authTime);
        $this->assertSame([], $step->identity->amr);
    }

    public function testAuthTimeIsNullWhenTheClaimIsNotAnInteger(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, [
            'extra' => ['auth_time' => 'not-an-int'],
        ]);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertNull($step->identity->authTime);
    }

    public function testAuthTimeIsNullWhenTheClaimIsNegative(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, [
            'extra' => ['auth_time' => -1],
        ]);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertNull($step->identity->authTime);
    }

    public function testAmrDropsNonStringAndEmptyEntries(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, [
            'extra' => ['amr' => ['mfa', 42, '', null, 'password']],
        ]);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame(['mfa', 'password'], $step->identity->amr);
    }

    public function testAmrIsEmptyWhenTheClaimIsNotAnArray(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, [
            'extra' => ['amr' => 'mfa'],
        ]);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame([], $step->identity->amr);
    }

    public function testTrustedPeerMatchesACidrRange(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey, ['172.16.0.0/12']);
        $token = $this->buildToken($signer, $privateKey, $clock);

        $step = $driver->login($this->request($token, '172.20.1.5'));

        $this->assertInstanceOf(Authenticated::class, $step);
    }

    public function testTrustedPeerMatchesTheSecondOfSeveralRanges(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey, ['10.0.0.0/8', '172.16.0.0/12']);
        $token = $this->buildToken($signer, $privateKey, $clock);

        $step = $driver->login($this->request($token, '172.20.1.5'));

        $this->assertInstanceOf(Authenticated::class, $step);
    }

    public function testRejectsAnUntrustedPeer(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock);

        $this->assertRejected($driver, $this->request($token, self::UNTRUSTED_PEER), 'gateway.untrusted_peer');
    }

    public function testRejectsARequestWithNoSourceAddress(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock);

        $this->assertRejected($driver, $this->request($token, null), 'gateway.untrusted_peer');
    }

    public function testRejectsAMissingAssertion(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);

        $this->assertRejected($driver, $this->request(null), 'gateway.missing_assertion');
    }

    public function testIgnoresPlainIdentityHeadersWhenThereIsNoSignedAssertion(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);

        $this->assertRejected(
            $driver,
            $this->request(null, self::PEER, [
                'Remote-User' => 'evil',
                'X-Remote-User' => 'evil',
                'Remote_User' => 'evil',
                'X-Authenticated-User' => 'evil',
            ]),
            'gateway.missing_assertion',
        );
    }

    public function testPlainIdentityHeadersAreIgnoredInFavorOfTheSignedAssertion(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock);

        $step = $driver->login($this->request($token, self::PEER, [
            'Remote-User' => 'evil',
            'X-Remote-User' => 'evil',
            'Remote_User' => 'evil',
            'X-Authenticated-User' => 'evil',
        ]));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame(self::SUBJECT, $step->identity->username);
    }

    public function testRejectsAnAlgNoneToken(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);

        $encoder = new JoseEncoder();
        $header = $encoder->base64UrlEncode($encoder->jsonEncode(['alg' => 'none', 'typ' => 'JWT']));
        $now = $clock->now();
        $payload = $encoder->base64UrlEncode($encoder->jsonEncode([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => self::SUBJECT,
            'iat' => $now->modify('-60 seconds')->getTimestamp(),
            'exp' => $now->modify('+3600 seconds')->getTimestamp(),
        ]));
        $token = $header.'.'.$payload.'.';

        $this->assertRejected($driver, $this->request($token), 'gateway.invalid_jwt');
    }

    public function testRejectsAWrongAlgorithm(): void
    {
        $clock = new FakeClock();
        // Sign with RS256, but the driver expects ES256.
        [$signer, $privateKey] = $this->keys('RS256');
        [, , $es256PublicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $es256PublicKey);
        $token = $this->buildToken($signer, $privateKey, $clock);

        $this->assertRejected($driver, $this->request($token), 'gateway.signature');
    }

    public function testRejectsABadSignature(): void
    {
        $clock = new FakeClock();
        // Sign with one ES256 key, but the driver uses a different ES256 public key.
        [$signer, $privateKey] = $this->keys('ES256');
        [, , $otherPublicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $otherPublicKey);
        $token = $this->buildToken($signer, $privateKey, $clock);

        $this->assertRejected($driver, $this->request($token), 'gateway.signature');
    }

    public function testRejectsAWrongIssuer(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, ['issuer' => 'https://evil.test']);

        $this->assertRejected($driver, $this->request($token), 'gateway.issuer');
    }

    public function testRejectsAWrongAudience(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, ['audience' => 'other-app']);

        $this->assertRejected($driver, $this->request($token), 'gateway.audience');
    }

    public function testRejectsAnExpiredToken(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, ['exp' => $clock->now()->modify('-60 seconds')]);

        $this->assertRejected($driver, $this->request($token), 'gateway.expired');
    }

    public function testAcceptsAnExpiredTokenWithinTheLeeway(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        // Expired 10 seconds ago, within the 30-second leeway.
        $token = $this->buildToken($signer, $privateKey, $clock, ['exp' => $clock->now()->modify('-10 seconds')]);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
    }

    public function testRejectsATokenNotYetValid(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, ['nbf' => $clock->now()->modify('+60 seconds')]);

        $this->assertRejected($driver, $this->request($token), 'gateway.not_yet_valid');
    }

    public function testAcceptsATokenNotYetValidWithinTheLeeway(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        // Not valid for another 10 seconds, within the 30-second leeway.
        $token = $this->buildToken($signer, $privateKey, $clock, ['nbf' => $clock->now()->modify('+10 seconds')]);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
    }

    public function testRejectsATokenIssuedInTheFuture(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, ['iat' => $clock->now()->modify('+60 seconds')]);

        $this->assertRejected($driver, $this->request($token), 'gateway.issued_in_future');
    }

    public function testAcceptsATokenIssuedInTheFutureWithinTheLeeway(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        // Issued 10 seconds in the future, within the 30-second leeway.
        $token = $this->buildToken($signer, $privateKey, $clock, ['iat' => $clock->now()->modify('+10 seconds')]);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
    }

    public function testRejectsAMissingIatClaim(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, ['iat' => null]);

        $this->assertRejected($driver, $this->request($token), 'gateway.missing_claim');
    }

    public function testRejectsAMissingExpClaim(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, ['exp' => null]);

        $this->assertRejected($driver, $this->request($token), 'gateway.missing_claim');
    }

    public function testRejectsAMissingSubClaim(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, ['subject' => null]);

        $this->assertRejected($driver, $this->request($token), 'gateway.missing_claim');
    }

    public function testRejectsAnEmptySubClaim(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, ['subject' => '']);

        $this->assertRejected($driver, $this->request($token), 'gateway.missing_claim');
    }

    public function testRejectsAMalformedToken(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);

        $this->assertRejected($driver, $this->request('not-a-jwt'), 'gateway.invalid_jwt');
    }

    public function testRejectsAMissingJtiClaim(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, ['jti' => null]);

        $this->assertRejected($driver, $this->request($token), 'gateway.missing_jti');
    }

    public function testRejectsAnEmptyJtiClaim(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, ['jti' => '']);

        $this->assertRejected($driver, $this->request($token), 'gateway.missing_jti');
    }

    public function testRejectsAnOversizedJtiClaim(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, ['jti' => str_repeat('a', 129)]);

        $this->assertRejected($driver, $this->request($token), 'gateway.missing_jti');
    }

    public function testAcceptsAMaxLengthJtiClaim(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        $token = $this->buildToken($signer, $privateKey, $clock, ['jti' => str_repeat('a', 128)]);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
        $this->assertSame(self::SUBJECT, $step->identity->username);
    }

    public function testRejectsAReplayedAssertion(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $guard = new InMemoryReplayGuard(30, $clock);
        $driver = $this->driver($clock, 'ES256', $publicKey, self::TRUSTED, 30, $guard);
        $token = $this->buildToken($signer, $privateKey, $clock, ['jti' => 'fixed-jti']);

        $step = $driver->login($this->request($token));
        $this->assertInstanceOf(Authenticated::class, $step);

        $this->assertRejected($driver, $this->request($token), 'gateway.replayed_assertion');
    }

    public function testAcceptsADifferentJtiAfterOneUse(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $guard = new InMemoryReplayGuard(30, $clock);
        $driver = $this->driver($clock, 'ES256', $publicKey, self::TRUSTED, 30, $guard);
        $first = $this->buildToken($signer, $privateKey, $clock, ['jti' => 'jti-one']);
        $second = $this->buildToken($signer, $privateKey, $clock, ['jti' => 'jti-two']);

        $this->assertInstanceOf(Authenticated::class, $driver->login($this->request($first)));
        $this->assertInstanceOf(Authenticated::class, $driver->login($this->request($second)));
    }

    public function testAcceptsAReusedJtiAfterItsRecordExpires(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $guard = new InMemoryReplayGuard(30, $clock);
        $driver = $this->driver($clock, 'ES256', $publicKey, self::TRUSTED, 30, $guard);
        $token = $this->buildToken($signer, $privateKey, $clock, ['jti' => 'fixed-jti']);

        $this->assertInstanceOf(Authenticated::class, $driver->login($this->request($token)));

        // The record lives until exp plus leeway (240 + 30 = 270 seconds).
        // Move past it, and the same jti is usable again.
        $clock->advance(271);
        $token = $this->buildToken($signer, $privateKey, $clock, ['jti' => 'fixed-jti']);

        $this->assertInstanceOf(Authenticated::class, $driver->login($this->request($token)));
    }

    public function testAcceptsALifetimeAtTheMaximum(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        // The default token lives exactly 300 seconds: the default maximum.
        $token = $this->buildToken($signer, $privateKey, $clock);

        $step = $driver->login($this->request($token));

        $this->assertInstanceOf(Authenticated::class, $step);
    }

    public function testRejectsALifetimeOverTheMaximum(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        $driver = $this->driver($clock, 'ES256', $publicKey);
        // exp = now + 241, so the assertion lives 301 seconds: one over the max.
        $token = $this->buildToken($signer, $privateKey, $clock, ['exp' => $clock->now()->modify('+241 seconds')]);

        $this->assertRejected($driver, $this->request($token), 'gateway.lifetime_too_long');
    }

    public function testHonorsACustomMaximumLifetime(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        // A 60-second maximum: the default 300-second assertion exceeds it.
        $driver = $this->driver($clock, 'ES256', $publicKey, self::TRUSTED, 30, null, 60);
        $token = $this->buildToken($signer, $privateKey, $clock);

        $this->assertRejected($driver, $this->request($token), 'gateway.lifetime_too_long');
    }

    public function testFailsClosedWithoutAReplayGuard(): void
    {
        $clock = new FakeClock();
        [, , $publicKey] = $this->keys('ES256');

        $this->expectException(InvalidConfig::class);

        new GatewayDriver(
            self::HEADER,
            'ES256',
            $publicKey,
            self::ISSUER,
            self::AUDIENCE,
            30,
            self::TRUSTED,
            $clock,
            // No replay guard, and no opt-out: the driver refuses to start.
        );
    }

    public function testBootsWithoutAReplayGuardWhenAllowed(): void
    {
        $clock = new FakeClock();
        [$signer, $privateKey, $publicKey] = $this->keys('ES256');
        // Constructed directly (not via driver()), so no guard is supplied.
        $driver = new GatewayDriver(
            self::HEADER,
            'ES256',
            $publicKey,
            self::ISSUER,
            self::AUDIENCE,
            30,
            self::TRUSTED,
            $clock,
            null,
            300,
            true,
        );
        // With no guard the replay check is skipped, so the same jti passes twice.
        $token = $this->buildToken($signer, $privateKey, $clock, ['jti' => 'fixed-jti']);

        $this->assertInstanceOf(Authenticated::class, $driver->login($this->request($token)));
        $this->assertInstanceOf(Authenticated::class, $driver->login($this->request($token)));
    }

    /**
     * A driver with the given algorithm, public key, trusted proxies and leeway.
     *
     * Without an explicit replay guard, the driver gets a fresh in-memory one
     * (sharing the clock), so the single-use checks are on. The fail-closed
     * test constructs the driver directly instead.
     */
    private function driver(FakeClock $clock, string $algorithm, string $publicKey, array $trustedProxies = self::TRUSTED, int $leeway = 30, ?ReplayGuard $replayGuard = null, int $maxLifetimeSeconds = 300, bool $allowWithoutReplayGuard = false): GatewayDriver
    {
        return new GatewayDriver(
            self::HEADER,
            $algorithm,
            $publicKey,
            self::ISSUER,
            self::AUDIENCE,
            $leeway,
            $trustedProxies,
            $clock,
            $replayGuard ?? new InMemoryReplayGuard($leeway, $clock),
            $maxLifetimeSeconds,
            $allowWithoutReplayGuard,
        );
    }

    /**
     * The signer, the private key (for signing) and the public key (for the
     * driver) for the given algorithm.
     *
     * @return array{0: Signer, 1: Key, 2: string}
     */
    private function keys(string $algorithm): array
    {
        return match ($algorithm) {
            'ES256' => $this->es256Keys(),
            'RS256' => $this->rsaKeys(),
            'EdDSA' => $this->eddsaKeys(),
        };
    }

    /**
     * @return array{0: Signer, 1: Key, 2: string}
     */
    private function es256Keys(): array
    {
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        openssl_pkey_export($resource, $privatePem);
        $publicPem = openssl_pkey_get_details($resource)['key'];

        return [new Sha256(), InMemory::plainText($privatePem), $publicPem];
    }

    /**
     * @return array{0: Signer, 1: Key, 2: string}
     */
    private function rsaKeys(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $privatePem);
        $publicPem = openssl_pkey_get_details($resource)['key'];

        return [new RsaSha256(), InMemory::plainText($privatePem), $publicPem];
    }

    /**
     * @return array{0: Signer, 1: Key, 2: string}
     */
    private function eddsaKeys(): array
    {
        $keypair = sodium_crypto_sign_keypair();

        return [
            new Eddsa(),
            InMemory::plainText(sodium_crypto_sign_secretkey($keypair)),
            base64_encode(sodium_crypto_sign_publickey($keypair)),
        ];
    }

    /**
     * A signed token, with the standard claims and any overrides.
     *
     * A claim set to null in $overrides is omitted from the token, so the
     * missing-claim tests can drop iat, exp, sub or jti.
     *
     * The default iat is 60 seconds in the past and the default exp is 240
     * seconds in the future, so the assertion lives exactly 300 seconds: the
     * driver's default maximum lifetime. A default jti is present and unique
     * per call, so two different tokens never collide; the replay tests
     * override it with a fixed value.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function buildToken(Signer $signer, Key $privateKey, FakeClock $clock, array $overrides = []): string
    {
        $now = $clock->now();

        $issuer = $this->override($overrides, 'issuer', self::ISSUER);
        $audience = $this->override($overrides, 'audience', self::AUDIENCE);
        $subject = $this->override($overrides, 'subject', self::SUBJECT);
        $jti = $this->override($overrides, 'jti', uniqid('jti-', true));
        $iat = $this->override($overrides, 'iat', $now->modify('-60 seconds'));
        $nbf = $this->override($overrides, 'nbf', $now->modify('-60 seconds'));
        $exp = $this->override($overrides, 'exp', $now->modify('+240 seconds'));
        $extra = $this->override($overrides, 'extra', []);

        $builder = Builder::new(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates());

        if ($issuer !== null) {
            $builder = $builder->issuedBy($issuer);
        }

        if ($audience !== null) {
            $builder = $builder->permittedFor($audience);
        }

        if ($iat !== null) {
            $builder = $builder->issuedAt($iat);
        }

        if ($nbf !== null) {
            $builder = $builder->canOnlyBeUsedAfter($nbf);
        }

        if ($exp !== null) {
            $builder = $builder->expiresAt($exp);
        }

        if ($subject !== null) {
            $builder = $builder->relatedTo($subject);
        }

        if ($jti !== null) {
            $builder = $builder->identifiedBy($jti);
        }

        foreach ($extra as $name => $value) {
            $builder = $builder->withClaim($name, $value);
        }

        return $builder->getToken($signer, $privateKey)->toString();
    }

    /**
     * The override for a claim, or its default when the key is absent.
     *
     * Unlike the null coalescing operator, a key set to null is honored, so
     * the tests can drop a claim on purpose.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function override(array $overrides, string $key, mixed $default): mixed
    {
        return array_key_exists($key, $overrides) ? $overrides[$key] : $default;
    }

    /**
     * A request from the given peer, with the given assertion (or none) and
     * any extra headers.
     *
     * @param  array<string, string>  $extraHeaders
     */
    private function request(?string $token, ?string $peer = self::PEER, array $extraHeaders = []): ServerRequest
    {
        $headers = $extraHeaders;

        if ($token !== null) {
            $headers[self::HEADER] = $token;
        }

        $serverParams = $peer !== null ? ['REMOTE_ADDR' => $peer] : [];

        return new ServerRequest('GET', 'https://app.test/login', $headers, null, '1.1', $serverParams);
    }

    /**
     * Assert that the driver rejects the request with the given reason code.
     */
    private function assertRejected(GatewayDriver $driver, ServerRequest $request, string $reason): void
    {
        try {
            $driver->login($request);
            $this->fail('Expected AuthenticationFailed.');
        } catch (AuthenticationFailed $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }
    }
}
