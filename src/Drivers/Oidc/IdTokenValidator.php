<?php

declare(strict_types=1);

namespace Waynestate\Sesame\Drivers\Oidc;

use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use UnexpectedValueException;
use Waynestate\Sesame\Drivers\JwksProvider;
use Waynestate\Sesame\Exception\AuthenticationFailed;

/**
 * Validate an OpenID Connect ID token (OpenID Connect Core 1.0, 3.1.3.7).
 *
 * The signature is checked with the same firebase/php-jwt call and the same
 * JwksProvider the oauth2 driver uses, so SESAME has one JWT verifier, not
 * two. The header's algorithm must be on the allowlist (RSA only) before
 * the key is even looked up, so "none" and HMAC tokens are refused without
 * their signatures being read. Then the claims: iss exactly, aud includes
 * the client id (and azp is the client id when present or when there is
 * more than one audience), exp, nbf and iat with the leeway and the
 * driver's clock, sub present, and the nonce equal to the one this browser
 * sent.
 */
final readonly class IdTokenValidator
{
    /**
     * @param list<string> $algorithms
     */
    public function __construct(
        private JwksProvider $jwks,
        private ClockInterface $clock,
        private string $issuer,
        private string $clientId,
        private array $algorithms,
        private int $leewaySeconds,
    ) {}

    /**
     * The verified claims of the ID token.
     *
     * @return array<string, mixed>
     *
     * @throws AuthenticationFailed When any check fails.
     */
    public function validate(#[\SensitiveParameter] string $idToken, #[\SensitiveParameter] string $expectedNonce): array
    {
        [$alg, $kid] = $this->header($idToken);

        $key = $this->jwks->keyFor($kid, $alg);

        if ($key === null) {
            throw new AuthenticationFailed('No published signing key matches the ID token (oidc.unknown_key).');
        }

        $claims = (array) $this->decode($idToken, $key);

        $iss = $claims['iss'] ?? null;

        if (! is_string($iss) || $iss !== $this->issuer) {
            throw new AuthenticationFailed('The ID token issuer does not match (oidc.issuer).');
        }

        $this->assertAudience($claims['aud'] ?? null, $claims['azp'] ?? null);

        if (! is_int($claims['exp'] ?? null)) {
            throw new AuthenticationFailed('The ID token has no usable "exp" claim (oidc.missing_claim).');
        }

        $iat = $claims['iat'] ?? null;

        if (! is_int($iat)) {
            throw new AuthenticationFailed('The ID token has no usable "iat" claim (oidc.missing_claim).');
        }

        // Checked here as well as in the library, so the rule does not
        // depend on which time claims the library chooses to read.
        if ($iat > $this->clock->now()->getTimestamp() + $this->leewaySeconds) {
            throw new AuthenticationFailed('The ID token was issued in the future (oidc.not_yet_valid).');
        }

        $sub = $claims['sub'] ?? null;

        if (! is_string($sub) || $sub === '') {
            throw new AuthenticationFailed('The ID token has no usable "sub" claim (oidc.missing_claim).');
        }

        $nonce = $claims['nonce'] ?? null;

        if (! is_string($nonce) || $expectedNonce === '' || ! hash_equals($expectedNonce, $nonce)) {
            throw new AuthenticationFailed('The ID token nonce does not match this sign-in (oidc.nonce).');
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    /**
     * The token's algorithm and key id, after the allowlist check.
     *
     * @return array{string, non-empty-string}
     */
    private function header(#[\SensitiveParameter] string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new AuthenticationFailed('The ID token is not a valid JWT (oidc.invalid_jwt).');
        }

        $header = json_decode(self::base64UrlDecode($parts[0]), true);

        if (! is_array($header)) {
            throw new AuthenticationFailed('The ID token is not a valid JWT (oidc.invalid_jwt).');
        }

        $alg = $header['alg'] ?? null;

        if (! is_string($alg) || ! in_array($alg, $this->algorithms, true)) {
            // The attacker-chosen algorithm name is not echoed back.
            throw new AuthenticationFailed('The ID token does not use an allowed algorithm (oidc.bad_algorithm).');
        }

        if (array_key_exists('crit', $header)) {
            throw new AuthenticationFailed('The ID token carries critical header extensions SESAME does not understand (oidc.invalid_jwt).');
        }

        $kid = $header['kid'] ?? null;

        if (! is_string($kid) || $kid === '') {
            throw new AuthenticationFailed('The ID token has no usable "kid" header (oidc.missing_kid).');
        }

        return [$alg, $kid];
    }

    /**
     * The signature and time checks, with the library pinned to this
     * driver's leeway and clock for the one call, as in the oauth2 driver.
     */
    private function decode(#[\SensitiveParameter] string $token, Key $key): object
    {
        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = $this->leewaySeconds;
        JWT::$timestamp = $this->clock->now()->getTimestamp();

        try {
            return JWT::decode($token, $key);
        } catch (ExpiredException) {
            throw new AuthenticationFailed('The ID token has expired (oidc.expired).');
        } catch (BeforeValidException) {
            throw new AuthenticationFailed('The ID token is not valid yet (oidc.not_yet_valid).');
        } catch (SignatureInvalidException) {
            throw new AuthenticationFailed('The ID token signature is invalid (oidc.signature).');
        } catch (UnexpectedValueException|InvalidArgumentException|DomainException) {
            throw new AuthenticationFailed('The ID token is not a valid JWT (oidc.invalid_jwt).');
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }
    }

    /**
     * aud must include the client id. With more than one audience, azp is
     * required; whenever azp is present it must be the client id (Core
     * 3.1.3.7, steps 3 to 5).
     */
    private function assertAudience(mixed $aud, mixed $azp): void
    {
        $audiences = [];

        if (is_string($aud)) {
            $audiences = [$aud];
        } elseif (is_array($aud)) {
            foreach ($aud as $entry) {
                if (is_string($entry)) {
                    $audiences[] = $entry;
                }
            }
        }

        if (! in_array($this->clientId, $audiences, true)) {
            throw new AuthenticationFailed('The ID token audience does not match (oidc.audience).');
        }

        if ($azp !== null && $azp !== $this->clientId) {
            throw new AuthenticationFailed('The ID token authorized party does not match (oidc.azp).');
        }

        if (count($audiences) > 1 && $azp === null) {
            throw new AuthenticationFailed('The ID token has several audiences and no authorized party (oidc.azp).');
        }
    }

    private static function base64UrlDecode(string $input): string
    {
        $padded = str_pad($input, (int) ceil(strlen($input) / 4) * 4, '=');

        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
