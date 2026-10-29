<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use Closure;
use Firebase\JWT\JWT;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Drivers\OAuth2Driver;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Tests\Support\ArrayCache;
use Waynestate\Sesame\Tests\Support\FakeClock;
use Waynestate\Sesame\Tests\Support\FakeHttpClient;

/**
 * The oauth2 driver: RS256 bearer tokens validated against a JWKS.
 *
 * The keys are generated locally (two independent RSA pairs, so a rotation
 * test can publish a second key), and the issuer is a fake PSR-18 client
 * that answers with queued JWKS documents. Nothing touches the network.
 */
final class OAuth2DriverTest extends TestCase
{
    private const ISSUER = 'https://login.example.com/v2.0';

    private const JWKS_URL = 'https://login.example.com/discovery/v2.0/keys';

    private const AUDIENCE = 'test-audience';

    private const KID_A = 'test-key-a';

    private const KID_B = 'test-key-b';

    /**
     * The locally generated key pairs, memoized by name.
     *
     * @var array<string, array{private: string, jwk: array<string, string>}>
     */
    private static array $keyPairs = [];

    private FakeClock $clock;

    private FakeHttpClient $http;

    private ArrayCache $cache;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->http = new FakeHttpClient();
        $this->cache = new ArrayCache($this->clock);
    }

    public function testNameIsOauth2(): void
    {
        self::assertSame('oauth2', $this->driver()->name());
    }

    public function testLogoutUrlAndServiceUrlAreNull(): void
    {
        $driver = $this->driver();

        self::assertNull($driver->logoutUrl('https://app.test/'));
        self::assertNull($driver->serviceUrl());
    }

    public function testAValidTokenSignsTheUserIn(): void
    {
        $step = $this->validLogin();

        self::assertSame('jdoe', $step->identity->username);
        self::assertSame('oauth2', $step->identity->driver);
        self::assertSame($this->clock->now(), $step->identity->authenticatedAt);
    }

    public function testMapsEmailAndNameClaimsToAttributes(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $step = $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: [
            'email' => 'jdoe@example.com',
            'name' => 'Jane Doe',
        ])));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame(['jdoe@example.com'], $step->identity->attributes['email']);
        self::assertSame(['Jane Doe'], $step->identity->attributes['name']);
    }

    public function testDropsMalformedAttributeClaims(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $step = $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: [
            'email' => ['jdoe@example.com', 42],
            'name' => 42,
        ])));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame(['jdoe@example.com'], $step->identity->attributes['email']);
        self::assertArrayNotHasKey('name', $step->identity->attributes);
    }

    public function testReadsAuthTimeFromTheAuthTokenClaim(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $authTime = $this->clock->now()->getTimestamp() - 300;
        $step = $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: [
            'auth_time' => $authTime,
        ])));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertNotNull($step->identity->authTime);
        self::assertSame($authTime, $step->identity->authTime->getTimestamp());
    }

    public function testLeavesAuthTimeNullWhenItIsMissingOrNotAnInteger(): void
    {
        foreach ([[], ['auth_time' => 'yesterday'], ['auth_time' => -5]] as $overrides) {
            [$privateKey, $jwk] = self::keyPair('a');
            $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

            $step = $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: $overrides)));

            self::assertInstanceOf(Authenticated::class, $step);
            self::assertNull($step->identity->authTime);
        }
    }

    public function testReadsAmrFromTheAuthTokenClaim(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $step = $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: [
            'amr' => ['mfa', 'pwd', 42],
        ])));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame(['mfa', 'pwd'], $step->identity->amr);
    }

    public function testAudienceMayBeAListThatIncludesTheExpectedAudience(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $step = $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: [
            'aud' => ['other-audience', self::AUDIENCE],
        ])));

        self::assertInstanceOf(Authenticated::class, $step);
    }

    public function testALowercaseBearerSchemeIsAccepted(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $step = $this->driver()->login($this->requestWith('bearer ' . $this->token($privateKey)));

        self::assertInstanceOf(Authenticated::class, $step);
    }

    public function testAMissingAuthorizationHeaderIsRejected(): void
    {
        $this->assertRejected('oauth2.missing_token', fn(): mixed => $this->driver()->login($this->requestWith(null)));

        self::assertCount(0, $this->http->requests);
    }

    public function testANonBearerAuthorizationHeaderIsRejected(): void
    {
        $this->assertRejected('oauth2.missing_token', fn(): mixed => $this->driver()->login($this->requestWith('Basic dG9rZW4=')));

        self::assertCount(0, $this->http->requests);
    }

    public function testAnEmptyBearerTokenIsRejected(): void
    {
        $this->assertRejected('oauth2.missing_token', fn(): mixed => $this->driver()->login($this->requestWith('Bearer   ')));

        self::assertCount(0, $this->http->requests);
    }

    public function testAMalformedTokenIsRejectedWithoutTouchingTheIssuer(): void
    {
        $this->assertRejected('oauth2.invalid_jwt', fn(): mixed => $this->driver()->login($this->requestWith('Bearer not.a.jwt')));

        self::assertCount(0, $this->http->requests);
    }

    public function testATokenWithoutAKeyIsRejected(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $token = JWT::encode($this->claims(), $privateKey, 'RS256');

        $this->assertRejected('oauth2.missing_kid', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $token)));

        self::assertCount(0, $this->http->requests);
    }

    public function testAnUnsignedTokenIsRejected(): void
    {
        $header = self::base64UrlEncode((string) json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $payload = self::base64UrlEncode((string) json_encode($this->claims()));

        $this->assertRejected('oauth2.bad_algorithm', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $header . '.' . $payload . '.')));

        self::assertCount(0, $this->http->requests);
    }

    public function testAnHmacTokenSignedWithThePublicKeyMaterialIsRejected(): void
    {
        // The classic forgery: an HS256 token whose "secret" is the issuer's
        // public key material (the modulus), which anyone can read. It is
        // refused on the algorithm alone, before any signature is looked at.
        [, $jwk] = self::keyPair('a');

        $token = JWT::encode($this->claims(), $jwk['n'], 'HS256', self::KID_A);

        $this->assertRejected('oauth2.bad_algorithm', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $token)));

        self::assertCount(0, $this->http->requests);
    }

    public function testAWrongIssuerIsRejected(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $this->assertRejected('oauth2.issuer', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: [
            'iss' => 'https://evil.example.com/v2.0',
        ]))));
    }

    public function testAWrongAudienceIsRejected(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $this->assertRejected('oauth2.audience', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: [
            'aud' => 'some-other-app',
        ]))));
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $this->assertRejected('oauth2.expired', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: [
            'exp' => $this->clock->now()->getTimestamp() - 120,
        ]))));
    }

    public function testATokenExpiredWithinTheLeewayIsAccepted(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $step = $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: [
            'exp' => $this->clock->now()->getTimestamp() - 30,
        ])));

        self::assertInstanceOf(Authenticated::class, $step);
    }

    public function testATokenThatIsNotYetValidIsRejected(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $this->assertRejected('oauth2.not_yet_valid', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: [
            'nbf' => $this->clock->now()->getTimestamp() + 120,
        ]))));
    }

    public function testATokenNotYetValidWithinTheLeewayIsAccepted(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $step = $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: [
            'nbf' => $this->clock->now()->getTimestamp() + 30,
        ])));

        self::assertInstanceOf(Authenticated::class, $step);
    }

    public function testATokenWithoutAnExpiryIsRejected(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $claims = $this->claims();
        unset($claims['exp']);

        $token = JWT::encode($claims, $privateKey, 'RS256', self::KID_A);

        $this->assertRejected('oauth2.missing_claim', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $token)));
    }

    public function testAMissingScopeIsRejected(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $this->assertRejected('"profile" scope (oauth2.scope)', fn(): mixed => $this->driver(['required_scopes' => ['openid', 'profile']])
            ->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: ['scp' => 'openid email']))));
    }

    public function testAllRequiredScopesAreAccepted(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $step = $this->driver(['required_scopes' => ['openid', 'profile']])
            ->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: ['scp' => 'openid profile email'])));

        self::assertInstanceOf(Authenticated::class, $step);
    }

    public function testScopesMayArriveAsAList(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $step = $this->driver(['required_scopes' => ['openid']])
            ->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: ['scp' => ['openid', 'email']])));

        self::assertInstanceOf(Authenticated::class, $step);
    }

    public function testAMissingRoleIsRejected(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $this->assertRejected('"admin" role (oauth2.role)', fn(): mixed => $this->driver(['required_roles' => ['admin']])
            ->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: ['roles' => ['user']]))));
    }

    public function testTheRequiredRolesAreAccepted(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $step = $this->driver(['required_roles' => ['admin']])
            ->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: ['roles' => ['user', 'admin']])));

        self::assertInstanceOf(Authenticated::class, $step);
    }

    public function testARolesClaimThatIsNotAListIsRejected(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $this->assertRejected('oauth2.role', fn(): mixed => $this->driver(['required_roles' => ['admin']])
            ->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: ['roles' => 'admin']))));
    }

    public function testTheUsernameComesFromTheAccessidClaimWhenConfigured(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $step = $this->driver(['identity_claim' => 'accessid'])
            ->login($this->requestWith('Bearer ' . $this->token($privateKey, overrides: ['accessid' => 'jdoe'])));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertSame('jdoe', $step->identity->username);
    }

    public function testAMissingIdentityClaimIsRejected(): void
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $claims = $this->claims();
        unset($claims['preferred_username']);

        $token = JWT::encode($claims, $privateKey, 'RS256', self::KID_A);

        $this->assertRejected('preferred_username" claim (oauth2.missing_claim)', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $token)));
    }

    public function testASignatureMismatchIsRejected(): void
    {
        // The published key says kid A, but the token was signed with a
        // different key under the same kid: the signature cannot verify.
        [$privateKeyB] = self::keyPair('b');
        [, $jwkA] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwkA, self::KID_A)]));

        $this->assertRejected('oauth2.signature', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKeyB, self::KID_A))));
    }

    public function testTheKeySetIsFetchedOnceAndThenCached(): void
    {
        $this->validLogin();
        $this->validLogin();

        self::assertCount(1, $this->http->requests);
    }

    public function testTheKeySetIsRefetchedAfterItsTtlExpires(): void
    {
        $this->validLogin();

        $this->clock->advance(3601);

        $this->validLogin();

        self::assertCount(2, $this->http->requests);
    }

    public function testAnUnknownKeyIdTriggersOneRefetchAndThenSucceeds(): void
    {
        // The cached set knows only key A; the token carries key B, so the
        // provider refetches once and finds the rotated set.
        [, $jwkA] = self::keyPair('a');
        [$privateKeyB, $jwkB] = self::keyPair('b');

        $this->http->queue($this->jwksResponse([self::withKid($jwkA, self::KID_A)]));
        $this->http->queue($this->jwksResponse([self::withKid($jwkB, self::KID_B)]));

        $step = $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKeyB, self::KID_B)));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertCount(2, $this->http->requests);
    }

    public function testAnUnknownKeyIdRefetchesAtMostOncePerMinute(): void
    {
        // Both answers hold only key A: the refetch finds nothing new.
        [, $jwkA] = self::keyPair('a');
        [$privateKeyB] = self::keyPair('b');

        $this->http->queue($this->jwksResponse([self::withKid($jwkA, self::KID_A)]));
        $this->http->queue($this->jwksResponse([self::withKid($jwkA, self::KID_A)]));

        $token = $this->token($privateKeyB, self::KID_B);

        $this->assertRejected('oauth2.unknown_key', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $token)));

        // Within the cooldown window, a second bad token costs no request.
        $this->assertRejected('oauth2.unknown_key', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $token)));

        self::assertCount(2, $this->http->requests);

        // After the window, the provider may refetch again.
        $this->clock->advance(61);
        $this->http->queue($this->jwksResponse([self::withKid($jwkA, self::KID_A)]));

        $this->assertRejected('oauth2.unknown_key', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $token)));

        self::assertCount(3, $this->http->requests);
    }

    public function testAStaleCachedSetIsRefreshedWhenTheIssuerRotates(): void
    {
        // The first login caches the set that holds only key A. The issuer
        // then rotates to key B, and the next token must still be accepted.
        $this->validLogin();

        [$privateKeyB, $jwkB] = self::keyPair('b');
        $this->http->queue($this->jwksResponse([self::withKid($jwkB, self::KID_B)]));

        $step = $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKeyB, self::KID_B)));

        self::assertInstanceOf(Authenticated::class, $step);
        self::assertCount(2, $this->http->requests);
    }

    public function testAnUnreachableIssuerFailsClosed(): void
    {
        // No queued response: the fake client throws, and the driver must
        // answer with an authentication error, not a transport error.
        [$privateKey] = self::keyPair('a');

        $this->assertRejected('oauth2.jwks_unavailable', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey))));
    }

    public function testANon2xxIssuerAnswerFailsClosed(): void
    {
        [$privateKey] = self::keyPair('a');
        $this->http->queue(new Response(500, [], 'nope'));

        $this->assertRejected('oauth2.jwks_unavailable', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey))));
    }

    public function testAnEmptyKeySetFailsClosed(): void
    {
        [$privateKey] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([]));

        $this->assertRejected('oauth2.jwks_unavailable', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey))));
    }

    public function testAMalformedKeySetFailsClosed(): void
    {
        [$privateKey] = self::keyPair('a');
        $this->http->queue(new Response(200, ['Content-Type' => 'application/json'], 'not a key set'));

        $this->assertRejected('oauth2.jwks_unavailable', fn(): mixed => $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey))));
    }

    /**
     * Run the attempt and assert it failed with an authentication error
     * carrying the given reason code.
     */
    private function assertRejected(string $reason, Closure $attempt): void
    {
        try {
            $attempt();
        } catch (AuthenticationFailed $e) {
            self::assertStringContainsString($reason, $e->getMessage());

            return;
        }

        self::fail(sprintf('Expected AuthenticationFailed with "%s".', $reason));
    }

    /**
     * A signed-in step from a fresh, valid token under key A.
     */
    private function validLogin(): Authenticated
    {
        [$privateKey, $jwk] = self::keyPair('a');
        $this->http->queue($this->jwksResponse([self::withKid($jwk, self::KID_A)]));

        $step = $this->driver()->login($this->requestWith('Bearer ' . $this->token($privateKey)));

        self::assertInstanceOf(Authenticated::class, $step);

        return $step;
    }

    /**
     * The driver under test, with any config overrides merged in.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function driver(array $overrides = []): OAuth2Driver
    {
        $config = array_merge([
            'issuer' => self::ISSUER,
            'audience' => self::AUDIENCE,
            'leeway_seconds' => 60,
            'required_scopes' => [],
            'required_roles' => [],
            'identity_claim' => 'preferred_username',
            'jwks_cache_ttl_seconds' => 3600,
        ], $overrides);

        return new OAuth2Driver(
            $config['issuer'],
            self::JWKS_URL,
            $config['audience'],
            $config['leeway_seconds'],
            $config['required_scopes'],
            $config['required_roles'],
            $config['identity_claim'],
            $this->http,
            new Psr17Factory(),
            $this->cache,
            $config['jwks_cache_ttl_seconds'],
            $this->clock,
        );
    }

    /**
     * A PSR-7 request carrying the given Authorization header, when there is
     * one.
     */
    private function requestWith(?string $authorization): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/');

        return $authorization === null
            ? $request
            : $request->withHeader('Authorization', $authorization);
    }

    /**
     * A token signed with the given key under the given key id, with the
     * standard claims plus any overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function token(string $privateKey, string $kid = self::KID_A, array $overrides = []): string
    {
        // The overrides win over the standard claims.
        return JWT::encode($overrides + $this->claims(), $privateKey, 'RS256', $kid);
    }

    /**
     * The standard claims a valid test token carries.
     *
     * @return array<string, mixed>
     */
    private function claims(): array
    {
        $now = $this->clock->now()->getTimestamp();

        return [
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'exp' => $now + 3600,
            'iat' => $now,
            'nbf' => $now,
            'preferred_username' => 'jdoe',
        ];
    }

    /**
     * A JWKS document holding the given keys, as a 200 response.
     *
     * @param  list<array<string, string>>  $keys
     */
    private function jwksResponse(array $keys): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['keys' => $keys]));
    }

    /**
     * The two locally generated RSA key pairs, memoized by name.
     *
     * @return array{0: string, 1: array<string, string>} The private key (PEM) and the public JWK (no kid).
     */
    private static function keyPair(string $name): array
    {
        if (! isset(self::$keyPairs[$name])) {
            $resource = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);

            if ($resource === false) {
                self::fail('Could not generate an RSA key for the test.');
            }

            $details = openssl_pkey_get_details($resource);

            if ($details === false) {
                self::fail('Could not read the generated RSA key.');
            }

            $privatePem = '';

            if (! openssl_pkey_export($resource, $privatePem)) {
                self::fail('Could not export the generated RSA key.');
            }

            self::$keyPairs[$name] = [
                'private' => $privatePem,
                'jwk' => [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'n' => self::base64UrlEncode((string) $details['rsa']['n']),
                    'e' => self::base64UrlEncode((string) $details['rsa']['e']),
                ],
            ];
        }

        return [self::$keyPairs[$name]['private'], self::$keyPairs[$name]['jwk']];
    }

    /**
     * Add a key id to a JWK.
     *
     * @param  array<string, string>  $jwk
     * @return array<string, string>
     */
    private static function withKid(array $jwk, string $kid): array
    {
        return $jwk + ['kid' => $kid];
    }

    /**
     * Encode a value in base64url, the way JWT segments are encoded.
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
