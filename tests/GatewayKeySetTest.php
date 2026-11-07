<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Tests;

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
use Lcobucci\JWT\Token\Builder;
use Nyholm\Psr7\ServerRequest;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Drivers\GatewayKeySet;
use Waynestate\Sesame\Drivers\InMemoryReplayGuard;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Exception\InvalidConfig;
use Waynestate\Sesame\Tests\Support\FakeClock;

/**
 * The gateway key set: a JWKS file of the gateway's public keys by kid.
 *
 * Drawbridge publishes its current and next public key in one JWKS file;
 * an app points gateway.jwks_file at it. These tests build real key sets the
 * way Drawbridge's keys:jwks does (public members only, with kid, alg and
 * use), then check both the file rules and a full sign-in through the
 * driver with keys that came from the file.
 */
final class GatewayKeySetTest extends TestCase
{
    private const ISSUER = 'https://drawbridge.wayne.edu';

    private const AUDIENCE = 'sesame';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testReadsAnEs256KeySetByKid(): void
    {
        [, $current] = $this->ecKey();
        [, $next] = $this->ecKey();

        $keys = GatewayKeySet::fromJson($this->jwks([
            $this->ecJwk($current, 'drawbridge-2026-11'),
            $this->ecJwk($next, 'drawbridge-2027-11'),
        ]), 'ES256');

        self::assertSame(['drawbridge-2026-11', 'drawbridge-2027-11'], array_keys($keys));
        self::assertSame($this->pem($current), $keys['drawbridge-2026-11']);
        self::assertSame($this->pem($next), $keys['drawbridge-2027-11']);
    }

    public function testReadsAKeySetWithoutAlgOrUse(): void
    {
        [, $public] = $this->ecKey();
        $jwk = $this->ecJwk($public, 'k1');
        unset($jwk['alg'], $jwk['use']);

        $keys = GatewayKeySet::fromJson($this->jwks([$jwk]), 'ES256');

        self::assertSame(['k1'], array_keys($keys));
    }

    public function testReadsAnRs256KeySet(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $resource);
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);

        $keys = GatewayKeySet::fromJson($this->jwks([[
            'kty' => 'RSA',
            'kid' => 'rsa-1',
            'n' => $this->b64url($details['rsa']['n']),
            'e' => $this->b64url($details['rsa']['e']),
        ]]), 'RS256');

        self::assertSame(trim($details['key']), trim($keys['rsa-1']));
    }

    public function testReadsAnEddsaKeySet(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $raw = sodium_crypto_sign_publickey($pair);

        $keys = GatewayKeySet::fromJson($this->jwks([[
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'kid' => 'ed-1',
            'x' => $this->b64url($raw),
        ]]), 'EdDSA');

        self::assertSame(base64_encode($raw), $keys['ed-1']);
    }

    public function testReadsAKeySetFile(): void
    {
        [, $public] = $this->ecKey();
        $file = $this->file($this->jwks([$this->ecJwk($public, 'k1')]));

        self::assertSame(['k1' => $this->pem($public)], GatewayKeySet::fromFile($file, 'ES256'));
    }

    public function testRefusesAMissingFile(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('The gateway key set file named by "gateway.jwks_file" does not exist or cannot be read.');

        GatewayKeySet::fromFile(sys_get_temp_dir() . '/sesame-no-such-jwks-' . bin2hex(random_bytes(4)) . '.json', 'ES256');
    }

    public function testRefusesAnOversizedFile(): void
    {
        $file = $this->file(str_repeat(' ', 65537));

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('larger than 64 KB');

        GatewayKeySet::fromFile($file, 'ES256');
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function badDocuments(): iterable
    {
        yield 'not JSON' => ['{"keys": [', 'is not valid JSON'];
        yield 'a JSON list' => ['[]', 'must be a JWKS document'];
        yield 'no keys member' => ['{}', 'must be a JWKS document'];
        yield 'empty keys' => ['{"keys": []}', 'must be a JWKS document'];
        yield 'keys is an object' => ['{"keys": {"a": {}}}', 'must be a JWKS document'];
        yield 'a key that is not an object' => ['{"keys": ["key"]}', 'must be a JSON object'];
        yield 'a key without kid' => ['{"keys": [{"kty": "EC"}]}', 'must have a non-empty "kid"'];
        yield 'a key with an empty kid' => ['{"keys": [{"kty": "EC", "kid": ""}]}', 'must have a non-empty "kid"'];
        yield 'a key with a numeric kid' => ['{"keys": [{"kty": "EC", "kid": 7}]}', 'must have a non-empty "kid"'];
    }

    #[DataProvider('badDocuments')]
    public function testRefusesABadDocument(string $json, string $message): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage($message);

        GatewayKeySet::fromJson($json, 'ES256');
    }

    public function testRefusesALongKid(): void
    {
        [, $public] = $this->ecKey();

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('must have a non-empty "kid" of at most 128 characters');

        GatewayKeySet::fromJson($this->jwks([$this->ecJwk($public, str_repeat('k', 129))]), 'ES256');
    }

    public function testRefusesADuplicateKid(): void
    {
        [, $a] = $this->ecKey();
        [, $b] = $this->ecKey();

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('lists the kid "same" more than once');

        GatewayKeySet::fromJson($this->jwks([$this->ecJwk($a, 'same'), $this->ecJwk($b, 'same')]), 'ES256');
    }

    public function testRefusesMoreThanEightKeys(): void
    {
        [, $public] = $this->ecKey();
        $keys = [];

        for ($i = 1; $i <= 9; $i++) {
            $keys[] = $this->ecJwk($public, 'k' . $i);
        }

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('holds more than 8 keys');

        GatewayKeySet::fromJson($this->jwks($keys), 'ES256');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function privateMembers(): iterable
    {
        foreach (['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'] as $member) {
            yield $member => [$member];
        }
    }

    #[DataProvider('privateMembers')]
    public function testRefusesPrivateKeyMaterial(string $member): void
    {
        [, $public] = $this->ecKey();
        $jwk = $this->ecJwk($public, 'leaky');
        $jwk[$member] = 'c2VjcmV0';

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('The key "leaky" in the gateway key set ("gateway.jwks_file") holds private key material.');

        GatewayKeySet::fromJson($this->jwks([$jwk]), 'ES256');
    }

    public function testRefusesAKeyForAnotherAlgorithm(): void
    {
        [, $public] = $this->ecKey();
        $jwk = $this->ecJwk($public, 'k1');
        $jwk['alg'] = 'ES384';

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('is not for ES256, the pinned "gateway.algorithm"');

        GatewayKeySet::fromJson($this->jwks([$jwk]), 'ES256');
    }

    public function testRefusesAnEncryptionKey(): void
    {
        [, $public] = $this->ecKey();
        $jwk = $this->ecJwk($public, 'k1');
        $jwk['use'] = 'enc';

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('must be a signing key ("use": "sig")');

        GatewayKeySet::fromJson($this->jwks([$jwk]), 'ES256');
    }

    public function testRefusesTheWrongKeyTypeForTheAlgorithm(): void
    {
        [, $public] = $this->ecKey();

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('The key "k1" in the gateway key set ("gateway.jwks_file") is not an RSA key, which "gateway.algorithm" RS256 needs.');

        $jwk = $this->ecJwk($public, 'k1');
        unset($jwk['alg']);

        GatewayKeySet::fromJson($this->jwks([$jwk]), 'RS256');
    }

    public function testRefusesTheWrongCurve(): void
    {
        [, $public] = $this->ecKey();
        $jwk = $this->ecJwk($public, 'k1');
        $jwk['crv'] = 'P-384';

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('is not an EC P-256 key');

        GatewayKeySet::fromJson($this->jwks([$jwk]), 'ES256');
    }

    public function testRefusesAPointThatIsNotOnTheCurve(): void
    {
        [, $public] = $this->ecKey();
        $jwk = $this->ecJwk($public, 'k1');
        // A y that does not belong to x: firebase/php-jwt builds a PEM from
        // it without complaint, so the key set must load it to find out.
        $jwk['y'] = $this->b64url(str_repeat("\x01", 32));

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('could not be read as an EC P-256 key');

        GatewayKeySet::fromJson($this->jwks([$jwk]), 'ES256');
    }

    public function testRefusesAKeyWithAMalformedMember(): void
    {
        [, $public] = $this->ecKey();
        $jwk = $this->ecJwk($public, 'k1');
        $jwk['x'] = ['not', 'a', 'string'];

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('could not be read as an EC P-256 key');

        GatewayKeySet::fromJson($this->jwks([$jwk]), 'ES256');
    }

    public function testRefusesAShortRsaKey(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $resource);
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('shorter than 2048 bits');

        GatewayKeySet::fromJson($this->jwks([[
            'kty' => 'RSA',
            'kid' => 'short',
            'n' => $this->b64url($details['rsa']['n']),
            'e' => $this->b64url($details['rsa']['e']),
        ]]), 'RS256');
    }

    public function testRefusesAShortEddsaKey(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('could not be read as an OKP Ed25519 key');

        GatewayKeySet::fromJson($this->jwks([[
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'kid' => 'ed-1',
            'x' => $this->b64url('too short'),
        ]]), 'EdDSA');
    }

    public function testConfigReadsTheKeySetFileAtBoot(): void
    {
        [, $public] = $this->ecKey();
        $file = $this->file($this->jwks([$this->ecJwk($public, 'k1')]));

        $config = Config::fromArray($this->gatewayConfig(['jwks_file' => $file]));

        self::assertSame($file, $config->gatewayJwksFile);
        self::assertSame(['k1' => $this->pem($public)], $config->gatewayPublicKeys);
        self::assertNull($config->gatewayPublicKey);
    }

    public function testConfigStopsAtBootOnABadKeySetFile(): void
    {
        $file = $this->file('{"keys": []}');

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('must be a JWKS document');

        Config::fromArray($this->gatewayConfig(['jwks_file' => $file]));
    }

    public function testConfigTreatsAnEmptyKeySetPathAsUnset(): void
    {
        $config = Config::fromArray($this->gatewayConfig(['jwks_file' => '', 'public_key' => 'public-key']));

        self::assertNull($config->gatewayJwksFile);
        self::assertNull($config->gatewayPublicKeys);
        self::assertSame('public-key', $config->gatewayPublicKey);
    }

    public function testConfigRefusesANonStringKeySetPath(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('The config key "gateway.jwks_file" must be a path to a JWKS file.');

        Config::fromArray($this->gatewayConfig(['jwks_file' => ['a.json']]));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function competingKeySettings(): iterable
    {
        yield 'with public_key' => [['public_key' => 'public-key']];
        yield 'with public_keys' => [['public_keys' => ['a' => 'key-a']]];
    }

    /**
     * @param  array<string, mixed>  $other
     */
    #[DataProvider('competingKeySettings')]
    public function testConfigRefusesAKeySetFileAlongsideAnotherKeySetting(array $other): void
    {
        [, $public] = $this->ecKey();
        $file = $this->file($this->jwks([$this->ecJwk($public, 'k1')]));

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('Set only one of "gateway.public_key", "gateway.public_keys" and "gateway.jwks_file"');

        Config::fromArray($this->gatewayConfig(['jwks_file' => $file] + $other));
    }

    /**
     * The whole rotation, through the factory: a key set file of the current
     * and the next key, an assertion signed with either, and a refusal for a
     * key the set never listed.
     */
    public function testARotationSignsInWithTheCurrentAndTheNextKeyFromTheFile(): void
    {
        [$currentPrivate, $currentPublic] = $this->ecKey();
        [$nextPrivate, $nextPublic] = $this->ecKey();
        [$strangerPrivate] = $this->ecKey();
        $file = $this->file($this->jwks([
            $this->ecJwk($currentPublic, 'drawbridge-2026-11'),
            $this->ecJwk($nextPublic, 'drawbridge-2027-11'),
        ]));
        $clock = new FakeClock();
        $driver = DriverFactory::make(
            Config::fromArray($this->gatewayConfig(['jwks_file' => $file])),
            null,
            null,
            $clock,
            new InMemoryReplayGuard(30, $clock),
        );

        $before = $driver->login($this->request($this->token(new Sha256(), $currentPrivate, $clock, 'drawbridge-2026-11')));
        $after = $driver->login($this->request($this->token(new Sha256(), $nextPrivate, $clock, 'drawbridge-2027-11')));

        self::assertInstanceOf(Authenticated::class, $before);
        self::assertInstanceOf(Authenticated::class, $after);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('gateway.signature');

        $driver->login($this->request($this->token(new Sha256(), $strangerPrivate, $clock, 'drawbridge-2026-11')));
    }

    public function testAnEddsaKeySetSignsIn(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $file = $this->file($this->jwks([[
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'kid' => 'ed-1',
            'x' => $this->b64url(sodium_crypto_sign_publickey($pair)),
            'alg' => 'EdDSA',
            'use' => 'sig',
        ]]));
        $clock = new FakeClock();
        $driver = DriverFactory::make(
            Config::fromArray($this->gatewayConfig(['jwks_file' => $file, 'algorithm' => 'EdDSA'])),
            null,
            null,
            $clock,
            new InMemoryReplayGuard(30, $clock),
        );

        $step = $driver->login($this->request($this->token(new Eddsa(), sodium_crypto_sign_secretkey($pair), $clock, 'ed-1')));

        self::assertInstanceOf(Authenticated::class, $step);
    }

    public function testAnRs256KeySetSignsIn(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $resource);
        openssl_pkey_export($resource, $privatePem);
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        $file = $this->file($this->jwks([[
            'kty' => 'RSA',
            'kid' => 'rsa-1',
            'n' => $this->b64url($details['rsa']['n']),
            'e' => $this->b64url($details['rsa']['e']),
        ]]));
        $clock = new FakeClock();
        $driver = DriverFactory::make(
            Config::fromArray($this->gatewayConfig(['jwks_file' => $file, 'algorithm' => 'RS256'])),
            null,
            null,
            $clock,
            new InMemoryReplayGuard(30, $clock),
        );

        $step = $driver->login($this->request($this->token(new RsaSha256(), (string) $privatePem, $clock, 'rsa-1')));

        self::assertInstanceOf(Authenticated::class, $step);
    }

    /**
     * A fresh P-256 key pair: the private PEM (for signing) and the key.
     *
     * @return array{0: string, 1: OpenSSLAsymmetricKey}
     */
    private function ecKey(): array
    {
        $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $resource);
        openssl_pkey_export($resource, $privatePem);
        self::assertIsString($privatePem);

        return [$privatePem, $resource];
    }

    private function pem(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        return $details['key'];
    }

    /**
     * The public JWK of a P-256 key, as Drawbridge's keys:jwks writes it.
     *
     * @return array<string, string>
     */
    private function ecJwk(OpenSSLAsymmetricKey $key, string $kid): array
    {
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => $this->b64url(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
            'y' => $this->b64url(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
            'kid' => $kid,
            'alg' => 'ES256',
            'use' => 'sig',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $keys
     */
    private function jwks(array $keys): string
    {
        return json_encode(['keys' => $keys], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function file(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'sesame-jwks-');
        self::assertIsString($file);
        file_put_contents($file, $contents);
        $this->files[] = $file;

        return $file;
    }

    /**
     * @param  array<string, mixed>  $gateway
     * @return array<string, mixed>
     */
    private function gatewayConfig(array $gateway = []): array
    {
        return [
            'driver' => 'gateway',
            'environment' => 'testing',
            'gateway' => $gateway + [
                'issuer' => self::ISSUER,
                'audience' => self::AUDIENCE,
                'trusted_proxies' => ['10.0.0.0/8'],
            ],
        ];
    }

    /**
     * @param  non-empty-string  $kid
     */
    private function token(Signer $signer, string $privateKey, FakeClock $clock, string $kid): string
    {
        if ($privateKey === '') {
            self::fail('The test key is empty.');
        }

        $now = $clock->now();

        return Builder::new(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates())
            ->issuedBy(self::ISSUER)
            ->permittedFor(self::AUDIENCE)
            ->relatedTo('ab1234')
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify('+60 seconds'))
            ->withHeader('typ', 'drawbridge+jwt')
            ->withHeader('kid', $kid)
            ->getToken($signer, InMemory::plainText($privateKey))
            ->toString();
    }

    private function request(string $token): ServerRequest
    {
        return new ServerRequest('GET', 'https://app.test/login', ['X-Sesame-Assertion' => $token], null, '1.1', ['REMOTE_ADDR' => '10.1.2.3']);
    }
}
