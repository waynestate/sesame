<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers;

use DomainException;
use Firebase\JWT\JWK;
use InvalidArgumentException;
use JsonException;
use OpenSSLAsymmetricKey;
use UnexpectedValueException;
use Waynestate\Sesame\Exception\InvalidConfig;

/**
 * The gateway's public keys, read from a JWKS file (RFC 7517) into a map of
 * kid to key for the gateway driver.
 *
 * Drawbridge publishes one small key set holding its current and its next
 * public key (`bin/drawbridge keys:jwks`). An app ships that file or reads it
 * from a mounted path, and the driver picks the key by the assertion's kid,
 * the same as a `gateway.public_keys` map. The file is only a standard,
 * environment-friendly way to hand that map over: nothing is fetched over the
 * network.
 *
 * The JWK parsing itself is firebase/php-jwt's (JWK::parseKey), which SESAME
 * already uses for Entra's key sets. This class only adds the rules a gateway
 * key set must meet, and it checks them at boot so a bad file stops the app
 * with an InvalidConfig error instead of refusing the first sign-in:
 *
 * - a JSON object with a non-empty "keys" list of at most eight keys;
 * - every key has its own non-empty kid;
 * - public keys only: a key carrying any private member is refused, because a
 *   leaked private key in an app repository is the one mistake this file
 *   must never let through;
 * - the key type the pinned algorithm needs (ES256: EC P-256, RS256: RSA of
 *   2048 bits or more, EdDSA: OKP Ed25519), and a matching "alg" and "use"
 *   when the key names them.
 *
 * Open Sesame works for exactly the words on the list, and this is the list.
 */
final class GatewayKeySet
{
    /**
     * The largest key set file accepted, in bytes. A real one is under 1 KB.
     */
    private const MAX_BYTES = 65536;

    /**
     * The most keys a gateway key set may hold. Current plus next is two; a
     * longer list is a sign that retired keys are never being removed.
     */
    private const MAX_KEYS = 8;

    /**
     * The longest kid accepted, in bytes.
     */
    private const MAX_KID_LENGTH = 128;

    /**
     * JWK members that only a private (or symmetric) key carries.
     *
     * @var list<string>
     */
    private const PRIVATE_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];

    /**
     * Read a JWKS file into a map of kid to key.
     *
     * @return non-empty-array<non-empty-string, non-empty-string>
     *
     * @throws InvalidConfig When the file is missing, too large or not a usable gateway key set.
     */
    public static function fromFile(string $path, string $algorithm): array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidConfig(
                'The gateway key set file named by "gateway.jwks_file" does not exist or cannot be read.',
            );
        }

        $size = filesize($path);

        if ($size === false || $size > self::MAX_BYTES) {
            throw new InvalidConfig(
                'The gateway key set file named by "gateway.jwks_file" is larger than 64 KB; a gateway key set is a few keys.',
            );
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw new InvalidConfig(
                'The gateway key set file named by "gateway.jwks_file" could not be read.',
            );
        }

        return self::fromJson($json, $algorithm);
    }

    /**
     * Read a JWKS document into a map of kid to key.
     *
     * Each key comes back in the form the driver already takes for
     * `gateway.public_keys`: a PEM for ES256 and RS256, a base64-encoded raw
     * key for EdDSA.
     *
     * @return non-empty-array<non-empty-string, non-empty-string>
     *
     * @throws InvalidConfig When the document is not a usable gateway key set.
     */
    public static function fromJson(string $json, string $algorithm): array
    {
        try {
            $set = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidConfig('The gateway key set ("gateway.jwks_file") is not valid JSON.');
        }

        if (! is_array($set) || ! isset($set['keys']) || ! is_array($set['keys']) || $set['keys'] === [] || ! array_is_list($set['keys'])) {
            throw new InvalidConfig(
                'The gateway key set ("gateway.jwks_file") must be a JWKS document: a JSON object with a non-empty "keys" list.',
            );
        }

        if (count($set['keys']) > self::MAX_KEYS) {
            throw new InvalidConfig(sprintf(
                'The gateway key set ("gateway.jwks_file") holds more than %d keys; remove the retired ones.',
                self::MAX_KEYS,
            ));
        }

        $keys = [];

        foreach ($set['keys'] as $jwk) {
            if (! is_array($jwk)) {
                throw new InvalidConfig('Each entry in the gateway key set ("gateway.jwks_file") must be a JSON object.');
            }

            $kid = $jwk['kid'] ?? null;

            if (! is_string($kid) || $kid === '' || strlen($kid) > self::MAX_KID_LENGTH) {
                throw new InvalidConfig(sprintf(
                    'Each key in the gateway key set ("gateway.jwks_file") must have a non-empty "kid" of at most %d characters.',
                    self::MAX_KID_LENGTH,
                ));
            }

            if (array_key_exists($kid, $keys)) {
                throw new InvalidConfig(sprintf(
                    'The gateway key set ("gateway.jwks_file") lists the kid "%s" more than once.',
                    $kid,
                ));
            }

            foreach (self::PRIVATE_MEMBERS as $member) {
                if (array_key_exists($member, $jwk)) {
                    throw new InvalidConfig(sprintf(
                        'The key "%s" in the gateway key set ("gateway.jwks_file") holds private key material. Publish only the public half, and treat that private key as exposed.',
                        $kid,
                    ));
                }
            }

            if (array_key_exists('alg', $jwk) && $jwk['alg'] !== $algorithm) {
                throw new InvalidConfig(sprintf(
                    'The key "%s" in the gateway key set ("gateway.jwks_file") is not for %s, the pinned "gateway.algorithm".',
                    $kid,
                    $algorithm,
                ));
            }

            if (array_key_exists('use', $jwk) && $jwk['use'] !== 'sig') {
                throw new InvalidConfig(sprintf(
                    'The key "%s" in the gateway key set ("gateway.jwks_file") must be a signing key ("use": "sig").',
                    $kid,
                ));
            }

            $keys[$kid] = self::material($jwk, $kid, $algorithm);
        }

        return $keys;
    }

    /**
     * One JWK as the key form the driver takes, after checking it is the
     * type the pinned algorithm needs.
     *
     * @param array<mixed> $jwk
     * @return non-empty-string
     */
    private static function material(array $jwk, string $kid, string $algorithm): string
    {
        [$kty, $crv, $described] = match ($algorithm) {
            'ES256' => ['EC', 'P-256', 'an EC P-256 key'],
            'RS256' => ['RSA', null, 'an RSA key'],
            'EdDSA' => ['OKP', 'Ed25519', 'an OKP Ed25519 key'],
            default => throw new InvalidConfig('The config key "gateway.algorithm" must be "ES256", "RS256" or "EdDSA".'),
        };

        if (($jwk['kty'] ?? null) !== $kty || ($crv !== null && ($jwk['crv'] ?? null) !== $crv)) {
            throw new InvalidConfig(sprintf(
                'The key "%s" in the gateway key set ("gateway.jwks_file") is not %s, which "gateway.algorithm" %s needs.',
                $kid,
                $described,
                $algorithm,
            ));
        }

        // The members the key is built from must be non-empty strings before
        // the library sees them, so a malformed file fails here, by name.
        $members = match ($kty) {
            'RSA' => ['n', 'e'],
            'EC' => ['x', 'y'],
            default => ['x'],
        };

        foreach ($members as $member) {
            if (! isset($jwk[$member]) || ! is_string($jwk[$member]) || $jwk[$member] === '') {
                throw self::unreadable($kid, $described);
            }
        }

        try {
            $key = JWK::parseKey($jwk, $algorithm);
        } catch (InvalidArgumentException|UnexpectedValueException|DomainException) {
            throw self::unreadable($kid, $described);
        }

        if ($key === null) {
            throw self::unreadable($kid, $described);
        }

        $material = $key->getKeyMaterial();

        if ($algorithm === 'EdDSA') {
            // firebase/php-jwt hands an Ed25519 key back as standard base64,
            // the form the driver reads for EdDSA. A raw Ed25519 public key
            // is exactly 32 bytes.
            if (! is_string($material) || $material === '' || strlen((string) base64_decode($material, true)) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw self::unreadable($kid, $described);
            }

            return $material;
        }

        // ES256 comes back as a PEM string and RS256 as an OpenSSL key. Load
        // both through OpenSSL: the library builds the EC PEM from x and y
        // without checking the point, and a bad point must stop the app here,
        // not throw from the signature check later.
        $public = $material instanceof OpenSSLAsymmetricKey ? $material : (is_string($material) ? openssl_pkey_get_public($material) : false);

        if ($public === false) {
            throw self::unreadable($kid, $described);
        }

        $details = openssl_pkey_get_details($public);

        if (! is_array($details) || ! isset($details['key']) || ! is_string($details['key']) || $details['key'] === '') {
            throw self::unreadable($kid, $described);
        }

        if ($algorithm === 'ES256' && ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw self::unreadable($kid, $described);
        }

        if ($algorithm === 'RS256' && (! isset($details['bits']) || $details['bits'] < 2048)) {
            throw new InvalidConfig(sprintf(
                'The key "%s" in the gateway key set ("gateway.jwks_file") is an RSA key shorter than 2048 bits.',
                $kid,
            ));
        }

        return $details['key'];
    }

    private static function unreadable(string $kid, string $described): InvalidConfig
    {
        return new InvalidConfig(sprintf(
            'The key "%s" in the gateway key set ("gateway.jwks_file") could not be read as %s.',
            $kid,
            $described,
        ));
    }
}
